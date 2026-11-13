<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosUserManagementIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable CSRF only for automated test requests.
        // Real browser requests retain CSRF protection.
        $this->withoutMiddleware(
          \App\Http\Middleware\VerifyCsrfToken::class
);

        // Never run these database-writing tests
        // against the normal development database.
        $this->assertSame(
            'kfos_test',
            DB::connection()->getDatabaseName(),
            'STOP: This test requires the kfos_test database.'
        );

        // PHPUnit starts with APP_ENV=testing. The old test exercises the
        // existing local-only development controller, so simulate local only
        // within this test application. Never alter app routes or production.
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'kfos.allow_shared_dev_admin' => true,
            'session.driver' => 'array',
        ]);

        // routes/web.php was evaluated before setUp(), while env was testing.
        // Register ONLY the existing development routes in PHPUnit's isolated
        // in-memory router, mirroring KfosSessionIntegrationTest.
        if (! Route::has('kfos.auth.dev.users.index')) {
            Route::middleware('web')->group(static function (): void {
                Route::get('/kfos-auth-dev/login',
                    [\App\Http\Controllers\Auth\KfosDevLoginController::class, 'show'])
                    ->name('kfos.auth.dev.login');
                Route::post('/kfos-auth-dev/login',
                    [\App\Http\Controllers\Auth\KfosDevLoginController::class, 'login'])
                    ->name('kfos.auth.dev.submit');
                Route::middleware(['auth:web', 'kfos.account'])->group(static function (): void {
                    Route::get('/kfos-auth-dev/home',
                        [\App\Http\Controllers\Auth\KfosDevLoginController::class, 'home'])
                        ->name('kfos.auth.dev.home');
                    Route::post('/kfos-auth-dev/logout',
                        [\App\Http\Controllers\Auth\KfosDevLoginController::class, 'logout'])
                        ->name('kfos.auth.dev.logout');
                    Route::get('/kfos-auth-dev/users/create',
                        [\App\Http\Controllers\Admin\KfosDevUserController::class, 'create'])
                        ->name('kfos.auth.dev.users.create');
                    Route::post('/kfos-auth-dev/users',
                        [\App\Http\Controllers\Admin\KfosDevUserController::class, 'store'])
                        ->name('kfos.auth.dev.users.store');
                    Route::get('/kfos-auth-dev/users',
                        [\App\Http\Controllers\Admin\KfosDevAccountStatusController::class, 'index'])
                        ->name('kfos.auth.dev.users.index');
                    Route::post('/kfos-auth-dev/users/{user}/status',
                        [\App\Http\Controllers\Admin\KfosDevAccountStatusController::class, 'update'])
                        ->whereNumber('user')->name('kfos.auth.dev.users.status');
                });
            });
            app('router')->getRoutes()->refreshNameLookups();
        }
        $this->assertTrue(app()->environment('local'));
        $this->assertTrue(Route::has('kfos.auth.dev.users.index'));
        $this->assertTrue(Route::has('kfos.auth.dev.users.status'));
        $this->assertTrue(Route::has('kfos.auth.dev.users.store'));
    }

    private function createAdmin(): User
    {
        // kfos_test may already contain the single KFOS_ADMIN development
        // account. Never insert another account with the same login ID.
        // DatabaseTransactions restores all test-only mutations afterward.
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        $admin = User::query()->where('login_id', 'KFOS_ADMIN')->first();
        if ($admin === null) {
            $admin = new User();
        }
        return tap($admin->forceFill([
            'login_id' => 'KFOS_ADMIN',
            'name' => 'Synthetic Development Admin',
            'email' => null,
            'employee_id' => null,
            'password' => Hash::make(Str::random(32)),
        ])->forceFill([
            'is_active' => true,
            'is_system_admin' => true,
            'is_development_only' => true,
            'session_version' => 1,
        ]), function (User $user) {
            $user->save();
        });
    }

    private function createTestEmployee(): User
    {
        $loginId = 'AUTO_' . strtoupper(Str::random(12));

        $employeeId = DB::table('employees')
            ->insertGetId([
                'employee_id' => $loginId,
                'name' => 'Synthetic Test Employee',
                'position' => 'Test Operator',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('employee_id_reservations')->insert([
            'employee_id' => $employeeId,
            'reserved_employee_id' => $loginId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return tap(User::query()->create([
            'employee_id' => $employeeId,
            'login_id' => $loginId,
            'name' => 'Synthetic Test Employee',
            'email' => null,
            'password' => Hash::make(Str::random(32)),
        ])->forceFill([
            'is_active' => true,
            'is_system_admin' => false,
            'is_development_only' => true,
            'session_version' => 1,
        ]), function (User $user) {
            $user->save();
        });
    }

    private function loginAsAdmin(User $admin): void
    {
        $this->actingAs($admin, 'web')
            ->withSession([
                'kfos_session_version' =>
                    (int) $admin->session_version,
            ]);
    }

    public function test_account_lifecycle_and_audit(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createTestEmployee();

        $this->loginAsAdmin($admin);

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users/'
                . $target->id . '/status',
            [
                'status' => 'inactive',
                'reason' => 'Automated deactivation test',
            ]
        )->assertRedirect(
            route('kfos.auth.dev.users.index')
        );

        $target->refresh();

        $this->assertFalse($target->is_active);
        $this->assertSame(2, $target->session_version);

        $this->assertDatabaseHas(
            'employees',
            [
                'id' => $target->employee_id,
                'is_active' => true,
            ]
        );

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'actor_user_id' => $admin->id,
                'target_user_id' => $target->id,
                'event_type' => 'dev_user_deactivated',
                'result' => 'success',
                'is_shared_dev_identity' => true,
            ]
        );

        $this->assertNotNull($target->remember_token);

        $tokenAfterDeactivation = $target->remember_token;

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users/'
                . $target->id . '/status',
            [
                'status' => 'active',
                'reason' => 'Automated reactivation test',
            ]
        )->assertRedirect(
            route('kfos.auth.dev.users.index')
        );

        $target->refresh();

        $this->assertTrue($target->is_active);
        $this->assertSame(3, $target->session_version);

        $this->assertNotSame(
            $tokenAfterDeactivation,
            $target->remember_token
        );

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'actor_user_id' => $admin->id,
                'target_user_id' => $target->id,
                'event_type' => 'dev_user_activated',
                'result' => 'success',
            ]
        );
    }

    public function test_duplicate_employee_id_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createTestEmployee();

        $this->loginAsAdmin($admin);

        $before = DB::table('employees')->count();

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users',
            [
                'employee_id' => $target->login_id,
                'name' => 'Duplicate Test',
                'position' => 'Test Operator',
                'password' => 'Temporary-Synthetic-Password-123!',
                'password_confirmation' =>
                    'Temporary-Synthetic-Password-123!',
            ]
        )->assertSessionHasErrors('employee_id');

        $this->assertSame(
            $before,
            DB::table('employees')->count()
        );
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->createAdmin();

        $this->loginAsAdmin($admin);

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users/'
                . $admin->id . '/status',
            [
                'status' => 'inactive',
                'reason' => 'Prohibited self-deactivation test',
            ]
        )->assertForbidden();

        $admin->refresh();

        $this->assertTrue($admin->is_active);
    }
}
