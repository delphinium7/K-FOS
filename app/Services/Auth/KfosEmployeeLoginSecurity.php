<?php

namespace App\Services\Auth;

use App\Models\User;
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
    public function verifyCredentials(string $employeeId, string $password): ?User
    {
        return DB::transaction(function () use ($employeeId, $password): ?User {
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

            // Only a genuine new password login clears 1–4 failures.
            DB::table('kfos_login_security_states')->where('user_id', $user->id)->update([
                'failed_attempts' => 0,
                'updated_at' => now(),
            ]);
            $this->audit('employee_credential_login_success', 'success', $user);
            return $user;
        }, 3);
    }

    /** Only an eligible real System Administrator may explicitly restore login eligibility. */
    public function restore(User $actor, int $targetUserId, ?string $remark = null): bool
    {
        if (! $actor->is_active || ! $actor->is_system_admin ||
            $actor->is_development_only || $actor->employee_id === null) {
            abort(403, 'Not authorized to restore login access.');
        }

        if ($remark !== null && mb_strlen($remark) > 1000) {
            throw ValidationException::withMessages(['remark' => 'Remark is too long.']);
        }

        return DB::transaction(function () use ($actor, $targetUserId, $remark): bool {
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
