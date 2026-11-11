<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KfosAuthStageLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Stage-Test-2026!';
    private const BASE = 'http://localhost:8080/kfos-auth-stage';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * DATABASE SAFETY
         *
         * Stop immediately if the database is not kfos_test.
         */
        $this->assertSame(
            'kfos_test',
            DB::connection()->getDatabaseName(),
            'STOP: Tests must use kfos_test'
        );

        /*
         * LOCAL STAGING ENVIRONMENT
         *
         * Simulate local environment only within
         * this PHPUnit test application.
         *
         * The original staging guard is unchanged.
         */
        app()->instance('env', 'local');

        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'kfos_auth_stage.enabled' => true,
            'session.driver' => 'array',
        ]);

        /*
         * HTTP TEST CONFIGURATION
         *
         * Allow JSON test requests to transmit cookies.
         */
        $this->withCredentials();

        /*
         * CSRF TEST EXCEPTION
         *
         * Disable CSRF verification only for this
         * PHPUnit test class.
         *
         * Real HTTP requests remain CSRF protected.
         */
        $this->withoutMiddleware(
            VerifyCsrfToken::class
        );

        /*
         * ACTUAL STAGING ROUTES
         *
         * Load the existing staging route definitions
         * without modifying routes/web.php.
         */
        Route::middleware('web')->group(function (): void {
            require base_path('routes/kfos-auth-stage.php');
        });
    }

    /*
     * CREATE SYNTHETIC EMPLOYEE
     *
     * DatabaseTransactions rolls back the records
     * after each test.
     */
    private function employee(string $employeeId): User
    {
        $employeePk = DB::table('employees')->insertGetId([
            'employee_id' => $employeeId,
            'name' => 'Synthetic '.$employeeId,
            'position' => 'Test Operator',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User();

        $user->forceFill([
            'employee_id' => $employeePk,
            'login_id' => $employeeId,
            'name' => 'Synthetic '.$employeeId,
            'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'is_system_admin' => false,
            'is_development_only' => false,
            'session_version' => 1,
        ])->save();

        return $user;
    }

    /*
     * TEST 1
     *
     * Successful login, authenticated session
     * and explicit logout.
     */
    public function test_staging_login_session_and_logout(): void
    {
        $user = $this->employee('STAGE_LIFECYCLE_01');

        // Access without login must fail.
        $this->getJson(self::BASE.'/session')
            ->assertUnauthorized();

        // Login using synthetic employee credentials.
        $this->postJson(self::BASE.'/login', [
            'employee_id' => $user->login_id,
            'password' => self::PASSWORD,
            'remember_me' => false,
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('remembered', false);

        // Authenticated session must be available.
        $this->getJson(self::BASE.'/session')
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user_id', $user->id);

        // Explicit logout.
        $this->postJson(self::BASE.'/logout')
            ->assertOk()
            ->assertJsonPath('logged_out', true);

        // Previous session must no longer work.
        $this->getJson(self::BASE.'/session')
            ->assertUnauthorized();
    }

    /*
     * TEST 2
     *
     * Invalid credentials must receive the
     * approved generic error message.
     */
    public function test_invalid_credentials_are_rejected(): void
    {
        $user = $this->employee('STAGE_LIFECYCLE_02');

        $this->postJson(self::BASE.'/login', [
            'employee_id' => $user->login_id,
            'password' => 'Incorrect-Synthetic-Password!',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Invalid Employee ID or password.'
            );

        // No authenticated session should exist.
        $this->getJson(self::BASE.'/session')
            ->assertUnauthorized();
    }

    /*
     * TEST 3
     *
     * Remember me opt-in must create a
     * remembered-device grant.
     */
    public function test_remember_me_opt_in_creates_a_grant(): void
    {
        $user = $this->employee('STAGE_LIFECYCLE_03');

        $this->postJson(self::BASE.'/login', [
            'employee_id' => $user->login_id,
            'password' => self::PASSWORD,
            'remember_me' => true,
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('remembered', true);

        // Exactly one remembered-device grant.
        $this->assertSame(
            1,
            DB::table('kfos_remembered_devices')
                ->where('user_id', $user->id)
                ->count()
        );
    }
}
