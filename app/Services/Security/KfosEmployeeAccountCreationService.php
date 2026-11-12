<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated universal-System-Administrator-only creation of a real K-FOS
 * user for an ALREADY VERIFIED employee master record. This operation is NOT
 * initial administrator bootstrap, employee-master creation, or a public API.
 * AUTH-001/006/007/009/015/017/025; AUTH-DB-001/002/005/009; CDR-0556/0559.
 */
final class KfosEmployeeAccountCreationService
{
    public function __construct(private KfosAuthorization $authorization) {}

    private function mutationLock(): void
    {
        // Share transaction-wide lock with all K-FOS role writers.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
    }

    private function requireCurrentAdministrator(User $actor): void
    {
        $id = Auth::guard('web')->id();
        if ($id === null || (int) $id !== (int) $actor->getKey()) {
            throw new AuthorizationException('Current authenticated administrator required.');
        }

        $fresh = DB::table('users')->where('id', $actor->getKey())
            ->lockForUpdate()->first();
        $version = session()->get('kfos_session_version');
        if ($fresh === null || $version === null
            || (int) $version !== (int) $fresh->session_version
            || ! $this->authorization->isSystemAdministrator($actor)) {
            throw new AuthorizationException('Genuine active System Administrator required.');
        }
    }

    private function validInitialPassword(string $password): bool
    {
        return mb_strlen($password) >= 8
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[^\pL\pN\s]/u', $password) === 1;
    }

    /**
     * Create one standard active employee login, with exactly one current
     * ordinary operational role. Employee master identity is never modified.
     * The calling UI must arrange manual external credential handoff AFTER Save.
     */
    public function create(
        User $actor,
        string $officialEmployeeId,
        string $employeeName,
        string $employeePosition,
        string $initialPassword,
        int $operationalRoleId
    ): User {
        // Password material is never used in audit details or exception text.
        if (! $this->validInitialPassword($initialPassword)) {
            throw ValidationException::withMessages([
                'password' => 'Password does not meet the configured minimum requirements.',
            ]);
        }
        if ($officialEmployeeId === '' || mb_strlen($officialEmployeeId) > 100
            || $employeeName === '' || $employeePosition === '') {
            throw ValidationException::withMessages([
                'employee_id' => 'Valid official employee information is required.',
            ]);
        }

        $hash = Hash::make($initialPassword);
        try {
            return DB::transaction(function () use (
                $actor, $officialEmployeeId, $employeeName, $employeePosition,
                $hash, $operationalRoleId
            ): User {
                $this->mutationLock();
                $this->requireCurrentAdministrator($actor);

                $employee = DB::table('employees')
                    ->where('employee_id', $officialEmployeeId)
                    ->lockForUpdate()->first();
                if ($employee === null || ! $employee->is_active) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'A verified active Employee Master record is required.',
                    ]);
                }
                if ($employee->name !== $employeeName
                    || $employee->position !== $employeePosition) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Entered details must match the verified Employee Master.',
                    ]);
                }

                // The original official identifier is permanent; do not reuse
                // a historically reserved identifier belonging to someone else.
                $reservation = DB::table('employee_id_reservations')
                    ->where('reserved_employee_id', $officialEmployeeId)
                    ->lockForUpdate()->first();
                if ($reservation !== null
                    && (int) $reservation->employee_id !== (int) $employee->id) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Employee identifier is reserved.',
                    ]);
                }
                if (DB::table('users')
                    ->where('employee_id', $employee->id)
                    ->orWhere('login_id', $officialEmployeeId)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Employee account already exists.',
                    ]);
                }

                $role = DB::table('kfos_roles')->where('id', $operationalRoleId)
                    ->lockForUpdate()->first();
                if ($role === null || $role->kind !== 'operational'
                    || ! $role->is_active) {
                    throw ValidationException::withMessages([
                        'role_id' => 'Select an active operational role.',
                    ]);
                }

                if ($reservation === null) {
                    DB::table('employee_id_reservations')->insert([
                        'employee_id' => $employee->id,
                        'reserved_employee_id' => $officialEmployeeId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $userId = DB::table('users')->insertGetId([
                    'employee_id' => $employee->id,
                    'login_id' => $officialEmployeeId,
                    'name' => $employee->name,
                    'email' => null,
                    'password' => $hash,
                    'is_active' => true,
                    'is_system_admin' => false,
                    'is_development_only' => false,
                    'session_version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('kfos_operational_role_assignments')->insert([
                    'user_id' => $userId, 'role_id' => $role->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('security_audit_logs')->insert([
                    'actor_user_id' => $actor->getKey(),
                    'target_user_id' => $userId,
                    'event_type' => 'kfos_employee_account_created',
                    'result' => 'success',
                    'details' => json_encode([
                        'employee_id' => $officialEmployeeId,
                        'employee_master_id' => (int) $employee->id,
                        'actor_capacity' => 'system_administrator',
                        'before' => ['account_exists' => false, 'role_id' => null],
                        'after' => [
                            'account_exists' => true,
                            'account_status' => 'active',
                            'operational_role_id' => (int) $role->id,
                        ],
                        'credential' => 'initial_password_set_securely_not_logged',
                    ], JSON_THROW_ON_ERROR),
                    'is_shared_dev_identity' => false,
                    'created_at' => now(),
                ]);
                return User::query()->findOrFail($userId);
            }, 3);
        } catch (QueryException $exception) {
            // Concurrent unique-index violations cannot reveal internal IDs.
            throw ValidationException::withMessages([
                'employee_id' => 'Account creation could not be completed.',
            ]);
        }
    }
}
