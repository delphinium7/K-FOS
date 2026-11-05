<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\KfosLoginRestorationController;
use App\Http\Controllers\Auth\KfosEmployeeCredentialController;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Tests register private routes in memory; routes/web.php is NOT modified. */
class KfosProductionHttpIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Only-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName(),
            'STOP: Only run with DB_DATABASE=kfos_test');
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8080']);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        Route::middleware('web')->post('/__kfos_auth_integration/login',
            [KfosEmployeeCredentialController::class, 'store']);
        Route::middleware(['web', 'auth:web', 'kfos.account'])->get(
            '/__kfos_auth_integration/protected', fn () => response()->json(['ok' => true]));
        Route::middleware(['web', 'auth:web', 'kfos.account'])->post(
            '/__kfos_auth_integration/restore/{targetUserId}',
            [KfosLoginRestorationController::class, 'store']);
    }

    private function employee(string $id, bool $admin = false, bool $dev = false): User
    {
        $pk = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $id,
            'name' => 'Synthetic '.$id,
            'position' => 'Test Operator',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $pk,
            'login_id' => $id,
            'name' => 'Synthetic '.$id,
            'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'is_system_admin' => $admin,
            'is_development_only' => $dev,
            'session_version' => 1,
        ])->save();
        return $user;
    }

    private function login(string $id, string $password = self::PASSWORD)
    {
        return $this->postJson('http://localhost:8080/__kfos_auth_integration/login', [
            'employee_id' => $id, 'password' => $password,
        ]);
    }

    public function test_real_employee_login_creates_protected_session_and_audit(): void
    {
        $user = $this->employee('HTTP_INTEG_001');
        $this->login($user->login_id)->assertOk()->assertJson(['authenticated' => true]);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(1, (int) session('kfos_session_version'));
        $this->getJson('http://localhost:8080/__kfos_auth_integration/protected')
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('security_audit_logs', [
            'target_user_id' => $user->id,
            'event_type' => 'employee_credential_login_success',
            'result' => 'success',
        ]);
    }

    public function test_fifth_failure_blocks_new_login_but_preserves_prior_session(): void
    {
        $user = $this->employee('HTTP_INTEG_002');
        $this->login($user->login_id)->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->login($user->login_id, 'wrong-password')->assertStatus(422);
        }
        $this->login($user->login_id)->assertStatus(422);
        $this->getJson('http://localhost:8080/__kfos_auth_integration/protected')
            ->assertOk();
        $this->assertDatabaseHas('kfos_login_security_states', [
            'user_id' => $user->id, 'failed_attempts' => 5,
        ]);
    }

    public function test_admin_restores_locked_employee_then_employee_can_login(): void
    {
        $target = $this->employee('HTTP_INTEG_003');
        $admin = $this->employee('HTTP_ADMIN_003', true);
        for ($i = 0; $i < 5; $i++) {
            $this->login($target->login_id, 'wrong-password')->assertStatus(422);
        }
        $this->login($target->login_id)->assertStatus(422);
        $this->login($admin->login_id)->assertOk();
        $this->postJson(
            'http://localhost:8080/__kfos_auth_integration/restore/'.$target->id,
            ['remark' => 'Synthetic authorized restoration']
        )->assertOk()->assertJson(['restored' => true]);
        $this->assertDatabaseHas('kfos_login_security_states', [
            'user_id' => $target->id, 'failed_attempts' => 0, 'restricted_at' => null,
        ]);
        $this->login($target->login_id)->assertOk();
    }

    public function test_non_admin_and_shared_dev_identity_cannot_restore(): void
    {
        $target = $this->employee('HTTP_INTEG_004');
        $ordinary = $this->employee('HTTP_USER_004');
        $shared = $this->employee('KFOS_ADMIN', true, true);
        $this->login($ordinary->login_id)->assertOk();
        $this->postJson(
            'http://localhost:8080/__kfos_auth_integration/restore/'.$target->id,
            []
        )->assertForbidden();

        // Shared dev identity must never authenticate via production adapter.
        Auth::guard('web')->logout();
        $this->login($shared->login_id)->assertStatus(422);
        $this->assertGuest('web');
    }
}
