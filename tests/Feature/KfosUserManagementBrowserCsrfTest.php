<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unlike normal Laravel feature tests, DO NOT bypass VerifyCsrfToken.
 * Override only Laravel's testing-environment exemption, never the actual
 * token comparison or any production middleware. Synthetic users only.
 */
final class KfosUserManagementBrowserCsrfTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = 'http://localhost:8080/kfos/security/users';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8080']);

        $this->app->bind(VerifyCsrfToken::class, static function ($app) {
            return new class($app, $app->make(Encrypter::class)) extends VerifyCsrfToken {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });

        // Isolated route loading; local opt-in mount is OFF in this test run.
        require base_path('routes/kfos-security-users.php');
    }

    private function employee(bool $admin): User
    {
        $code = 'T5AI_'.strtoupper(Str::random(14));
        $pk = DB::table('employees')->insertGetId([
            'employee_id' => $code,
            'name' => 'Synthetic CSRF Test Employee',
            'position' => 'Synthetic Test Position',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $pk, 'login_id' => $code,
            'name' => 'Synthetic CSRF Test Employee', 'email' => null,
            'password' => Hash::make('Synthetic-CSRF-Test-Password-2026!'),
            'is_active' => true, 'is_system_admin' => false,
            'is_development_only' => false, 'session_version' => 1,
        ])->save();
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function loggedIn(User $actor, string $token, int $version = 1): void
    {
        $this->actingAs($actor, 'web')
            ->withSession(['_token' => $token, 'kfos_session_version' => $version]);
    }

    public function test_missing_csrf_header_is_rejected_before_validation(): void
    {
        $this->loggedIn($this->employee(true), Str::random(40));
        $this->postJson(self::URL, [])->assertStatus(419);
    }

    public function test_invalid_csrf_header_is_rejected(): void
    {
        $this->loggedIn($this->employee(true), Str::random(40));
        $this->withHeader('X-CSRF-TOKEN', 'synthetic-forged-token')
            ->postJson(self::URL, [])->assertStatus(419);
    }

    public function test_valid_csrf_header_reaches_normal_request_validation(): void
    {
        $token = Str::random(40);
        $this->loggedIn($this->employee(true), $token);
        $this->withHeader('X-CSRF-TOKEN', $token)
            ->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_valid_csrf_does_not_replace_authentication(): void
    {
        $token = Str::random(40);
        $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson(self::URL, [])->assertUnauthorized();
    }

    public function test_valid_csrf_does_not_override_stale_session(): void
    {
        $token = Str::random(40);
        $this->loggedIn($this->employee(true), $token, 0);
        $this->withHeader('X-CSRF-TOKEN', $token)
            ->postJson(self::URL, [])->assertForbidden();
    }
}
