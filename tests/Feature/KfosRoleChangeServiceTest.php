<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\KfosRoleChangeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosRoleChangeServiceTest extends TestCase
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
        $login = 'T5Z_'.strtoupper(Str::random(14));
        $employee = DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Test Employee',
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employee, 'login_id' => $login,
            'name' => 'Synthetic Test Employee', 'email' => null,
            'password' => Hash::make(Str::random(40)),
            'is_active' => true, 'is_system_admin' => false,
            'is_development_only' => false, 'session_version' => 1,
        ])->save();
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function role(): int
    {
        return DB::table('kfos_roles')->insertGetId([
            'code' => 't5z_'.strtolower(Str::random(15)),
            'name' => 'Synthetic Role '.Str::random(8), 'kind' => 'operational',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grant(int $roleId, string $code): void
    {
        $id = DB::table('kfos_permissions')->where('code', $code)->value('id');
        $this->assertNotNull($id);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assign(User $user, int $roleId): void
    {
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(User $actor): void
    {
        $this->actingAs($actor, 'web')->withSession([
            'kfos_session_version' => (int) $actor->session_version,
        ]);
    }

    public function test_delegate_business_edit_audits_and_revokes_all_members(): void
    {
        $editor = $this->employee();
        $editorRole = $this->role();
        $this->grant($editorRole, 'security.roles.edit_permissions');
        $this->assign($editor, $editorRole);
        $member = $this->employee();
        $targetRole = $this->role();
        $this->assign($member, $targetRole);
        $code = 't5z.production.'.strtolower(Str::random(12));
        DB::table('kfos_permissions')->insert([
            'code' => $code, 'name' => 'Synthetic Business Permission',
            'category' => 'business', 'is_protected' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($editor);
        $service = app(KfosRoleChangeService::class);
        $this->assertTrue($service->replacePermissions($editor, $targetRole, [$code]));
        $this->assertTrue($member->hasKfosPermission($code));
        $this->assertSame(2, (int) DB::table('users')->where('id', $member->id)->value('session_version'));
        $log = DB::table('security_audit_logs')->where('event_type', 'kfos_role_permissions_changed')->latest('id')->first();
        $this->assertSame($editor->id, (int) $log->actor_user_id);
        $this->assertSame([$code], json_decode($log->details, true)['after']);
        $this->assertFalse($service->replacePermissions($editor, $targetRole, [$code]));
        $this->assertSame(1, DB::table('security_audit_logs')->where('event_type', 'kfos_role_permissions_changed')->where('actor_user_id', $editor->id)->count());
    }

    public function test_delegate_cannot_change_protected_permissions(): void
    {
        $editor = $this->employee();
        $editorRole = $this->role();
        $this->grant($editorRole, 'security.roles.edit_permissions');
        $this->assign($editor, $editorRole);
        $role = $this->role();
        $this->login($editor);
        try {
            app(KfosRoleChangeService::class)->replacePermissions($editor, $role, ['security.roles.assign']);
            $this->fail('Protected checkbox escalation was accepted.');
        } catch (AuthorizationException $e) {
            $this->assertFalse(DB::table('kfos_role_permissions')->where('role_id', $role)->exists());
        }
        $this->assertSame(0, DB::table('security_audit_logs')->where('event_type', 'kfos_role_permissions_changed')->where('actor_user_id', $editor->id)->count());
    }

    public function test_delegate_can_transfer_away_from_protected_but_not_into_it(): void
    {
        $delegate = $this->employee();
        $sourceRole = $this->role();
        $this->grant($sourceRole, 'security.roles.assign');
        $this->assign($delegate, $sourceRole);
        $target = $this->employee();
        $protectedRole = $this->role();
        $this->grant($protectedRole, 'security.roles.edit_permissions');
        $ordinaryRole = $this->role();
        $this->assign($target, $protectedRole); // Pre-existing membership fixture.
        $this->login($delegate);
        $service = app(KfosRoleChangeService::class);
        // A no-op is not a successful role change and must not revoke sessions.
        $this->assertFalse($service->assignOperationalRole($delegate, $target->id, $protectedRole));
        $this->assertSame(1, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->assertTrue($service->assignOperationalRole($delegate, $target->id, $ordinaryRole));
        $this->assertSame($ordinaryRole, (int) DB::table('kfos_operational_role_assignments')->where('user_id', $target->id)->value('role_id'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        try {
            $service->assignOperationalRole($delegate, $target->id, $protectedRole);
            $this->fail('Delegate assigned INTO protected role.');
        } catch (AuthorizationException $e) {
            $this->assertSame($ordinaryRole, (int) DB::table('kfos_operational_role_assignments')->where('user_id', $target->id)->value('role_id'));
        }
        $this->assertSame(1, DB::table('security_audit_logs')->where('event_type', 'kfos_operational_role_assignment_changed')->where('actor_user_id', $delegate->id)->count());
        $this->assertTrue($service->assignOperationalRole($delegate, $delegate->id, $ordinaryRole));
        $this->assertFalse($delegate->hasKfosPermission('security.roles.assign'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $delegate->id)->value('session_version'));
    }

    public function test_real_admin_can_grant_protected_role_but_not_to_universal_admin(): void
    {
        $admin = $this->employee(true);
        $employee = $this->employee();
        $protectedRole = $this->role();
        $this->login($admin);
        $service = app(KfosRoleChangeService::class);
        $this->assertTrue($service->replacePermissions($admin, $protectedRole, ['security.roles.create']));
        $this->assertTrue($service->assignOperationalRole($admin, $employee->id, $protectedRole));
        $this->assertTrue($employee->hasKfosPermission('security.roles.create'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $employee->id)->value('session_version'));
        try {
            $service->assignOperationalRole($admin, $admin->id, $protectedRole);
            $this->fail('Unapproved coexistence allowed.');
        } catch (AuthorizationException $e) {
            $this->assertFalse(DB::table('kfos_operational_role_assignments')->where('user_id', $admin->id)->exists());
        }
    }
}
