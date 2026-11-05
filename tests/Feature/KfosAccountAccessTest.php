<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KfosAccountAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated test configuration.
        // No production or development DB changes.
        $this->app->detectEnvironment(
            fn () => 'local'
        );

        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'kfos.allow_shared_dev_admin' => true,
            'session.driver' => 'array',
        ]);

        // Temporary test-only route.
        // It is NOT added to routes/web.php.
        Route::middleware([
            'web',
            'auth',
            'kfos.account',
        ])->get(
            '/__kfos_test/account-access',
            function () {
                return response()->json([
                    'access' => 'allowed',
                ]);
            }
        );
    }

    private function makeTestUser(): User
    {
        $user = new User();

        $user->forceFill([
            'id' => 999999,
            'login_id' => 'KFOS_TEST_SESSION',
            'name' => 'Synthetic Session Test',
            'is_active' => true,
            'is_system_admin' => false,
            'is_development_only' => true,
            'session_version' => 1,
            'remember_token' => null,
        ]);

        return $user;
    }

    private function visitProtectedPage()
    {
        return $this->getJson(
            'http://localhost:8080/__kfos_test/account-access'
        );
    }

    public function test_active_account_with_valid_session_is_allowed(): void
    {
        $user = $this->makeTestUser();

        $this->actingAs($user, 'web')
            ->withSession([
                'kfos_session_version' => 1,
            ]);

        $this->visitProtectedPage()
            ->assertOk()
            ->assertJson([
                'access' => 'allowed',
            ]);
    }

    public function test_inactive_account_is_denied(): void
    {
        $user = $this->makeTestUser();

        $this->actingAs($user, 'web')
            ->withSession([
                'kfos_session_version' => 1,
            ]);

        // Simulate account deactivation.
        $user->is_active = false;

        $this->visitProtectedPage()
            ->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_outdated_session_version_is_denied(): void
    {
        $user = $this->makeTestUser();

        $this->actingAs($user, 'web')
            ->withSession([
                'kfos_session_version' => 1,
            ]);

        // Simulate a status change that increments
        // the account's session version.
        $user->session_version = 2;

        $this->visitProtectedPage()
            ->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_missing_session_version_is_denied(): void
    {
        $user = $this->makeTestUser();

        $this->actingAs($user, 'web');

        $this->visitProtectedPage()
            ->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_development_account_is_denied_when_flag_disabled(): void
    {
        $user = $this->makeTestUser();

        $this->actingAs($user, 'web')
            ->withSession([
                'kfos_session_version' => 1,
            ]);

        config([
            'kfos.allow_shared_dev_admin' => false,
        ]);

        $this->visitProtectedPage()
            ->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->visitProtectedPage()
            ->assertUnauthorized();
    }
}
