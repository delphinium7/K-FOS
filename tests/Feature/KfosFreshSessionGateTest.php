<?php

namespace Tests\Feature;

use App\Http\Middleware\KfosFreshSessionGate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** All test identities are synthetic and all DB writes are transaction-rolled-back. */
final class KfosFreshSessionGateTest extends TestCase
{
    use DatabaseTransactions;
    private const URL = 'http://localhost:8080/__kfos_step6/fresh-session';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        app()->detectEnvironment(fn () => 'local');
        config(['app.url' => 'http://localhost:8080', 'app.debug' => true,
            'session.driver' => 'array']);
        Route::middleware(['web','auth:web','kfos.account', KfosFreshSessionGate::class])
            ->get('/__kfos_step6/fresh-session', fn () => response()->json(['authorized' => true]));
    }

    private function employee(bool $universal = false, bool $legacy = false): User
    {
        $code = 'T6_'.strtoupper(Str::random(16));
        $pk = DB::table('employees')->insertGetId([
            'employee_id' => $code, 'name' => 'Test '.$code,
            'position' => 'Synthetic Test Employee', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $u = new User();
        $u->forceFill([
            'employee_id' => $pk, 'login_id' => $code,
            'name' => 'Test '.$code, 'email' => null,
            'password' => Hash::make('Synthetic-Only-Step6!'),
            'is_active' => true, 'is_system_admin' => $legacy,
            'is_development_only' => false, 'session_version' => 1,
        ])->save();
        if ($universal) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $u;
    }

    private function login(User $user, int $version = 1): void
    {
        $this->actingAs($user, 'web')->withSession(['kfos_session_version' => $version]);
    }

    public function test_active_original_employee_with_current_session_is_allowed(): void
    {
        $u = $this->employee(); $this->login($u);
        $this->getJson(self::URL)->assertOk()->assertJson(['authorized' => true]);
    }

    public function test_hr_inactive_denies_ordinary_account_even_when_login_account_stays_active(): void
    {
        $u = $this->employee(); $this->login($u);
        DB::table('employees')->where('id', $u->employee_id)->update(['is_active' => false]);
        $this->getJson(self::URL)->assertForbidden();
        $this->assertGuest('web');
        $this->assertTrue((bool) DB::table('users')->where('id', $u->id)->value('is_active'));
    }

    public function test_legacy_admin_flag_does_not_bypass_original_master_inactive(): void
    {
        $u = $this->employee(false, true); $this->login($u);
        DB::table('employees')->where('id', $u->employee_id)->update(['is_active' => false]);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_genuine_universal_administrator_retains_narrow_employment_exception(): void
    {
        $u = $this->employee(true); $this->login($u);
        DB::table('employees')->where('id', $u->employee_id)->update(['is_active' => false]);
        $this->getJson(self::URL)->assertOk()->assertJson(['authorized' => true]);
        // Exception DOES NOT bypass independent KFOS login-account Inactive status.
        DB::table('users')->where('id', $u->id)->update(['is_active' => false]);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_stale_session_version_does_not_authorize_fresh_request(): void
    {
        $u = $this->employee(); $this->login($u);
        DB::table('users')->where('id', $u->id)->update(['session_version' => 2]);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_no_prior_session_is_not_authorized(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_hr_status_correction_does_not_restore_logged_out_session(): void
    {
        $u = $this->employee(); $this->login($u);
        DB::table('employees')->where('id', $u->employee_id)->update(['is_active' => false]);
        $this->getJson(self::URL)->assertForbidden();
        DB::table('employees')->where('id', $u->employee_id)->update(['is_active' => true]);
        $this->getJson(self::URL)->assertUnauthorized();
        // Not a durable epoch test: old remembered tokens still need the CDR barriers.
    }
}
