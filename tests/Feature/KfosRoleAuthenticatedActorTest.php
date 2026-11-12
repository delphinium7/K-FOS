<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\KfosRoleChangeService;
use App\Services\Security\KfosRoleLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosRoleAuthenticatedActorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    private function employee(bool $administrator = false): User
    {
        $login = 'T5AD_'.strtoupper(Str::random(14));
        $employeeId = DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Actor Test',
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employeeId, 'login_id' => $login,
            'name' => 'Synthetic Actor Test', 'email' => null,
            'password' => Hash::make(Str::random(48)),
            'is_active' => true, 'is_system_admin' => false,
            'is_development_only' => false, 'session_version' => 1,
        ])->save();
        if ($administrator) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function role(): int
    {
        return DB::table('kfos_roles')->insertGetId([
            'code' => 't5ad_'.strtolower(Str::random(15)),
            'name' => 'Synthetic Role '.Str::random(12),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grantEditor(User $delegate): void
    {
        $role = $this->role();
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $role,
            'permission_id' => DB::table('kfos_permissions')
                ->where('code', 'security.roles.edit_permissions')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $delegate->id, 'role_id' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(User $actor, int $version = 1): void
    {
        $this->actingAs($actor, 'web')->withSession([
            'kfos_session_version' => $version,
        ]);
    }

    public function test_unlogged_permission_editor_cannot_write_using_user_object(): void
    {
        $delegate = $this->employee();
        $this->grantEditor($delegate);
        $role = $this->role();
        try {
            app(KfosRoleChangeService::class)->replacePermissions($delegate, $role, []);
            $this->fail('A model object without an authenticated session was accepted.');
        } catch (AuthorizationException $e) {
            $this->assertFalse(DB::table('security_audit_logs')
                ->where('event_type', 'kfos_role_permissions_changed')
                ->where('actor_user_id', $delegate->id)->exists());
        }
    }

    public function test_authenticated_other_user_cannot_supply_administrator_object(): void
    {
        $admin = $this->employee(true);
        $ordinary = $this->employee();
        $this->login($ordinary);
        try {
            app(KfosRoleLifecycleService::class)
                ->createOperationalRole($admin, 'Impersonated Role');
            $this->fail('Different authenticated user impersonated administrator.');
        } catch (AuthorizationException $e) {
            $this->assertFalse(DB::table('kfos_roles')
                ->where('name', 'Impersonated Role')->exists());
        }
    }

    public function test_stale_session_cannot_use_permission_editor_model(): void
    {
        $delegate = $this->employee();
        $this->grantEditor($delegate);
        $role = $this->role();
        $this->login($delegate, 0);
        try {
            app(KfosRoleChangeService::class)->replacePermissions($delegate, $role, []);
            $this->fail('A stale session changed a role.');
        } catch (AuthorizationException $e) {
            $this->assertSame(0, DB::table('kfos_role_permissions')
                ->where('role_id', $role)->count());
        }
    }

    public function test_authenticated_genuine_administrator_can_still_create_role(): void
    {
        $admin = $this->employee(true);
        $this->login($admin);
        $roleId = app(KfosRoleLifecycleService::class)
            ->createOperationalRole($admin, 'Authorized New Role');
        $this->assertSame('Authorized New Role',
            DB::table('kfos_roles')->where('id', $roleId)->value('name'));
        $this->assertDatabaseHas('security_audit_logs', [
            'actor_user_id' => $admin->id,
            'event_type' => 'kfos_operational_role_created',
            'result' => 'success',
        ]);
    }
}
