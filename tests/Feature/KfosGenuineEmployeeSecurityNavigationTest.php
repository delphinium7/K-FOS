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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class KfosGenuineEmployeeSecurityNavigationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-5AP-Only-Test!';
    private const LOGIN = 'http://localhost:8080/kfos-auth-stage/login';
    private const HOME = 'http://localhost:8080/kfos-auth-stage/ui/home';
    private const LOGOUT = 'http://localhost:8080/kfos-auth-stage/logout';
    private const USERS = 'http://localhost:8080/kfos/security/ui/users';
    private const ROLES = 'http://localhost:8080/kfos/security/ui/roles';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        app()->detectEnvironment(fn () => 'local');
        config([
            'app.debug' => true, 'app.url' => 'http://localhost:8080',
            'kfos_auth_stage.enabled' => true,
            'session.driver' => 'array',
        ]);
        $this->withCredentials();
        Route::middleware('web')->group(function (): void {
            require base_path('routes/kfos-auth-stage.php');
        });
        Route::middleware([EnsureKfosAuthStageLocal::class, KfosRestoreRememberedSession::class])
            ->group(function (): void {
                require base_path('routes/kfos-security-users.php');
                require base_path('routes/kfos-security-ui.php');
                require base_path('routes/kfos-security-roles.php');
            });
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertTrue(Route::has('kfos.auth.stage.ui.home'));
        $this->assertTrue(Route::has('kfos.security.roles.ui.index'));
    }

    private function account(bool $administrator = false, bool $developmentOnly = false, bool $legacyAdmin = false): User
    {
        $code = 'T5AP_'.strtoupper(Str::random(14));
        $employee = $developmentOnly ? null : DB::table('employees')->insertGetId([
            'employee_id' => $code,
            'name' => 'Synthetic '.$code,
            'position' => 'Synthetic Operator',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employee, 'login_id' => $code,
            'name' => 'Synthetic '.$code, 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'is_system_admin' => $legacyAdmin || $developmentOnly,
            'is_development_only' => $developmentOnly,
            'session_version' => 1,
        ])->save();
        if ($administrator) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function delegate(string $right): User
    {
        $user = $this->account();
        $role = DB::table('kfos_roles')->insertGetId([
            'code' => 't5ap_'.strtolower(Str::random(20)),
            'name' => 'T5AP Role '.strtoupper(Str::random(12)),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $permission = DB::table('kfos_permissions')->where('code', $right)->value('id');
        $this->assertNotNull($permission);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $role, 'permission_id' => $permission,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $user->id, 'role_id' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function csrfPost(string $url, array $payload): TestResponse
    {
        $token = Str::random(40);
        return $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson($url, $payload);
    }

    private function employeeLogin(User $user): void
    {
        $this->csrfPost(self::LOGIN, [
            'employee_id' => $user->login_id,
            'password' => self::PASSWORD,
            'remember_me' => false,
        ])->assertOk()->assertJsonPath('authenticated', true);
    }

    public function test_real_administrator_credential_login_unlocks_security_pages(): void
    {
        $admin = $this->account(true);
        $this->employeeLogin($admin);
        $this->get(self::HOME)->assertOk()
            ->assertSee('Open User Management')
            ->assertSee('Create Employee Login')
            ->assertSee('Open Roles &amp; Permissions', false);
        $this->get(self::USERS)->assertOk();
        $this->get(self::USERS.'/create')->assertOk();
        $this->get(self::ROLES)->assertOk();
        $this->assertTrue(DB::table('security_audit_logs')
            ->where('target_user_id', $admin->id)
            ->where('event_type', 'employee_credential_login_success')->exists());
    }

    public function test_account_status_delegate_sees_users_but_not_roles_or_add_user(): void
    {
        $delegate = $this->delegate('security.accounts.status');
        $this->employeeLogin($delegate);
        $this->get(self::HOME)->assertOk()
            ->assertSee('Open User Management')
            ->assertDontSee('Open Roles &amp; Permissions', false)
            ->assertDontSee('Create Employee Login');
        $this->get(self::USERS)->assertOk()
            ->assertDontSee('href="'.self::ROLES.'"', false);
        $this->get(self::ROLES)->assertForbidden();
        $this->get(self::USERS.'/create')->assertForbidden();
    }

    public function test_role_creator_sees_only_role_management_navigation(): void
    {
        $delegate = $this->delegate('security.roles.create');
        $this->employeeLogin($delegate);
        $this->get(self::HOME)->assertOk()
            ->assertSee('Open Roles &amp; Permissions', false)
            ->assertDontSee('Open User Management');
        $this->get(self::ROLES)->assertOk()
            ->assertDontSee('href="'.self::USERS.'"', false);
        $this->get(self::USERS)->assertForbidden();
    }

    public function test_ordinary_employee_does_not_gain_security_navigation(): void
    {
        $user = $this->account();
        $this->employeeLogin($user);
        $this->get(self::HOME)->assertOk()
            ->assertSee('No Security Management navigation')
            ->assertDontSee('Open User Management')
            ->assertDontSee('Open Roles &amp; Permissions', false);
        $this->get(self::USERS)->assertForbidden();
        $this->get(self::ROLES)->assertForbidden();
    }

    public function test_dev_identity_cannot_log_in_and_legacy_flag_gives_no_rights(): void
    {
        $dev = $this->account(false, true);
        $this->csrfPost(self::LOGIN, [
            'employee_id' => $dev->login_id, 'password' => self::PASSWORD,
        ])->assertUnprocessable();
        $this->getJson(self::HOME)->assertUnauthorized();

        $legacy = $this->account(false, false, true);
        $this->employeeLogin($legacy);
        $this->get(self::HOME)->assertOk()->assertSee('No Security Management navigation');
        $this->get(self::ROLES)->assertForbidden();
    }

    public function test_missing_or_invalid_csrf_cannot_create_session(): void
    {
        $user = $this->account();
        $payload = ['employee_id' => $user->login_id, 'password' => self::PASSWORD];
        $this->postJson(self::LOGIN, $payload)->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'forged-token')
            ->postJson(self::LOGIN, $payload)->assertStatus(419);
        $this->getJson(self::HOME)->assertUnauthorized();
    }

    public function test_logout_revokes_session_and_security_access(): void
    {
        $admin = $this->account(true);
        $this->employeeLogin($admin);
        $this->get(self::HOME)->assertOk();
        $this->csrfPost(self::LOGOUT, [])->assertOk()->assertJsonPath('logged_out', true);
        $this->getJson(self::HOME)->assertUnauthorized();
        $this->getJson(self::USERS)->assertUnauthorized();
    }

    public function test_local_stage_guard_blocks_production_and_disabled_stage(): void
    {
        $admin = $this->account(true);
        $this->employeeLogin($admin);
        config(['kfos_auth_stage.enabled' => false]);
        $this->get(self::HOME)->assertNotFound();
        $this->get(self::USERS)->assertNotFound();
        config(['kfos_auth_stage.enabled' => true]);
        app()->detectEnvironment(fn () => 'production');
        $this->get(self::HOME)->assertNotFound();
        $this->get(self::ROLES)->assertNotFound();
    }
}
