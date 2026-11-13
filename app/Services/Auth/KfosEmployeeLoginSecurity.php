<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Security\KfosAuthorization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Production-policy domain foundation, NOT a public login endpoint.
 * The HTTP controller must still perform its own session creation,
 * authorization, CSRF, request validation and Remember-me policy.
 */
final class KfosEmployeeLoginSecurity
{
    public function __construct(private KfosAuthorization $authorization) {}

    private function requireCurrentSystemAdministrator(User $actor): void
    {
        // Must be called inside an existing transaction holding the shared
        // administration advisory lock. A model argument alone is NOT authority.
        $authenticatedId = Auth::guard('web')->id();
        $version = session()->get('kfos_session_version');
        $fresh = DB::table('users')->where('id', $actor->getKey())
            ->lockForUpdate()->first();
        if ($authenticatedId === null || (int) $authenticatedId !== (int) $actor->getKey()
            || $fresh === null || $version === null
            || (int) $version !== (int) $fresh->session_version
            || ! $this->authorization->isSystemAdministrator($actor)) {
            abort(403, 'Current genuine System Administrator session required.');
        }
    }

    private function audit(string $event, string $result, ?User $user = null): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $result === 'success' ? $user?->id : null,
            'target_user_id' => $user?->id,
            'event_type' => $event,
            'result' => $result,
            'is_shared_dev_identity' => false,
            'created_at' => now(),
        ]);
    }

    /** Return a validated employee account or null. Never reveal the cause to the caller. */
    public function verifyCredentials(string $employeeId, string $password, bool $auditSuccessfulCredentials = true): ?User
    {
        return DB::transaction(function () use ($employeeId, $password, $auditSuccessfulCredentials): ?User {
            // Serialize account-wide attempts across independent origins.
            $user = User::query()
                ->where('login_id', $employeeId)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $this->audit('employee_login_unknown_id', 'failed');
                return null;
            }

            // Shared/synthetic development identities are never accepted here.
            if ($user->is_development_only || ! $user->is_active || $user->employee_id === null) {
                $this->audit('employee_login_denied', 'failed', $user);
                return null;
            }

            DB::table('kfos_login_security_states')->insertOrIgnore([
                'user_id' => $user->id,
                'failed_attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = DB::table('kfos_login_security_states')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($state->restricted_at !== null) {
                $this->audit('employee_login_restricted_attempt', 'failed', $user);
                return null;
            }

            if (! Hash::check($password, $user->password)) {
                $count = min(5, (int) $state->failed_attempts + 1);
                $updates = ['failed_attempts' => $count, 'updated_at' => now()];
                if ($count === 5) {
                    $updates['restricted_at'] = now();
                }
                DB::table('kfos_login_security_states')
                    ->where('user_id', $user->id)->update($updates);

                $this->audit('employee_login_invalid_password', 'failed', $user);

                if ($count === 5) {
                    DB::table('kfos_login_restriction_notifications')->insert([
                        'target_user_id' => $user->id,
                        'restricted_at' => $updates['restricted_at'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->audit('employee_login_restricted_five_failures', 'restricted', $user);
                }
                return null;
            }

            // Verify the original linked Employee Master, not a login-ID
            // string. Hold its row lock through the outer HTTP issuance TX.
            $originalEmployee = DB::table('employees')
                ->where('id', $user->employee_id)->lockForUpdate()->first();
            if ($originalEmployee === null || (! (bool) $originalEmployee->is_active
                && ! $this->authorization->isSystemAdministrator($user))) {
                // Correct password, but current original-employment eligibility
                // denies issuance. Do not reset failed counts or claim success.
                $this->audit('employee_login_session_issuance_denied', 'failed', $user);
                return null;
            }

            // Direct policy callers retain the historical verified-login
            // counter reset and audit. The HTTP issuing transaction performs
            // BOTH only after its final eligibility decision has passed.
            if ($auditSuccessfulCredentials) {
                DB::table('kfos_login_security_states')->where('user_id', $user->id)->update([
                    'failed_attempts' => 0,
                    'updated_at' => now(),
                ]);
                $this->audit('employee_credential_login_success', 'success', $user);
            }
            return $user;
        }, 3);
    }

    /** Only an eligible real System Administrator may explicitly restore login eligibility. */
    public function restore(User $actor, int $targetUserId, ?string $remark = null): bool
    {
        if ($remark !== null && mb_strlen($remark) > 1000) {
            throw ValidationException::withMessages(['remark' => 'Remark is too long.']);
        }

        return DB::transaction(function () use ($actor, $targetUserId, $remark): bool {
            // Serialize with the confirmed universal-admin, status and role writers.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
            $this->requireCurrentSystemAdministrator($actor);
            // Keep the same user->security-state lock order as credential login.
            $target = User::query()->whereKey($targetUserId)->lockForUpdate()->firstOrFail();
            if ($target->is_development_only || $target->employee_id === null) {
                abort(403, 'Not eligible for this restoration workflow.');
            }
            $state = DB::table('kfos_login_security_states')
                ->where('user_id', $target->id)->lockForUpdate()->first();
            if ($state === null || $state->restricted_at === null) {
                return false;
            }

            DB::table('kfos_login_security_states')->where('user_id', $target->id)->update([
                'failed_attempts' => 0,
                'restricted_at' => null,
                'restored_at' => now(),
                'restored_by_user_id' => $actor->id,
                'updated_at' => now(),
            ]);
            DB::table('kfos_login_restriction_notifications')
                ->where('target_user_id', $target->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolved_by_user_id' => $actor->id, 'updated_at' => now()]);
            DB::table('security_audit_logs')->insert([
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'event_type' => 'employee_login_restriction_restored',
                'result' => 'success',
                'details' => json_encode(['remark' => $remark]),
                'is_shared_dev_identity' => false,
                'created_at' => now(),
            ]);
            // Deliberately DO NOT revoke already-valid sessions or change passwords.
            return true;
        }, 3);
    }
}
