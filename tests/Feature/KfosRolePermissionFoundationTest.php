<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosRolePermissionFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    private function employee(bool $dev = false, bool $legacyAdmin = false): User
    {
        $login = 'T5Y_'.strtoupper(Str::random(16));
        $employeeId = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Test Employee',
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employeeId, 'login_id' => $login,
            'name' => 'Synthetic Test Employee', 'email' => null,
            'password' => Hash::make(Str::random(48)),
            'is_active' => true, 'is_system_admin' => $legacyAdmin,
            'is_development_only' => $dev, 'session_version' => 1,
        ])->save();
        return $user;
    }

    private function role(): int
    {
        return DB::table('kfos_roles')->insertGetId([
            'code' => 't5y_'.strtolower(Str::random(14)),
            'name' => 'Synthetic Operational Role', 'kind' => 'operational',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dev_admin_flag_never_grants_production_authority(): void
    {
        $dev = $this->employee(true, true);
        $this->assertFalse($dev->isKfosSystemAdministrator());
        $this->assertFalse($dev->hasKfosPermission('security.roles.assign'));
        $realWithoutMembership = $this->employee(false, true);
        $this->assertFalse($realWithoutMembership->isKfosSystemAdministrator());
        $this->assertFalse($realWithoutMembership->hasKfosPermission('security.roles.assign'));
    }

    public function test_operational_permission_grant_and_revocation_are_immediately_reflected(): void
    {
        $user = $this->employee();
        $roleId = $this->role();
        $code = 't5y.orders.'.strtolower(Str::random(12));
        $permissionId = DB::table('kfos_permissions')->insertGetId([
            'code' => $code, 'name' => 'Synthetic Test Permission',
            'category' => 'business', 'is_protected' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($user->hasKfosPermission($code));
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $permissionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue($user->hasKfosPermission($code));
        DB::table('kfos_role_permissions')->where('role_id', $roleId)
            ->where('permission_id', $permissionId)->delete();
        $this->assertFalse($user->hasKfosPermission($code));
    }

    public function test_protected_permission_requires_explicit_role_grant(): void
    {
        $user = $this->employee();
        $roleId = $this->role();
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($user->hasKfosPermission('security.roles.edit_permissions'));
        $permissionId = DB::table('kfos_permissions')
            ->where('code', 'security.roles.edit_permissions')->value('id');
        $this->assertNotNull($permissionId);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $permissionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue($user->hasKfosPermission('security.roles.edit_permissions'));
        DB::table('kfos_roles')->where('id', $roleId)->update(['is_active' => false]);
        $this->assertFalse($user->hasKfosPermission('security.roles.edit_permissions'));
    }

    public function test_real_administrator_requires_separate_membership(): void
    {
        $user = $this->employee();
        $this->assertFalse($user->hasKfosPermission('security.roles.assign'));
        DB::table('kfos_system_administrators')->insert([
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue($user->isKfosSystemAdministrator());
        $this->assertTrue($user->hasKfosPermission('security.roles.assign'));
        $this->assertFalse($user->hasKfosPermission('not.registered.permission'));
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->assertFalse($user->hasKfosPermission('security.roles.assign'));
    }
}
