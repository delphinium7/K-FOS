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

final class KfosUserManagementUiPreviewTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = 'http://localhost:8080/kfos/security/ui/users';
    private const CREATE_URL = 'http://localhost:8080/kfos/security/ui/users/create';

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
            });
        // Dynamically registered test routes are added after Laravel's normal
        // boot-time name-index refresh. Refresh the route-name lookup so
        // Blade route('...') can resolve the two named POST endpoints.
        // This changes ONLY this isolated test's router, not application routes.
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->assertTrue(Route::has('kfos.security.users.store'));
        $this->assertTrue(Route::has('kfos.security.users.status.update'));
        $this->assertTrue(Route::has('kfos.security.users.ui.create'));
    }

    private function account(bool $admin = false, bool $dev = false): User
    {
        $code = 'T5AK_'.strtoupper(Str::random(14));
        $employeePk = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $code, 'name' => 'Synthetic User '. $code,
            'position' => 'Synthetic Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employeePk, 'login_id' => $code,
            'name' => 'Synthetic User '. $code, 'email' => null,
            'password' => Hash::make('Synthetic-5AK-Password-2026!'),
            'is_active' => true, 'is_system_admin' => $dev,
            'is_development_only' => $dev, 'session_version' => 1,
        ])->save();
        if ($admin) DB::table('kfos_system_administrators')->insert([
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function login(User $actor, int $version = 1): void
    {
        $this->actingAs($actor, 'web')->withSession(['kfos_session_version' => $version]);
    }

    private function grantStatusPermission(User $actor): void
    {
        $roleId = DB::table('kfos_roles')->insertGetId([
            'code' => 't5ak_'.strtolower(Str::random(15)),
            'name' => 'T5AK Status Role '.strtoupper(Str::random(12)),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('kfos_permissions')
            ->where('code', 'security.accounts.status')->value('id');
        $this->assertNotNull($permissionId);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $permissionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $actor->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_genuine_administrator_can_see_add_user_and_csrf_fields(): void
    {
        $admin = $this->account(true);
        $roleId = DB::table('kfos_roles')->insertGetId([
            'code' => 't5ak_'.strtolower(Str::random(15)),
            'name' => 'T5AK Active Role', 'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($admin);
        $this->get(self::URL)->assertOk()
            ->assertSee('Create Employee Login')
            ->assertSee('K-FOS')
            ->assertSee('Employee Login Accounts')
            ->assertDontSee('T5AK Active Role')
            ->assertDontSee('Synthetic-5AK-Password-2026!');
        $this->get(self::CREATE_URL)->assertOk()
            ->assertSee('Create Employee Login')
            ->assertSee('T5AK Active Role')
            ->assertSee('name="_token"', false)
            ->assertSee('kfos/security/users', false);
        $this->assertGreaterThan(0, $roleId);
    }

    public function test_delegate_sees_ordinary_status_targets_but_never_admin_or_add_user_form(): void
    {
        $delegate = $this->account();
        $admin = $this->account(true);
        $ordinary = $this->account();
        $this->grantStatusPermission($delegate);
        $this->login($delegate);
        $this->get(self::URL)->assertOk()
            ->assertDontSee('Create Employee Login')
            ->assertSee($ordinary->login_id)
            ->assertDontSee($admin->login_id)
            ->assertSee('Own account — no self-deactivation');
    }

    public function test_ordinary_user_without_status_permission_is_denied(): void
    {
        $this->login($this->account());
        $this->get(self::URL)->assertForbidden();
    }

    public function test_dev_identity_or_legacy_admin_flag_never_grants_real_ui_access(): void
    {
        $dev = $this->account(false, true);
        $this->login($dev);
        $this->assertNotSame(200, $this->get(self::URL)->status());
    }

    public function test_unauthenticated_user_cannot_view_employee_account_list(): void
    {
        $this->assertNotSame(200, $this->get(self::URL)->status());
    }

    public function test_stale_session_cannot_view_management_page(): void
    {
        $this->login($this->account(true), 0);
        $this->assertNotSame(200, $this->get(self::URL)->status());
    }

    public function test_administrator_can_filter_by_employee_id_and_account_status(): void
    {
        $admin = $this->account(true);
        $active = $this->account();
        $inactive = $this->account();
        DB::table('users')->where('id', $inactive->id)->update(['is_active' => false]);
        $this->login($admin);
        $this->get(self::URL.'?status=active&q='.urlencode($active->login_id))
            ->assertOk()->assertSee($active->login_id)
            ->assertDontSee($inactive->login_id)
            ->assertSee('Account status');
        $this->get(self::URL.'?status=inactive&q='.urlencode($inactive->login_id))
            ->assertOk()->assertSee($inactive->login_id)
            ->assertDontSee($active->login_id);
    }

    public function test_delegated_filters_never_expose_administrator_accounts(): void
    {
        $delegate = $this->account();
        $admin = $this->account(true);
        $this->grantStatusPermission($delegate);
        $this->login($delegate);
        $this->get(self::URL.'?q='.urlencode($admin->login_id))
            ->assertOk()
            // The requested ID is echoed back in the search field; that is
            // expected and must not be mistaken for a disclosed account row.
            ->assertSee('value="'.e($admin->login_id).'"', false)
            ->assertSee('No eligible accounts found.')
            ->assertDontSee('<td>'.e($admin->login_id).'</td>', false);
    }

    public function test_invalid_account_status_filter_is_rejected(): void
    {
        $this->login($this->account(true));
        $this->get(self::URL.'?status=unknown')->assertStatus(422);
    }

    public function test_delegate_cannot_open_the_separate_add_user_screen(): void
    {
        $delegate = $this->account();
        $this->grantStatusPermission($delegate);
        $this->login($delegate);
        $this->get(self::CREATE_URL)->assertForbidden();
        $this->get(self::URL)->assertOk()->assertDontSee('href="'.self::CREATE_URL.'"', false);
    }

    public function test_guest_and_stale_administrator_cannot_open_add_user_screen(): void
    {
        $this->assertNotSame(200, $this->get(self::CREATE_URL)->status());
        $this->login($this->account(true), 0);
        $this->assertNotSame(200, $this->get(self::CREATE_URL)->status());
    }

    public function test_development_identity_cannot_open_add_user_screen(): void
    {
        $this->login($this->account(false, true));
        $this->assertNotSame(200, $this->get(self::CREATE_URL)->status());
    }

    public function test_nonlocal_environment_cannot_access_the_preview(): void
    {
        $this->login($this->account(true));
        app()->detectEnvironment(fn () => 'production');
        $this->get(self::URL)->assertNotFound();
    }
}
