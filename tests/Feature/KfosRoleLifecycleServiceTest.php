<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\KfosRoleLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class KfosRoleLifecycleServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    private function employee(bool $admin = false): User
    {
        $login = 'T5AA_'.strtoupper(Str::random(14));
        $empId = DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Lifecycle Tester',
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $empId, 'login_id' => $login,
            'name' => 'Synthetic Lifecycle Tester', 'email' => null,
            'password' => Hash::make(Str::random(48)), 'is_active' => true,
            'is_system_admin' => false, 'is_development_only' => false,
            'session_version' => 1,
        ])->save();
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function grantDelegate(User $user, string $permission): void
    {
        $roleId = DB::table('kfos_roles')->insertGetId([
            'code' => 't5aa_'.strtolower(Str::random(15)),
            'name' => 'Editor '.Str::random(12), 'kind' => 'operational',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => DB::table('kfos_permissions')->where('code', $permission)->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function businessPermission(): string
    {
        $code = 't5aa.production.'.strtolower(Str::random(12));
        DB::table('kfos_permissions')->insert([
            'code' => $code, 'name' => 'Synthetic Business Permission',
            'category' => 'business', 'is_protected' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $code;
    }

    private function login(User $actor): void
    {
        $this->actingAs($actor, 'web')->withSession([
            'kfos_session_version' => (int) $actor->session_version,
        ]);
    }

    public function test_admin_can_create_role_with_protected_permission_but_no_auto_assignment(): void
    {
        $admin = $this->employee(true);
        $this->login($admin);
        $service = app(KfosRoleLifecycleService::class);
        $id = $service->createOperationalRole($admin, 'Workshop & Planning', ['security.roles.create']);
        $this->assertSame('Workshop & Planning', DB::table('kfos_roles')->where('id', $id)->value('name'));
        $this->assertSame(1, DB::table('kfos_role_permissions')->where('role_id', $id)->count());
        $this->assertSame(0, DB::table('kfos_operational_role_assignments')->where('role_id', $id)->count());
        $this->assertFalse($service->renameOperationalRole($admin, $id, 'Workshop & Planning'));
        $this->assertSame(1, DB::table('security_audit_logs')
            ->where('event_type','kfos_operational_role_created')->where('actor_user_id',$admin->id)->count());
    }

    public function test_delegate_can_create_unassigned_zero_permission_role_but_not_protected_role(): void
    {
        $delegate = $this->employee();
        $this->grantDelegate($delegate, 'security.roles.create');
        $this->login($delegate);
        $service = app(KfosRoleLifecycleService::class);
        $id = $service->createOperationalRole($delegate, 'Production Planner', []);
        $this->assertSame(0, DB::table('kfos_role_permissions')->where('role_id', $id)->count());
        try {
            $service->createOperationalRole($delegate, 'Protected Editor', ['security.roles.assign']);
            $this->fail('Delegate created role with protected permission.');
        } catch (AuthorizationException $e) {
            $this->assertFalse(DB::table('kfos_roles')->where('name', 'Protected Editor')->exists());
        }
    }

    public function test_normalized_name_duplicates_and_admin_impersonation_are_rejected(): void
    {
        $admin = $this->employee(true);
        $this->login($admin);
        $service = app(KfosRoleLifecycleService::class);
        $service->createOperationalRole($admin, 'Production Planner');
        foreach (['  production   planner  ', 'System-Administrator', 'S y s t e m Administrator', 'A', 'Wrong*Name'] as $name) {
            try {
                $service->createOperationalRole($admin, $name);
                $this->fail('Invalid or duplicate role name accepted: '.$name);
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(1, DB::table('kfos_roles')->where('kind', 'operational')->where('name', 'Production Planner')->count());
    }

    public function test_delegate_rename_preserves_membership_permissions_and_sessions(): void
    {
        $delegate = $this->employee();
        $this->grantDelegate($delegate, 'security.roles.edit_permissions');
        $member = $this->employee();
        $admin = $this->employee(true);
        $code = $this->businessPermission();
        $this->login($admin);
        $service = app(KfosRoleLifecycleService::class);
        $id = $service->createOperationalRole($admin, 'Cutting Team', [$code]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $member->id, 'role_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($delegate);
        $this->assertTrue($service->renameOperationalRole($delegate, $id, 'Cutting & Assembly'));
        $this->assertTrue($member->hasKfosPermission($code));
        $this->assertSame(1, (int) DB::table('users')->where('id', $member->id)->value('session_version'));
        $details = json_decode(DB::table('security_audit_logs')->where('event_type', 'kfos_operational_role_renamed')
            ->where('actor_user_id',$delegate->id)->value('details'), true);
        $this->assertSame('Cutting Team', $details['before']['name']);
        $this->assertSame('Cutting & Assembly', $details['after']['name']);
    }

    public function test_cannot_disable_role_with_member_even_if_employee_login_is_inactive(): void
    {
        $admin = $this->employee(true);
        $member = $this->employee();
        $this->login($admin);
        $service = app(KfosRoleLifecycleService::class);
        $id = $service->createOperationalRole($admin, 'Paint Section');
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $member->id, 'role_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $member->id)->update(['is_active' => false]);
        try {
            $service->setOperationalRoleActive($admin, $id, false);
            $this->fail('Role disable despite current assignment succeeded.');
        } catch (ValidationException $e) {
            $this->assertTrue((bool) DB::table('kfos_roles')->where('id', $id)->value('is_active'));
        }
        $this->assertSame(0, DB::table('security_audit_logs')
            ->where('event_type', 'kfos_operational_role_disabled')->where('actor_user_id',$admin->id)->count());
    }

    public function test_delegate_disable_and_reactivate_zero_member_role_without_session_revocation(): void
    {
        $delegate = $this->employee();
        $this->grantDelegate($delegate, 'security.roles.edit_permissions');
        $admin = $this->employee(true);
        $this->login($admin);
        $service = app(KfosRoleLifecycleService::class);
        $id = $service->createOperationalRole($admin, 'Disabled Name Reserved');
        $this->login($delegate);
        $this->assertTrue($service->setOperationalRoleActive($delegate, $id, false));
        $this->assertFalse($service->setOperationalRoleActive($delegate, $id, false));
        try {
            $this->login($admin);
            $service->createOperationalRole($admin, 'disabled  name reserved');
            $this->fail('Disabled name reused.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->login($delegate);
        $this->assertTrue($service->setOperationalRoleActive($delegate, $id, true));
        $this->assertSame(1, (int) DB::table('users')->where('id', $delegate->id)->value('session_version'));
        $this->assertSame(2, DB::table('security_audit_logs')->where('actor_user_id', $delegate->id)
            ->whereIn('event_type', ['kfos_operational_role_disabled','kfos_operational_role_reactivated'])->count());
    }

    public function test_universal_role_and_unauthorized_writer_are_rejected(): void
    {
        $ordinary = $this->employee();
        $admin = $this->employee(true);
        $this->login($admin);
        $service = app(KfosRoleLifecycleService::class);
        $id = DB::table('kfos_roles')->where('code', 'system_administrator')->value('id');
        foreach (['rename', 'disable'] as $action) {
            try {
                $action === 'rename'
                    ? $service->renameOperationalRole($admin, $id, 'Fake Universal Role')
                    : $service->setOperationalRoleActive($admin, $id, false);
                $this->fail('Universal role was changed.');
            } catch (AuthorizationException $e) {
                $this->assertSame('System Administrator', DB::table('kfos_roles')->where('id', $id)->value('name'));
            }
        }
        try {
            $this->login($ordinary);
            $service->createOperationalRole($ordinary, 'Unauthorized Role');
            $this->fail('Unauthorized ordinary user created role.');
        } catch (AuthorizationException $e) {
            $this->assertFalse(DB::table('kfos_roles')->where('name','Unauthorized Role')->exists());
        }
    }
}
