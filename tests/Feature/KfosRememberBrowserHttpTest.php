<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\KfosRememberBrowserController;
use App\Http\Middleware\KfosRestoreRememberedSession;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/** Routes registered in memory ONLY; application routes/web.php remains unchanged. */
class KfosRememberBrowserHttpTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Remember-Http-2026!';
    private const BASE = 'http://localhost:8080/__kfos_remember_http';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName(),
            'STOP: This test is restricted to kfos_test');
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8080']);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        // JSON test requests only transmit cookies when credentials are enabled.
        $this->withCredentials();

        Route::middleware('web')->post('/__kfos_remember_http/login',
            [KfosRememberBrowserController::class, 'login']);
        Route::middleware(['web', KfosRestoreRememberedSession::class, 'auth:web', 'kfos.account'])
            ->get('/__kfos_remember_http/protected',
                fn () => response()->json(['ok' => true, 'user_id' => Auth::guard('web')->id()]));
        Route::middleware(['web', KfosRestoreRememberedSession::class, 'auth:web', 'kfos.account'])
            ->post('/__kfos_remember_http/logout',
                [KfosRememberBrowserController::class, 'logout']);
        Route::middleware(['web', KfosRestoreRememberedSession::class, 'auth:web', 'kfos.account'])
            ->post('/__kfos_remember_http/logout-all',
                [KfosRememberBrowserController::class, 'logoutAll']);


    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function employee(string $id, bool $dev = false): User
    {
        $employeePk = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $id,
            'name' => 'Synthetic '.$id,
            'position' => 'Test Operator',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employeePk,
            'login_id' => $id,
            'name' => 'Synthetic '.$id,
            'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'is_system_admin' => $dev,
            'is_development_only' => $dev,
            'session_version' => 1,
        ])->save();
        return $user;
    }

    private function login(User $user, bool $remember = true)
    {
        return $this->postJson(self::BASE.'/login', [
            'employee_id' => $user->login_id,
            'password' => self::PASSWORD,
            'remember_me' => $remember,
        ]);
    }

    private function rememberCookie($response): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === KfosRememberBrowserController::COOKIE) {
                return $cookie;
            }
        }
        $this->fail('Expected a remembered-device cookie.');
    }

    /** Unwrap the encrypted response cookie to a plaintext token for the test request helper. */
    private function rememberedToken(Cookie $cookie): string
    {
        return CookieValuePrefix::remove(Crypt::decryptString($cookie->getValue()));
    }

    private function clearOnlyLocalSession(): void
    {
        // Simulates a browser session ending WITHOUT triggering app-level logout.
        // The fixed-duration remembered credential must still permit restoration.
        $this->flushSession();
        Auth::guard('web')->forgetUser();
    }

    public function test_unchecked_opt_in_creates_no_persistent_grant(): void
    {
        $user = $this->employee('REM_BROWSER_001');
        $this->login($user, false)->assertOk()->assertJson(['remembered' => false]);
        $this->assertDatabaseMissing('kfos_remembered_devices', ['user_id' => $user->id]);
        $this->assertLessThan(time(), $this->rememberCookie($this->login($user, false))->getExpiresTime());
    }

    public function test_cookie_is_http_only_same_site_and_not_plaintext(): void
    {
        $user = $this->employee('REM_BROWSER_002');
        $response = $this->login($user);
        $response->assertOk()->assertJson(['remembered' => true]);
        $cookie = $this->rememberCookie($response);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());
        // On local HTTP, Secure may be false; deployment HTTPS MUST be secure.
        $this->assertGreaterThan(100, strlen($cookie->getValue()),
            'Expected encrypted cookie value from standard web EncryptCookies middleware.');
        $this->assertSame(1, DB::table('kfos_remembered_devices')->where('user_id', $user->id)->count());
    }

    public function test_cookie_restores_session_after_original_session_is_lost(): void
    {
        $user = $this->employee('REM_BROWSER_003');
        $cookie = $this->rememberCookie($this->login($user));
        // Exercise the protected route directly: no diagnostic route may pre-authenticate.
        $plainToken = $this->rememberedToken($cookie);
        $this->assertSame(43, strlen($plainToken));
        $this->assertSame($user->id, app(KfosRememberedDeviceService::class)->validate($plainToken)?->id);
        $this->clearOnlyLocalSession();
        $this->withCookie($cookie->getName(), $plainToken)
            ->getJson(self::BASE.'/protected')
            ->assertOk()->assertJson(['ok' => true, 'user_id' => $user->id]);
        $this->assertSame(1, (int) session('kfos_session_version'));
        $this->assertNotNull(session('kfos_remember_restored_hash'));
    }

    public function test_restored_session_expires_at_exact_original_90_day_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 00:00:00 UTC');
        $user = $this->employee('REM_BROWSER_004');
        $cookie = $this->rememberCookie($this->login($user));
        $this->clearOnlyLocalSession();
        CarbonImmutable::setTestNow('2027-02-02 23:59:59 UTC');
        $this->withCookie($cookie->getName(), $this->rememberedToken($cookie))
            ->getJson(self::BASE.'/protected')->assertOk();
        CarbonImmutable::setTestNow('2027-02-03 00:00:00 UTC');
        // Even with a still-live Laravel session, the remembered grant is now invalid.
        $this->withCookie($cookie->getName(), $this->rememberedToken($cookie))
            ->getJson(self::BASE.'/protected')->assertUnauthorized();
    }

    public function test_explicit_logout_revokes_cookie_and_session(): void
    {
        $user = $this->employee('REM_BROWSER_005');
        $cookie = $this->rememberCookie($this->login($user));
        $this->withCookie($cookie->getName(), $this->rememberedToken($cookie))
            ->postJson(self::BASE.'/logout')->assertOk()->assertJson(['logged_out' => true]);
        $this->assertDatabaseHas('kfos_remembered_devices', [
            'user_id' => $user->id, // also check revoked_at below
        ]);
        $this->assertNotNull(DB::table('kfos_remembered_devices')
            ->where('user_id', $user->id)->value('revoked_at'));
        $this->clearOnlyLocalSession();
        $this->withCookie($cookie->getName(), $this->rememberedToken($cookie))
            ->getJson(self::BASE.'/protected')->assertUnauthorized();
    }

    public function test_logout_all_revokes_grants_and_changes_session_version(): void
    {
        $user = $this->employee('REM_BROWSER_006');
        $a = $this->rememberCookie($this->login($user));
        $this->login($user);
        $this->assertSame(2, DB::table('kfos_remembered_devices')
            ->where('user_id', $user->id)->count());
        $this->postJson(self::BASE.'/logout-all')->assertOk()
            ->assertJson(['logged_out_all' => true]);
        $this->assertSame(0, DB::table('kfos_remembered_devices')
            ->where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertSame(2, (int) User::findOrFail($user->id)->session_version);
        $this->clearOnlyLocalSession();
        $this->withCookie($a->getName(), $this->rememberedToken($a))
            ->getJson(self::BASE.'/protected')->assertUnauthorized();
    }

    public function test_shared_development_identity_is_rejected(): void
    {
        $dev = $this->employee('REM_BROWSER_SHARED', true);
        $this->login($dev)->assertStatus(422)
            ->assertJson(['message' => 'Invalid Employee ID or password.']);
        $this->assertDatabaseMissing('kfos_remembered_devices', ['user_id' => $dev->id]);
    }
}
