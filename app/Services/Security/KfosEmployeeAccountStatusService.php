<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genuine employee LOGIN account status only, not employee-master employment status.
 * CDR-0607/0608, AUTH-026/027/028/029/030/085.
 * No production HTTP route is mounted by this batch.
 */
final class KfosEmployeeAccountStatusService
{
    public function __construct(
        private KfosAuthorization $authorization,
        private KfosRememberedDeviceService $remembered
    ) {}

    private function mutationLock(): void
    {
        // Same lock as role, universal-member, and role-lifecycle writers.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
    }

    /** Returns whether authenticated actor has genuine universal membership. */
    private function authorizeActor(User $actor): bool
    {
        $current = Auth::guard('web')->id();
        if ($current === null || (int) $current !== (int) $actor->getKey()) {
            throw new AuthorizationException('Current authenticated actor required.');
        }
        $fresh = DB::table('users')->where('id', $actor->getKey())
            ->lockForUpdate()->first();
        $version = session()->get('kfos_session_version');
        if ($fresh === null || $version === null
            || (int) $version !== (int) $fresh->session_version
            || ! $this->authorization->allows($actor, 'security.accounts.status')) {
            throw new AuthorizationException('Current account-status authority required.');
        }
        return $this->authorization->isSystemAdministrator($actor);
    }

    /** Exclude target; count genuine administrators with a usable new-login path. */
    private function otherUsableAdministratorCount(int $targetUserId): int
    {
        return (int) DB::table('kfos_system_administrators as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->where('a.user_id', '<>', $targetUserId)
            ->where('u.is_active', true)
            ->where('u.is_development_only', false)
            ->where('e.is_active', true)
            ->whereNotNull('u.password')
            ->where('u.password', '<>', '')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('kfos_roles as r')
                    ->where('r.code', 'system_administrator')
                    ->where('r.kind', 'universal')
                    ->where('r.is_active', true);
            })
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

    /**
     * True only for a committed change; false for a no-op. On denial/exception,
     * the transaction rolls back including session/version and any audit insert.
     */
    public function setActive(User $actor, int $targetUserId, bool $active, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($actor, $targetUserId, $active, $reason): bool {
            $this->mutationLock();
            $isAdmin = $this->authorizeActor($actor);
            $reason = $this->normalizedReason($reason);
            $target = DB::table('users')->where('id', $targetUserId)
                ->lockForUpdate()->first();
            if ($target === null || $target->is_development_only || $target->employee_id === null
                || ! DB::table('employees')->where('id', $target->employee_id)->exists()) {
                throw new AuthorizationException('Existing genuine employee account required.');
            }
            // CDR-0608 and AUTH-030: no self-deactivation, irrespective of role.
            if (! $active && (int) $targetUserId === (int) $actor->getKey()) {
                throw new AuthorizationException('Self-deactivation is not permitted.');
            }
            $isUniversalTarget = DB::table('kfos_system_administrators')
                ->where('user_id', $targetUserId)->exists();
            if ($isUniversalTarget && ! $isAdmin) {
                throw new AuthorizationException('A delegate cannot change an administrator account.');
            }
            if ($isUniversalTarget && ! $active
                && $this->otherUsableAdministratorCount($targetUserId) < 1) {
                throw new AuthorizationException('Cannot deactivate the last usable administrator.');
            }
            if ((bool) $target->is_active === $active) {
                return false; // No false success audit or session revocation.
            }

            DB::table('users')->where('id', $targetUserId)->update([
                'is_active' => $active,
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
            // Invalidate all remembered grants; current and old sessions fail via version.
            $this->remembered->revokeAllForUser($targetUserId);
            DB::table('security_audit_logs')->insert([
                'actor_user_id' => $actor->getKey(),
                'target_user_id' => $targetUserId,
                'event_type' => $active
                    ? 'kfos_employee_account_reactivated'
                    : 'kfos_employee_account_deactivated',
                'result' => 'success',
                'details' => json_encode([
                    'employee_id' => (int) $target->employee_id,
                    'actor_capacity' => $isAdmin
                        ? 'system_administrator' : 'manage_employee_account_status',
                    'before' => ['login_account_status' => $target->is_active ? 'active' : 'inactive'],
                    'after' => ['login_account_status' => $active ? 'active' : 'inactive'],
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR),
                'is_shared_dev_identity' => false,
                'created_at' => now(),
            ]);
            return true;
        }, 3);
    }
}
