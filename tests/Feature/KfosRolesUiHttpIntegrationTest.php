<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureKfosAuthStageLocal;
use App\Http\Middleware\KfosRestoreRememberedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class KfosRolesUiHttpIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private const PAGE = 'http://localhost:8080/kfos/security/ui/roles';
    private const API = 'http://localhost:8080/kfos/security/roles';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        app()->detectEnvironment(fn () => 'local');
        config(['app.debug' => true, 'app.url' => 'http://localhost:8080', 'session.driver' => 'array']);
        Route::middleware([EnsureKfosAuthStageLocal::class, KfosRestoreRememberedSession::class])
            ->group(function (): void {
                require base_path('routes/kfos-security-users.php');
                require base_path('routes/kfos-security-ui.php');
                require base_path('routes/kfos-security-roles.php');
            });
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertTrue(Route::has('kfos.security.roles.ui.index'));
        $this->assertTrue(Route::has('kfos.security.roles.permissions'));
    }

    private function account(bool $admin = false, bool $dev = false): User
    {
        $code = 'T5AN_'.strtoupper(Str::random(14));
        $employee = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $code, 'name' => 'Synthetic '.$code,
            'position' => 'Synthetic Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employee, 'login_id' => $code,
            'name' => 'Synthetic '.$code, 'email' => null,
            'password' => Hash::make('Synthetic-T5AN-Only-Password!'),
            'is_active' => true, 'is_development_only' => $dev,
            'is_system_admin' => $dev, 'session_version' => 1,
        ])->save();
        if ($admin) DB::table('kfos_system_administrators')->insert([
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function role(bool $protected = false): int
    {
        $id = DB::table('kfos_roles')->insertGetId([
            'code' => 't5an_'.strtolower(Str::random(20)),
            'name' => 'T5AN Role '.strtoupper(Str::random(12)),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($protected) $this->giveRolePermission($id, 'security.roles.edit_permissions');
        return $id;
    }

    private function giveRolePermission(int $roleId, string $code): void
    {
        $id = DB::table('kfos_permissions')->where('code', $code)->value('id');
        $this->assertNotNull($id, 'Permission missing: '.$code);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function delegate(string $permission): User
    {
        $user = $this->account();
        $roleId = $this->role();
        $this->giveRolePermission($roleId, $permission);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function login(User $actor, int $version = 1): void
    {
        $this->actingAs($actor, 'web')->withSession(['kfos_session_version' => $version]);
    }

    private function postJsonAction(string $url, array $values = []): \Illuminate\Testing\TestResponse
    {
        // This suite switches the Laravel environment to local, where normal
        // web CSRF verification applies. Send a genuine matching session token
        // and request header, as the real browser does; never disable CSRF.
        $token = Str::random(40);
        return $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson($url, $values);
    }

    public function test_role_creation_post_without_csrf_token_is_rejected(): void
    {
        $admin = $this->account(true);
        $this->login($admin);
        $name = 'T5AN CSRF Denied '.strtoupper(Str::random(10));
        $this->postJson(self::API, ['name' => $name])->assertStatus(419);
        $this->assertFalse(DB::table('kfos_roles')->where('name', $name)->exists());
    }

    public function test_role_creation_post_with_invalid_csrf_token_is_rejected(): void
    {
        $admin = $this->account(true);
        $this->login($admin);
        $name = 'T5AN CSRF Forged '.strtoupper(Str::random(10));
        $this->withHeader('X-CSRF-TOKEN', 'invalid-forged-token')
            ->postJson(self::API, ['name' => $name])->assertStatus(419);
        $this->assertFalse(DB::table('kfos_roles')->where('name', $name)->exists());
    }

    public function test_genuine_administrator_can_see_role_controls_and_create_a_role(): void
    {
        $admin = $this->account(true);$this->login($admin);
        $this->get(self::PAGE)->assertOk()->assertSee('Add Operational Role')
            ->assertSee('Assign Employee Role')->assertSee('name="_token"', false);
        $name = 'T5AN Created '.strtoupper(Str::random(10));
        $this->postJsonAction(self::API, ['name' => $name])->assertCreated()->assertJson(['created' => true]);
        $this->assertTrue(DB::table('kfos_roles')->where('name', $name)->where('kind', 'operational')->exists());
        $this->assertTrue(DB::table('security_audit_logs')->where('event_type', 'kfos_operational_role_created')->where('actor_user_id', $admin->id)->exists());
    }

    public function test_an_ordinary_employee_and_shared_development_identity_cannot_access_roles(): void
    {
        $this->assertNotSame(200, $this->get(self::PAGE)->status());
        $this->login($this->account());$this->get(self::PAGE)->assertForbidden();
        $this->login($this->account(false, true));$this->get(self::PAGE)->assertForbidden();
    }

    public function test_stale_administrator_session_cannot_change_permissions(): void
    {
        $admin = $this->account(true);$role = $this->role();$this->login($admin, 0);
        $this->assertNotSame(200, $this->postJsonAction(self::API.'/'.$role.'/permissions', [])->status());
        $this->assertFalse(DB::table('security_audit_logs')->where('event_type','kfos_role_permissions_changed')->where('actor_user_id', $admin->id)->exists());
    }

    public function test_delegated_role_creator_does_not_receive_permission_edit_or_assignment(): void
    {
        $delegate = $this->delegate('security.roles.create');$this->login($delegate);
        $this->get(self::PAGE)->assertOk()->assertSee('Add Operational Role')->assertDontSee('Save Permissions');
        $role = $this->role();
        $this->postJsonAction(self::API.'/'.$role.'/rename', ['name' => 'T5AN Other Name'])->assertForbidden();
        $this->postJsonAction(self::API.'/employees/'.$this->account()->id.'/role', ['role_id' => $role])->assertForbidden();
    }

    public function test_delegated_editor_preserves_existing_protected_rights_when_changing_business_permissions(): void
    {
        $delegate = $this->delegate('security.roles.edit_permissions');
        $role = $this->role(true);
        $code = 't5an.business.'.strtolower(Str::random(12));
        DB::table('kfos_permissions')->insert([
            'code' => $code, 'name' => 'T5AN Business Test', 'category' => 'business',
            'is_protected' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($delegate);
        $this->get(self::PAGE)->assertOk()->assertSee('Protected (read only)');
        $this->postJsonAction(self::API.'/'.$role.'/permissions', ['permission_codes' => [$code]])
            ->assertOk()->assertJson(['changed' => true]);
        $codes = DB::table('kfos_role_permissions as rp')
            ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $role)->pluck('p.code')->all();
        $this->assertContains($code, $codes);
        $this->assertContains('security.roles.edit_permissions', $codes);
        $this->postJsonAction(self::API.'/'.$role.'/permissions', ['permission_codes' => ['security.roles.assign']])
            ->assertUnprocessable();
        $this->assertNotContains('security.roles.assign', DB::table('kfos_role_permissions as rp')
            ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $role)->pluck('p.code')->all());
    }

    public function test_delegate_can_assign_ordinary_role_but_not_a_protected_destination(): void
    {
        $delegate = $this->delegate('security.roles.assign');
        $target = $this->account();$ordinary = $this->role();$protected = $this->role(true);
        $this->login($delegate);
        $this->postJsonAction(self::API.'/employees/'.$target->id.'/role', ['role_id' => $protected])
            ->assertForbidden();
        $this->postJsonAction(self::API.'/employees/'.$target->id.'/role', ['role_id' => $ordinary])
            ->assertOk()->assertJson(['changed' => true]);
        $this->assertSame($ordinary, (int) DB::table('kfos_operational_role_assignments')->where('user_id',$target->id)->value('role_id'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
    }

    public function test_role_with_any_current_employee_assignment_cannot_be_disabled(): void
    {
        $admin = $this->account(true);$target = $this->account();$role = $this->role();
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id'=>$target->id,'role_id'=>$role,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->login($admin);
        $this->postJsonAction(self::API.'/'.$role.'/status', ['is_active' => false])->assertUnprocessable();
        $this->assertTrue((bool) DB::table('kfos_roles')->where('id',$role)->value('is_active'));
    }
}
