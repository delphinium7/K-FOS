<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AUTH-005/091 initial one-time, named-employee bootstrap domain operation.
 * This does not verify company approval from an untrusted text parameter.
 * Its sole operational adapter is the restricted interactive CLI command.
 * It must NEVER be reused as emergency recovery (AUTH-090 Option B).
 */
final class KfosInitialAdministratorBootstrap
{
    private function reject(string $reason = 'First-administrator initialization refused.'): never
    {
        throw new RuntimeException($reason);
    }

    private function passwordCompliant(string $password): bool
    {
        return mb_strlen($password) >= 8
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[^\pL\pN\s]/u', $password) === 1;
    }

    /**
     * Call only after company designations have been independently verified.
     * @param string $employeeId Exact existing Employee Master official ID.
     * @param string $operatorReference Auditable named technical-operator reference.
     * @param string $approvalReference Internal proof of company authorization.
     */
    public function initialize(
        string $employeeId,
        string $operatorReference,
        string $approvalReference,
        string $password
    ): User {
        $employeeId = trim($employeeId);
        $operatorReference = trim($operatorReference);
        $approvalReference = trim($approvalReference);
        if ($employeeId === '' || mb_strlen($employeeId) > 100
            || strtoupper($employeeId) === 'KFOS_ADMIN'
            || $operatorReference === '' || mb_strlen($operatorReference) > 150
            || $approvalReference === '' || mb_strlen($approvalReference) > 150
            || ! $this->passwordCompliant($password)) {
            $this->reject('Invalid identity, designation reference or password policy.');
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->reject('Authorized PostgreSQL server required.');
        }
        foreach ([
            'employees', 'users', 'employee_id_reservations',
            'kfos_roles', 'kfos_system_administrators',
            'kfos_operational_role_assignments', 'kfos_login_security_states',
            'kfos_remembered_devices', 'security_audit_logs',
            'kfos_first_admin_bootstrap_events', 'kfos_account_security_epochs',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $this->reject('Required security database schema unavailable.');
            }
        }

        return DB::transaction(function () use (
            $employeeId, $operatorReference, $approvalReference, $password
        ): User {
            // Share the lock with KfosUniversalAdministratorService and
            // KfosCredentialSessionIssuer to serialize administrator changes.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);

            if (DB::table('kfos_first_admin_bootstrap_events')->exists()
                || DB::table('kfos_system_administrators')->exists()
                || DB::table('security_audit_logs')
                    ->whereIn('event_type', [
                        'kfos_first_administrator_bootstrapped',
                        'kfos_universal_admin_granted',
                        'kfos_universal_admin_revoked',
                    ])->exists()) {
                $this->reject();
            }
            $role = DB::table('kfos_roles')->where('code', 'system_administrator')
                ->lockForUpdate()->first();
            if ($role === null || $role->kind !== 'universal' || ! $role->is_active) {
                $this->reject('Canonical administrator role unavailable.');
            }
            // Follow the existing login issuer's user -> original Employee
            // Master lock order. First read only the stable original key.
            $reference = DB::table('employees')
                ->where('employee_id', $employeeId)->first(['id']);
            if ($reference === null) {
                $this->reject('A designated, active original Employee Master record is required.');
            }
            $account = DB::table('users')->where('employee_id', $reference->id)
                ->lockForUpdate()->first();
            $employee = DB::table('employees')->where('id', $reference->id)
                ->lockForUpdate()->first();
            if ($employee === null || ! (bool) $employee->is_active
                || ! hash_equals($employeeId, (string) $employee->employee_id)) {
                $this->reject('A designated, active original Employee Master record is required.');
            }
            $reservation = DB::table('employee_id_reservations')
                ->where('reserved_employee_id', $employeeId)->lockForUpdate()->first();
            if ($reservation !== null && (int) $reservation->employee_id !== (int) $employee->id) {
                $this->reject('The original employee identifier is reserved elsewhere.');
            }
            $nameCollision = DB::table('users')->where('login_id', $employeeId)
                ->when($account !== null, fn ($q) => $q->where('id', '<>', $account->id))
                ->exists();
            if ($nameCollision || ($account !== null && (
                ! (bool) $account->is_active || (bool) $account->is_development_only
                || ! hash_equals($employeeId, (string) $account->login_id)
                || DB::table('kfos_operational_role_assignments')
                    ->where('user_id', $account->id)->exists()
                || DB::table('kfos_login_security_states')
                    ->where('user_id', $account->id)->whereNotNull('restricted_at')->exists()
            ))) {
                $this->reject('Existing account does not satisfy bootstrap safeguards.');
            }
            $hash = Hash::make($password);
            if ($account === null) {
                $userId = DB::table('users')->insertGetId([
                    'employee_id' => $employee->id,
                    'login_id' => $employeeId,
                    'name' => $employee->name,
                    'email' => null,
                    'password' => $hash,
                    'is_active' => true,
                    'is_system_admin' => false, // Never use legacy flag as authority.
                    'is_development_only' => false,
                    'session_version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $userId = (int) $account->id;
                // Existing employee account is allowed only if not locked or
                // operationally assigned. Invalidate its old sessions/tokens.
                DB::table('users')->where('id', $userId)->update([
                    'password' => $hash,
                    'session_version' => DB::raw('session_version + 1'),
                    'updated_at' => now(),
                ]);
                DB::table('kfos_remembered_devices')->where('user_id', $userId)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            if ($reservation === null) {
                DB::table('employee_id_reservations')->insert([
                    'employee_id' => $employee->id,
                    'reserved_employee_id' => $employeeId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // The new administrator has an explicit epoch row before login.
            DB::table('kfos_account_security_epochs')->insertOrIgnore([
                'user_id' => $userId, 'epoch' => 0,
                'revocation_pending' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('kfos_first_admin_bootstrap_events')->insert([
                'id' => 1, 'user_id' => $userId,
                'employee_master_id' => $employee->id,
                'official_employee_id' => $employeeId,
                'operator_reference' => $operatorReference,
                'authorization_reference' => $approvalReference,
                'created_at' => now(),
            ]);
            DB::table('security_audit_logs')->insert([
                'actor_user_id' => null, // Operator is not an authenticated KFOS administrator.
                'target_user_id' => $userId,
                'event_type' => 'kfos_first_administrator_bootstrapped',
                'result' => 'success',
                'details' => json_encode([
                    'original_employee_master_id' => (int) $employee->id,
                    'official_employee_id' => $employeeId,
                    'designated_operator_reference' => $operatorReference,
                    'authorization_reference' => $approvalReference,
                    'role_code' => 'system_administrator',
                    'credential' => 'hashed_not_logged',
                    'before' => ['universal_member' => false],
                    'after' => ['universal_member' => true],
                ], JSON_THROW_ON_ERROR),
                'is_shared_dev_identity' => false,
                'created_at' => now(),
            ]);
            return User::query()->findOrFail($userId);
        }, 3);
    }
}
