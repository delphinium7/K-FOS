<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosSessionIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private const TEST_PASSWORD =
        'Synthetic-Session-Test-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();

        // Block accidental execution against the
        // normal development database.
        $this->assertSame(
            'kfos_test',
            DB::connection()->getDatabaseName(),
            'STOP: Use DB_DATABASE=kfos_test.'
        );

        // Match the approved local development setup.
        $this->app->detectEnvironment(
            fn () => 'local'
        );

        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'kfos.allow_shared_dev_admin' => true,
            'session.driver' => 'array',
        ]);

        // CSRF is bypassed only for these automated
        // requests, never in the application itself.
        $this->withoutMiddleware(
            VerifyCsrfToken::class
        );
    }

    private function assertFixtureTransaction(): void
    {
        $this->assertSame(
            'kfos_test',
            DB::connection()->getDatabaseName()
        );

        $this->assertGreaterThan(
            0,
            DB::connection()->transactionLevel(),
            'STOP: Fixture changes require an active transaction.'
        );
    }

    private function createAdministrator(): User
    {
        $this->assertFixtureTransaction();

        $user = User::query()
            ->where('login_id', 'KFOS_ADMIN')
            ->first();

        if ($user === null) {
            $user = new User();
        }

        // Test changes are protected by DatabaseTransactions.
        $user->forceFill([
            'login_id' => 'KFOS_ADMIN',
            'name' => 'Synthetic Administrator',
            'email' => null,
            'employee_id' => null,
            'password' => Hash::make(self::TEST_PASSWORD),
            'is_active' => true,
            'is_system_admin' => true,
            'is_development_only' => true,
            'session_version' => 1,
        ])->save();

        return $user;
    }

    private function createOrdinaryUser(): User
    {
        $this->assertFixtureTransaction();

        $loginId = 'T5T_'.strtoupper(Str::random(12));
        $employeeId = DB::table('employees')
            ->insertGetId([
                'employee_id' => $loginId,
                'name' => 'Synthetic Test Operator',
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

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'login_id' => $loginId,
            'name' => 'Synthetic Test Operator',
            'email' => null,
            'password' => Hash::make(
                self::TEST_PASSWORD
            ),
        ]);

        $user->forceFill([
            'is_active' => true,
            'is_system_admin' => false,
            'is_development_only' => true,
            'session_version' => 1,
        ])->save();

        return $user;
    }

    private function loginAsAdministrator(): User
    {
        $admin = $this->createAdministrator();

        // Exercise the actual login controller.
        $this->post(
            'http://localhost:8080/kfos-auth-dev/login',
            [
                'employee_id' => 'KFOS_ADMIN',
                'password' => self::TEST_PASSWORD,
            ]
        )->assertRedirect(
            route('kfos.auth.dev.home')
        );

        $this->getJson(
            'http://localhost:8080/kfos-auth-dev/home'
        )->assertOk();

        return $admin;
    }

    public function test_deactivated_account_loses_access_and_old_session_does_not_return(): void
    {
        $admin = $this->loginAsAdministrator();

        // Simulate a database-side status change.
        // This is test-only setup, not a permitted
        // administrator self-deactivation workflow.
        DB::table('users')
            ->where('id', $admin->id)
            ->update([
                'is_active' => false,
                'session_version' => 2,
                'updated_at' => now(),
            ]);

        // Make Laravel retrieve the user afresh
        // instead of using its cached guard object.
        Auth::forgetGuards();

        $this->getJson(
            'http://localhost:8080/kfos-auth-dev/home'
        )->assertForbidden();

        // Reactivating the database record must
        // not restore the terminated old session.
        DB::table('users')
            ->where('id', $admin->id)
            ->update([
                'is_active' => true,
                'session_version' => 3,
                'updated_at' => now(),
            ]);

        Auth::forgetGuards();

        $this->getJson(
            'http://localhost:8080/kfos-auth-dev/home'
        )->assertUnauthorized();
    }

    public function test_changed_session_version_rejects_existing_login(): void
    {
        $admin = $this->loginAsAdministrator();

        DB::table('users')
            ->where('id', $admin->id)
            ->update([
                'session_version' => 2,
                'updated_at' => now(),
            ]);

        Auth::forgetGuards();

        $this->getJson(
            'http://localhost:8080/kfos-auth-dev/home'
        )->assertForbidden();
    }

    public function test_ordinary_user_cannot_access_user_management(): void
    {
        $user = $this->createOrdinaryUser();

        $this->actingAs($user, 'web')
            ->withSession([
                'kfos_session_version' => 1,
            ]);

        $this->getJson(
            'http://localhost:8080/kfos-auth-dev/users'
        )->assertForbidden();

        $this->postJson(
            'http://localhost:8080/kfos-auth-dev/users/1/status',
            [
                'status' => 'inactive',
                'reason' => 'Unauthorized test',
            ]
        )->assertForbidden();
    }
}
