<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\KfosEmployeeCredentialController;
use App\Http\Controllers\Auth\KfosRememberBrowserController;
use App\Http\Middleware\KfosFreshSessionGate;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use App\Services\Auth\KfosRememberedDeviceService;
use App\Services\Security\KfosAuthorization;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Test-only HTTP routes. The application does not acquire production routes. */
final class KfosFinalSessionEligibilityTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Final-Eligibility-2026!';
    private const BASE = 'http://localhost:8080/__kfos_final_eligibility';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        app()->detectEnvironment(fn () => 'local');
        config(['app.debug' => true, 'app.url' => 'http://localhost:8080', 'session.driver' => 'array']);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Route::middleware('web')->post('/__kfos_final_eligibility/remember',
            [KfosRememberBrowserController::class, 'login']);
        Route::middleware('web')->post('/__kfos_final_eligibility/basic',
            [KfosEmployeeCredentialController::class, 'store']);
        Route::middleware(['web', 'auth:web', 'kfos.account', KfosFreshSessionGate::class])
            ->get('/__kfos_final_eligibility/protected', fn () => response()->json(['allowed' => true]));
    }

    private function makeEmployee(bool $masterActive = true, bool $legacyAdminFlag = false, bool $genuineAdmin = false): User
    {
        $id = 'T6FIX_'.strtoupper(Str::random(14));
        $masterId = DB::table('employees')->insertGetId([
            'employee_id' => $id, 'name' => 'Synthetic '.$id,
            'position' => 'Synthetic Test', 'is_active' => $masterActive,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $u = new User();
        $u->forceFill([
            'employee_id' => $masterId, 'login_id' => $id,
            'name' => 'Synthetic '.$id, 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'is_development_only' => false,
            'is_system_admin' => $legacyAdminFlag, 'session_version' => 1,
        ])->save();
        if ($genuineAdmin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $u;
    }

    private function signIn(User $user, bool $remember = true): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::BASE.'/remember', [
            'employee_id' => $user->login_id, 'password' => self::PASSWORD,
            'remember_me' => $remember,
        ]);
    }

    public function test_inactive_original_employment_denies_ordinary_session_and_remember_grant(): void
    {
        $ordinary = $this->makeEmployee(false);
        $this->signIn($ordinary)->assertStatus(422)
            ->assertJsonPath('message', 'Invalid Employee ID or password.');
        $this->assertGuest('web');
        $this->assertDatabaseMissing('kfos_remembered_devices', ['user_id' => $ordinary->id]);
        $this->assertDatabaseHas('security_audit_logs', [
            'target_user_id' => $ordinary->id,
            'event_type' => 'employee_login_session_issuance_denied', 'result' => 'failed',
        ]);
        $this->assertFalse(DB::table('security_audit_logs')
            ->where('target_user_id', $ordinary->id)
            ->where('event_type', 'employee_credential_login_success')->exists());
        $this->assertSame(0, (int) DB::table('kfos_login_security_states')
            ->where('user_id', $ordinary->id)->value('failed_attempts'));
    }

    public function test_legacy_admin_boolean_does_not_bypass_inactive_employment(): void
    {
        $legacy = $this->makeEmployee(false, true, false);
        $this->signIn($legacy)->assertStatus(422);
        $this->assertGuest('web');
        $this->assertFalse(app(KfosAuthorization::class)->isSystemAdministrator($legacy));
    }

    public function test_genuine_admin_with_inactive_employment_keeps_authorized_access(): void
    {
        $admin = $this->makeEmployee(false, false, true);
        $this->signIn($admin)->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('remembered', true);
        $this->assertAuthenticatedAs($admin, 'web');
        $authorization = app(KfosAuthorization::class);
        $this->assertTrue($authorization->isSystemAdministrator($admin));
        $this->assertTrue($authorization->allows($admin, 'security.roles.create'));
        $this->assertTrue($authorization->allows($admin, 'security.accounts.status'));
        $this->getJson(self::BASE.'/protected')->assertOk()->assertJsonPath('allowed', true);
        $this->assertSame(1, DB::table('security_audit_logs')
            ->where('target_user_id', $admin->id)
            ->where('event_type', 'employee_credential_login_success')->count());
    }

    public function test_remembered_grant_refuses_reuse_after_employment_inactivation(): void
    {
        $ordinary = $this->makeEmployee();
        $remembered = app(KfosRememberedDeviceService::class);
        $token = $remembered->issueAfterCredentialLogin($ordinary, true);
        $this->assertNotNull($token);
        $this->assertNotNull($remembered->validate($token));
        DB::table('employees')->where('id', $ordinary->employee_id)->update([
            'is_active' => false, 'updated_at' => now(),
        ]);
        $this->assertNull($remembered->validate($token));
        $this->assertNull($remembered->issueAfterCredentialLogin($ordinary, true));
    }

    public function test_inactive_employment_admin_remembered_grant_requires_current_membership(): void
    {
        $admin = $this->makeEmployee(false, false, true);
        $remembered = app(KfosRememberedDeviceService::class);
        $token = $remembered->issueAfterCredentialLogin($admin, true);
        $this->assertNotNull($token);
        $this->assertNotNull($remembered->validate($token));
        DB::table('kfos_system_administrators')->where('user_id', $admin->id)->delete();
        $this->assertNull($remembered->validate($token));
    }

    public function test_genuine_admin_membership_removed_after_login_denies_protected_access(): void
    {
        $admin = $this->makeEmployee(false, false, true);
        $this->signIn($admin, false)->assertOk();
        DB::table('kfos_system_administrators')->where('user_id', $admin->id)->delete();
        Auth::forgetGuards();
        $this->getJson(self::BASE.'/protected')->assertForbidden();
    }

    public function test_basic_credential_http_adapter_also_denies_inactive_ordinary(): void
    {
        $ordinary = $this->makeEmployee(false);
        $this->postJson(self::BASE.'/basic', [
            'employee_id' => $ordinary->login_id, 'password' => self::PASSWORD,
        ])->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_direct_credential_policy_denies_correct_password_for_inactive_ordinary(): void
    {
        $ordinary = $this->makeEmployee(false);
        $this->assertNull(app(KfosEmployeeLoginSecurity::class)
            ->verifyCredentials($ordinary->login_id, self::PASSWORD));
        $this->assertDatabaseMissing('security_audit_logs', [
            'target_user_id' => $ordinary->id,
            'event_type' => 'employee_credential_login_success',
        ]);
    }

    public function test_denied_correct_password_preserves_partial_failure_count(): void
    {
        $ordinary = $this->makeEmployee();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(self::BASE.'/basic', [
                'employee_id' => $ordinary->login_id,
                'password' => 'Wrong-Synthetic-Password!',
            ])->assertStatus(422);
        }
        DB::table('employees')->where('id', $ordinary->employee_id)->update([
            'is_active' => false, 'updated_at' => now(),
        ]);
        $this->postJson(self::BASE.'/basic', [
            'employee_id' => $ordinary->login_id, 'password' => self::PASSWORD,
        ])->assertStatus(422);
        $this->assertSame(2, (int) DB::table('kfos_login_security_states')
            ->where('user_id', $ordinary->id)->value('failed_attempts'));
        $this->assertDatabaseMissing('security_audit_logs', [
            'target_user_id' => $ordinary->id,
            'event_type' => 'employee_credential_login_success',
        ]);
    }

    public function test_wrong_password_still_tracks_failure_without_false_success(): void
    {
        $ordinary = $this->makeEmployee();
        $this->postJson(self::BASE.'/basic', [
            'employee_id' => $ordinary->login_id, 'password' => 'Wrong-Synthetic-Password!',
        ])->assertStatus(422);
        $this->assertGuest('web');
        $this->assertSame(1, (int) DB::table('kfos_login_security_states')
            ->where('user_id', $ordinary->id)->value('failed_attempts'));
        $this->assertDatabaseMissing('security_audit_logs', [
            'target_user_id' => $ordinary->id,
            'event_type' => 'employee_credential_login_success',
        ]);
    }
}
