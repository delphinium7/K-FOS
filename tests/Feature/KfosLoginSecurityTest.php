<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
        $admin = new User();

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
