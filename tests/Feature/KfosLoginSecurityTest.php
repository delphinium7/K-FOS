<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KfosLoginSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD =
        'Synthetic-Login-Test-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();

        // Safety check BEFORE making any database writes.
        $this->assertSame(
            'kfos_test',
            DB::connection()->getDatabaseName(),
            'STOP: Testing database must be kfos_test.'
        );

        $this->app->detectEnvironment(
            fn () => 'local'
        );

        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'kfos.allow_shared_dev_admin' => true,
            'session.driver' => 'array',
        ]);

        // Local-development routes are normally mounted at Laravel boot,
        // before PHPUnit setUp switches its environment. Register ONLY the
        // existing development contract in this isolated test router.
        // This does not change application routes or production activation.
        if (! Route::has('kfos.auth.dev.home')) {
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
                    // Dev home template references these existing named routes.
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

        // Only bypass CSRF within this test class.
        $this->withoutMiddleware(
            VerifyCsrfToken::class
        );

        foreach (['KFOS_ADMIN', 'UNKNOWN_TEST_ID'] as $id) {
            RateLimiter::clear(
                'kfos-dev-login:' . hash(
                    'sha256',
                    '127.0.0.1|' . $id
                )
            );
        }
    }

    protected function tearDown(): void
    {
        foreach (['KFOS_ADMIN', 'UNKNOWN_TEST_ID'] as $id) {
            RateLimiter::clear(
                'kfos-dev-login:' . hash(
                    'sha256',
                    '127.0.0.1|' . $id
                )
            );
        }

        parent::tearDown();
    }

    private function createAdmin(bool $active = true): User
    {
        // The persistent kfos_test database may already contain the original
        // local KFOS_ADMIN identity. Reuse its record transactionally instead
        // of violating users_login_id_unique with a duplicate fixture.
        // DatabaseTransactions restores its original state after each test.
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        $admin = User::query()->where('login_id', 'KFOS_ADMIN')->first();
        if ($admin === null) {
            $admin = new User();
        }

        $admin->forceFill([
            'login_id' => 'KFOS_ADMIN',
            'employee_id' => null,
            'name' => 'Synthetic Administrator',
            'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => $active,
            'is_system_admin' => true,
            'is_development_only' => true,
            'session_version' => 1,
        ]);

        $admin->save();

        return $admin;
    }

    private function submitLogin(
        string $id,
        string $password
    ) {
        return $this
            ->withServerVariables([
                'REMOTE_ADDR' => '127.0.0.1',
            ])
            ->post(
                'http://localhost:8080/kfos-auth-dev/login',
                [
                    'employee_id' => $id,
                    'password' => $password,
                ]
            );
    }

    public function test_successful_credential_login_is_audited(): void
    {
        $admin = $this->createAdmin();

        $this->submitLogin(
            'KFOS_ADMIN',
            self::PASSWORD
        )->assertRedirect(
            route('kfos.auth.dev.home')
        );

        $this->assertAuthenticatedAs($admin, 'web');

        $this->assertSame(
            1,
            (int) session('kfos_session_version')
        );

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'actor_user_id' => $admin->id,
                'target_user_id' => $admin->id,
                'event_type' => 'dev_login_success',
                'result' => 'success',
                'is_shared_dev_identity' => true,
            ]
        );
    }

    public function test_wrong_password_is_denied_and_audited(): void
    {
        $admin = $this->createAdmin();

        $this->submitLogin(
            'KFOS_ADMIN',
            'Incorrect-Test-Password!'
        )->assertSessionHasErrors([
            'login' => 'Invalid Employee ID or password.',
        ]);

        $this->assertGuest('web');

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'target_user_id' => $admin->id,
                'event_type' => 'dev_login_failed',
                'result' => 'failed',
            ]
        );
    }

    public function test_unknown_id_is_denied_and_audited(): void
    {
        $this->submitLogin(
            'UNKNOWN_TEST_ID',
            'Incorrect-Test-Password!'
        )->assertSessionHasErrors([
            'login' => 'Invalid Employee ID or password.',
        ]);

        $this->assertGuest('web');

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'target_user_id' => null,
                'event_type' => 'dev_login_unknown_id',
                'result' => 'failed',
            ]
        );
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = $this->createAdmin(false);

        $this->submitLogin(
            'KFOS_ADMIN',
            self::PASSWORD
        )->assertSessionHasErrors([
            'login' => 'Invalid Employee ID or password.',
        ]);

        $this->assertGuest('web');

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'target_user_id' => $admin->id,
                'event_type' => 'dev_login_failed',
                'result' => 'failed',
            ]
        );
    }

    public function test_temporary_rate_limit_denies_sixth_attempt(): void
    {
        $this->createAdmin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->submitLogin(
                'KFOS_ADMIN',
                'Incorrect-Test-Password!'
            )->assertSessionHasErrors('login');
        }

        $this->submitLogin(
            'KFOS_ADMIN',
            self::PASSWORD
        )->assertSessionHasErrors([
            'login' => 'Invalid Employee ID or password.',
        ]);

        $this->assertGuest('web');

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'event_type' => 'dev_login_rate_limited',
                'result' => 'failed',
            ]
        );
    }
}
