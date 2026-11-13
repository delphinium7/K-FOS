<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\KfosRememberBrowserController;
use App\Http\Middleware\KfosEpochSessionGate;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Synthetic kfos_test only: opt-in gates are NOT mounted in production routes.
 * These tests do not simulate authoritative HR commits or audit recovery.
 */
final class KfosStep8EpochAdmissionTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Step8-Current-Password-2026!';
    private const BASE = 'http://localhost:8080/__kfos_step8';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        $this->assertTrue(Schema::hasColumn('kfos_remembered_devices', 'security_epoch_at_issue'));
        $this->assertTrue(Schema::hasTable('kfos_account_security_epochs'));
        app()->detectEnvironment(fn () => 'local');
        config([
            'kfos_step8.epoch_admission_enabled' => true,
            'app.debug' => true, 'app.url' => 'http://localhost:8080',
            'session.driver' => 'array',
        ]);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Route::middleware('web')->post('/__kfos_step8/login',
            [KfosRememberBrowserController::class, 'login']);
        Route::middleware(['web', 'auth:web', KfosEpochSessionGate::class])
            ->get('/__kfos_step8/protected', fn () => response()->json(['allowed' => true]));
    }

    private function account(bool $masterActive = true, bool $admin = false): User
    {
        $employeeId = 'S8_'.strtoupper(Str::random(16));
        $pk = DB::table('employees')->insertGetId([
            'employee_id' => $employeeId,
            'name' => 'Synthetic Epoch Test', 'position' => 'Synthetic Operator',
            'is_active' => $masterActive, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'employee_id' => $pk, 'login_id' => $employeeId,
            'name' => 'Synthetic Epoch Test', 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'is_development_only' => false,
            'is_system_admin' => false, 'session_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return User::findOrFail($userId);
    }

    private function epoch(User $account, int $generation = 4, bool $pending = false): void
    {
        DB::table('kfos_account_security_epochs')->insert([
            'user_id' => $account->id,
            'epoch' => $generation,
            'revocation_pending' => $pending,
            'original_hr_event_reference' => $pending ? 'SYNTHETIC_EVENT' : null,
            'barrier_set_at' => $pending ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(User $account, bool $remember = false): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::BASE.'/login', [
            'employee_id' => $account->login_id,
            'password' => self::PASSWORD,
            'remember_me' => $remember,
        ]);
    }

    public function test_missing_epoch_blocks_new_login_without_success_audit(): void
    {
        $user = $this->account();
        $this->login($user, true)->assertUnprocessable();
        $this->assertGuest('web');
        $this->assertDatabaseMissing('kfos_remembered_devices', ['user_id' => $user->id]);
        $this->assertDatabaseHas('security_audit_logs', [
            'target_user_id' => $user->id,
            'event_type' => 'employee_login_session_issuance_denied',
            'result' => 'failed',
        ]);
        $this->assertDatabaseMissing('security_audit_logs', [
            'target_user_id' => $user->id,
            'event_type' => 'employee_credential_login_success',
        ]);
    }

    public function test_pending_barrier_blocks_new_login_and_remember_grant(): void
    {
        $user = $this->account();
        $this->epoch($user, 6, true);
        $this->login($user, true)->assertUnprocessable();
        $this->assertGuest('web');
        $this->assertDatabaseMissing('kfos_remembered_devices', ['user_id' => $user->id]);
    }

    public function test_fresh_epoch_is_bound_to_session_and_protected_request(): void
    {
        $user = $this->account();
        $this->epoch($user, 7);
        $this->login($user)->assertOk()->assertJsonPath('authenticated', true);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(7, (int) session('kfos_security_epoch'));
        $this->getJson(self::BASE.'/protected')->assertOk()->assertJsonPath('allowed', true);
    }

    public function test_epoch_increment_denies_old_session_even_after_employment_restored(): void
    {
        $user = $this->account();
        $this->epoch($user, 1);
        $this->login($user)->assertOk();
        DB::table('employees')->where('id', $user->employee_id)
            ->update(['is_active' => false, 'updated_at' => now()]);
        DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
            ->update(['epoch' => 2, 'updated_at' => now()]);
        DB::table('employees')->where('id', $user->employee_id)
            ->update(['is_active' => true, 'updated_at' => now()]);
        Auth::forgetGuards();
        $this->getJson(self::BASE.'/protected')->assertForbidden();
    }

    public function test_pending_barrier_denies_existing_session(): void
    {
        $user = $this->account();
        $this->epoch($user, 1);
        $this->login($user)->assertOk();
        DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
            ->update(['revocation_pending' => true, 'updated_at' => now()]);
        Auth::forgetGuards();
        $this->getJson(self::BASE.'/protected')->assertForbidden();
    }

    public function test_remembered_grant_binds_epoch_and_old_grant_fails_after_increment(): void
    {
        $user = $this->account();
        $this->epoch($user, 4);
        $remembered = app(KfosRememberedDeviceService::class);
        $token = $remembered->issueAfterCredentialLogin($user, true);
        $this->assertNotNull($token);
        $this->assertDatabaseHas('kfos_remembered_devices', [
            'user_id' => $user->id, 'security_epoch_at_issue' => 4,
        ]);
        $this->assertNotNull($remembered->validate($token));
        DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
            ->update(['epoch' => 5, 'updated_at' => now()]);
        $this->assertNull($remembered->validate($token));
    }

    public function test_restoration_bound_epoch_is_original_not_current_generation(): void
    {
        $user = $this->account();
        $this->epoch($user, 8);
        $remembered = app(KfosRememberedDeviceService::class);
        $token = $remembered->issueAfterCredentialLogin($user, true);
        $this->assertNotNull($token);
        $this->assertSame(8, $remembered->boundEpochForValidToken($token));
        DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
            ->update(['epoch' => 9, 'updated_at' => now()]);
        $this->assertNull($remembered->boundEpochForValidToken($token));
    }

    public function test_legacy_remembered_grant_is_not_silently_promoted(): void
    {
        $user = $this->account();
        $this->epoch($user, 1);
        config(['kfos_step8.epoch_admission_enabled' => false]);
        $remembered = app(KfosRememberedDeviceService::class);
        $token = $remembered->issueAfterCredentialLogin($user, true);
        $this->assertNotNull($token);
        config(['kfos_step8.epoch_admission_enabled' => true]);
        $this->assertNull($remembered->validate($token));
    }

    public function test_admin_inactive_employment_exception_still_requires_epoch(): void
    {
        $user = $this->account(false, true);
        $this->epoch($user, 2);
        $this->login($user)->assertOk();
        $this->getJson(self::BASE.'/protected')->assertOk();
        DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
            ->update(['epoch' => 3, 'updated_at' => now()]);
        Auth::forgetGuards();
        $this->getJson(self::BASE.'/protected')->assertForbidden();
    }

    public function test_ordinary_inactive_employment_cannot_issue_with_epoch(): void
    {
        $user = $this->account(false);
        $this->epoch($user);
        $this->login($user)->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_gate_requires_opt_in_even_if_mounted_by_mistake(): void
    {
        $user = $this->account();
        $this->epoch($user);
        $this->login($user)->assertOk();
        config(['kfos_step8.epoch_admission_enabled' => false]);
        $this->getJson(self::BASE.'/protected')->assertStatus(503);
    }
}
