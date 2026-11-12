<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\KfosEmployeeAccountCreationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class KfosEmployeeAccountCreationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Initial-5AE-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    private function admin(bool $universal = true, bool $development = false): User
    {
        $login = 'T5AE_'.strtoupper(Str::random(13));
        $id = $development ? null : DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Administrator',
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $id, 'login_id' => $login,
            'name' => 'Synthetic Administrator', 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'is_system_admin' => $development,
            'is_development_only' => $development, 'session_version' => 1,
        ])->save();
        if ($universal && ! $development) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function master(bool $active = true): object
    {
        $login = 'T5AE_'.strtoupper(Str::random(13));
        $id = DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Employee',
            'position' => 'Test Operator', 'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('employees')->where('id', $id)->first();
    }

    private function role(bool $active = true, bool $universal = false): int
    {
        if ($universal) {
            return (int) DB::table('kfos_roles')
                ->where('code', 'system_administrator')->value('id');
        }
        return DB::table('kfos_roles')->insertGetId([
            'code' => 't5ae_'.strtolower(Str::random(14)),
            'name' => 'Synthetic Role '.Str::random(13),
            'kind' => 'operational', 'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(User $actor, int $version = 1): void
    {
        $this->actingAs($actor, 'web')
            ->withSession(['kfos_session_version' => $version]);
    }

    private function createAccount(User $admin, object $employee, int $roleId): User
    {
        return app(KfosEmployeeAccountCreationService::class)->create(
            $admin, $employee->employee_id, $employee->name,
            $employee->position, self::PASSWORD, $roleId
        );
    }

    public function test_admin_creates_active_account_with_role_audit_and_no_password_leak(): void
    {
        $admin = $this->admin();
        $employee = $this->master();
        $role = $this->role();
        $this->login($admin);

        $user = $this->createAccount($admin, $employee, $role);
        $this->assertSame($employee->employee_id, $user->login_id);
        $this->assertSame((int) $employee->id, (int) $user->employee_id);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->is_development_only);
        $this->assertFalse($user->isKfosSystemAdministrator());
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertDatabaseHas('kfos_operational_role_assignments', [
            'user_id' => $user->id, 'role_id' => $role,
        ]);
        $this->assertDatabaseHas('employee_id_reservations', [
            'employee_id' => $employee->id,
            'reserved_employee_id' => $employee->employee_id,
        ]);
        $audit = DB::table('security_audit_logs')
            ->where('target_user_id', $user->id)
            ->where('event_type', 'kfos_employee_account_created')->sole();
        $this->assertSame((int) $admin->id, (int) $audit->actor_user_id);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $audit->details);
        $this->assertStringNotContainsString($user->password, (string) $audit->details);
    }

    public function test_duplicate_account_rejected_without_duplicate_audit(): void
    {
        $admin = $this->admin();
        $employee = $this->master();
        $role = $this->role();
        $this->login($admin);
        $this->createAccount($admin, $employee, $role);
        $before = DB::table('security_audit_logs')
            ->where('event_type', 'kfos_employee_account_created')->count();
        try {
            $this->createAccount($admin, $employee, $role);
            $this->fail('Duplicate must fail.');
        } catch (ValidationException $expected) {
            $this->assertSame(1, DB::table('users')
                ->where('employee_id', $employee->id)->count());
            $this->assertSame($before, DB::table('security_audit_logs')
                ->where('event_type', 'kfos_employee_account_created')->count());
        }
    }

    public function test_missing_employee_master_rejected_without_creating_employee(): void
    {
        $admin = $this->admin();
        $role = $this->role();
        $this->login($admin);
        $code = 'T5AE_MISSING_'.strtoupper(Str::random(9));
        $count = DB::table('employees')->count();
        try {
            app(KfosEmployeeAccountCreationService::class)->create(
                $admin, $code, 'Nobody', 'Test Operator', self::PASSWORD, $role
            );
            $this->fail('An unknown Employee Master identity must be denied.');
        } catch (ValidationException $expected) {
            $this->assertSame($count, DB::table('employees')->count());
            $this->assertDatabaseMissing('users', ['login_id' => $code]);
        }
    }

    public function test_wrong_master_details_and_inactive_master_are_rejected(): void
    {
        $admin = $this->admin();
        $employee = $this->master(false);
        $role = $this->role();
        $this->login($admin);
        try {
            $this->createAccount($admin, $employee, $role);
            $this->fail('Inactive master must be denied');
        } catch (ValidationException $expected) {
            $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
        }
        DB::table('employees')->where('id', $employee->id)
            ->update(['is_active' => true]);
        try {
            app(KfosEmployeeAccountCreationService::class)->create(
                $admin, $employee->employee_id, 'Different Name',
                $employee->position, self::PASSWORD, $role
            );
            $this->fail('Master mismatch must be denied');
        } catch (ValidationException $expected) {
            $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
        }
    }

    public function test_rejects_weak_initial_password_and_disabled_or_universal_role(): void
    {
        $admin = $this->admin();
        $employee = $this->master();
        $this->login($admin);
        try {
            app(KfosEmployeeAccountCreationService::class)->create(
                $admin, $employee->employee_id, $employee->name,
                $employee->position, 'weak', $this->role()
            );
            $this->fail('Weak password must fail.');
        } catch (ValidationException $expected) {
            $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
        }
        foreach ([$this->role(false), $this->role(true, true)] as $roleId) {
            try {
                $this->createAccount($admin, $employee, $roleId);
                $this->fail('Invalid role must fail.');
            } catch (ValidationException $expected) {
                $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
            }
        }
    }

    public function test_nonadministrator_and_legacy_dev_identity_cannot_create_account(): void
    {
        $ordinary = $this->admin(false);
        $dev = $this->admin(false, true);
        $employee = $this->master();
        $role = $this->role();
        foreach ([$ordinary, $dev] as $actor) {
            $this->login($actor);
            try {
                $this->createAccount($actor, $employee, $role);
                $this->fail('Unauthorized actor must be denied.');
            } catch (AuthorizationException $expected) {
                $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
            }
        }
    }

    public function test_impersonation_and_stale_session_are_rejected(): void
    {
        $admin = $this->admin();
        $other = $this->admin(false);
        $employee = $this->master();
        $role = $this->role();
        $this->login($other);
        try {
            $this->createAccount($admin, $employee, $role);
            $this->fail('Impersonation must be denied');
        } catch (AuthorizationException $expected) {
            $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
        }
        $this->login($admin, 500);
        try {
            $this->createAccount($admin, $employee, $role);
            $this->fail('Stale session must be denied');
        } catch (AuthorizationException $expected) {
            $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
        }
    }

    public function test_sysadmin_can_assign_role_with_protected_business_authority_but_not_universal(): void
    {
        $admin = $this->admin();
        $employee = $this->master();
        $role = $this->role();
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $role,
            'permission_id' => DB::table('kfos_permissions')
                ->where('code', 'security.roles.assign')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($admin);
        $user = $this->createAccount($admin, $employee, $role);
        $this->assertTrue($user->hasKfosPermission('security.roles.assign'));
        $this->assertFalse($user->isKfosSystemAdministrator());
    }
}
