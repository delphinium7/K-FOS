<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genuine universal administrator membership only. This service is NOT an
 * initial-admin bootstrap, a development account manager or an HTTP route.
 * All future account-status/universal-membership writers must share the
 * same transaction advisory lock and last-usable-admin invariant.
 */
final class KfosUniversalAdministratorService
{
    public function __construct(
        private KfosAuthorization $authorization,
        private KfosRememberedDeviceService $remembered
    ) {}

    private function lock(): void
    {
        // Same PostgreSQL transaction lock as the Step 5Z/5AA role writers.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
    }

    private function requireAuthenticatedAdministrator(User $actor): void
    {
        $currentId = Auth::guard('web')->id();
        if ($currentId === null || (int) $currentId !== (int) $actor->getKey()) {
            throw new AuthorizationException('Authenticated System Administrator required.');
        }
        $fresh = DB::table('users')->where('id', $actor->getKey())
            ->lockForUpdate()->first();
        $version = session()->get('kfos_session_version');
        if ($fresh === null || $version === null
            || (int) $version !== (int) $fresh->session_version) {
            throw new AuthorizationException('Valid current authenticated session required.');
        }
        // Never trust the old is_system_admin flag or a development identity.
        if (! $this->authorization->isSystemAdministrator($actor)) {
            throw new AuthorizationException('Genuine System Administrator required.');
        }
    }

    /** Whether an account currently has a usable new-login path. */
    private function usableLogin(int $userId): bool
    {
        return DB::table('users as u')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->where('u.id', $userId)
            ->where('u.is_active', true)
            ->where('u.is_development_only', false)
            ->where('e.is_active', true)
            ->whereNotNull('u.password')
            ->where('u.password', '<>', '')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('kfos_login_security_states as s')
                    ->whereColumn('s.user_id', 'u.id')
                    ->whereNotNull('s.restricted_at');
            })
            ->exists();
    }

    private function otherUsableAdministratorCount(int $excludedId): int
    {
        return (int) DB::table('kfos_system_administrators as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->where('a.user_id', '<>', $excludedId)
            ->where('u.is_active', true)
            ->where('u.is_development_only', false)
            ->where('e.is_active', true)
            ->whereNotNull('u.password')
            ->where('u.password', '<>', '')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('kfos_login_security_states as s')
                    ->whereColumn('s.user_id', 'a.user_id')
                    ->whereNotNull('s.restricted_at');
            })
            ->count();
    }

    private function normalizedReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Reason is too long.']);
        }
        return $reason === '' ? null : $reason;
    }

    public function grant(User $actor, int $targetId, ?string $reason = null): bool
    {
        return $this->setMembership($actor, $targetId, true, $reason);
    }

    public function revoke(User $actor, int $targetId, ?string $reason = null): bool
    {
        return $this->setMembership($actor, $targetId, false, $reason);
    }

    private function setMembership(User $actor, int $targetId, bool $grant, ?string $reason): bool
    {
        return DB::transaction(function () use ($actor, $targetId, $grant, $reason): bool {
            $this->lock();
            $this->requireAuthenticatedAdministrator($actor);
            $reason = $this->normalizedReason($reason);

            $universal = DB::table('kfos_roles')->where('code', 'system_administrator')
                ->lockForUpdate()->first();
            if ($universal === null || $universal->kind !== 'universal' || ! $universal->is_active) {
                throw new AuthorizationException('Canonical System Administrator role unavailable.');
            }

            $target = DB::table('users')->where('id', $targetId)->lockForUpdate()->first();
            if ($target === null || $target->is_development_only || $target->employee_id === null
                || ! DB::table('employees')->where('id', $target->employee_id)->exists()) {
                throw new AuthorizationException('Genuine employee account required.');
            }

            $already = DB::table('kfos_system_administrators')->where('user_id', $targetId)->exists();
            if ($already === $grant) {
                return false; // No change, no successful audit, no session revocation.
            }

            if ($grant) {
                // Universal+operational role coexistence remains undecided.
                if (DB::table('kfos_operational_role_assignments')->where('user_id', $targetId)->exists()) {
                    throw new AuthorizationException('Remove the operational role first through authorized workflow.');
                }
                if (! $this->usableLogin($targetId)) {
                    throw new AuthorizationException('A usable, active employee login is required.');
                }
                DB::table('kfos_system_administrators')->insert([
                    'user_id' => $targetId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                // AUTH-032: never permit administrator self-downgrade.
                if ((int) $actor->getKey() === $targetId) {
                    throw new AuthorizationException('An administrator cannot revoke their own universal role.');
                }
                // AUTH-031/085: count eligible usable administrators at commit.
                // The shared advisory lock serializes genuine role writers.
                if ($this->otherUsableAdministratorCount($targetId) < 1) {
                    throw new AuthorizationException('At least one other usable System Administrator must remain.');
                }
                DB::table('kfos_system_administrators')->where('user_id', $targetId)->delete();
            }

            // Audit and session invalidation commit atomically with membership.
            DB::table('security_audit_logs')->insert([
                'actor_user_id' => $actor->getKey(),
                'target_user_id' => $targetId,
                'event_type' => $grant ? 'kfos_universal_admin_granted' : 'kfos_universal_admin_revoked',
                'result' => 'success',
                'details' => json_encode([
                    'role_code' => 'system_administrator',
                    'role_id' => (int) $universal->id,
                    'actor_capacity' => 'system_administrator',
                    'before' => ['universal_member' => ! $grant],
                    'after' => ['universal_member' => $grant],
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR),
                'is_shared_dev_identity' => false,
                'created_at' => now(),
            ]);
            DB::table('users')->where('id', $targetId)->update([
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
            $this->remembered->revokeAllForUser($targetId);
            return true;
        }, 3);
    }
}
