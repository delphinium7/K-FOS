<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Carbon\CarbonImmutable;

class KfosRememberedDevicePolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName(),
            'STOP: This test can run ONLY with DB_DATABASE=kfos_test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function employee(string $loginId = 'REMEMBER_TEST_USER'): User
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_id' => $loginId,
            'name' => 'Synthetic Remember Test',
            'position' => 'Test Operator',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employeeId,
            'login_id' => $loginId,
            'name' => 'Synthetic Remember Test',
            'email' => null,
            'password' => Hash::make('Synthetic-Remember-Password-2026!'),
            'is_active' => true,
            'is_system_admin' => false,
            'is_development_only' => false,
            'session_version' => 1,
        ]);
        $user->save();
        return $user;
    }

    public function test_unchecked_opt_in_creates_no_grant(): void
    {
        $user = $this->employee();
        $service = app(KfosRememberedDeviceService::class);
        $this->assertNull($service->issueAfterCredentialLogin($user, false));
        $this->assertSame(0, DB::table('kfos_remembered_devices')->where('user_id', $user->id)->count());
    }


public function test_expiry_is_exactly_90_days_and_never_rolls(): void
{
    // Original credential login: 5 November 2026.
    CarbonImmutable::setTestNow(
        '2026-11-05 00:00:00 UTC'
    );

    $user = $this->employee();

    $service = app(
        KfosRememberedDeviceService::class
    );

    $token = $service->issueAfterCredentialLogin(
        $user,
        true
    );

    $this->assertIsString($token);

    $grant = DB::table('kfos_remembered_devices')
        ->where('user_id', $user->id)
        ->first();

    $issuedAt = CarbonImmutable::parse(
        $grant->issued_at,
        'UTC'
    );

    $expiresAt = CarbonImmutable::parse(
        $grant->expires_at,
        'UTC'
    );

    // Exactly 90 days after original login.
    $this->assertSame(
        90 * 86400,
        $expiresAt->timestamp - $issuedAt->timestamp
    );

    // Immediately after issuance: valid.
    $this->assertNotNull(
        $service->validate($token)
    );

    // One second before expiration: still valid.
    CarbonImmutable::setTestNow(
        '2027-02-02 23:59:59 UTC'
    );

    $this->assertNotNull(
        $service->validate($token)
    );

    // Activity must not extend the expiry.
    $this->assertSame(
        $grant->expires_at,
        DB::table('kfos_remembered_devices')
            ->where('id', $grant->id)
            ->value('expires_at')
    );

    // Exact expiration instant: must be rejected.
    CarbonImmutable::setTestNow(
        '2027-02-03 00:00:00 UTC'
    );

    $this->assertNull(
        $service->validate($token)
    );

    // After expiration: remains rejected.
    CarbonImmutable::setTestNow(
        '2027-02-04 00:00:00 UTC'
    );

    $this->assertNull(
        $service->validate($token)
    );
}


    public function test_independent_devices_and_single_device_logout(): void
    {
        $user = $this->employee();
        $service = app(KfosRememberedDeviceService::class);
        $a = $service->issueAfterCredentialLogin($user, true);
        $b = $service->issueAfterCredentialLogin($user, true);
        $this->assertNotSame($a, $b);
        $this->assertSame(2, DB::table('kfos_remembered_devices')->where('user_id', $user->id)->count());
        $service->revokeOne($a);
        $this->assertNull($service->validate($a));
        $this->assertNotNull($service->validate($b));
    }

    public function test_logout_all_revokes_every_device(): void
    {
        $user = $this->employee();
        $service = app(KfosRememberedDeviceService::class);
        $a = $service->issueAfterCredentialLogin($user, true);
        $b = $service->issueAfterCredentialLogin($user, true);
        $this->assertSame(2, $service->revokeAllForUser($user->id));
        $this->assertNull($service->validate($a));
        $this->assertNull($service->validate($b));
    }

    public function test_deactivation_and_session_version_change_invalidate_credential(): void
    {
        $user = $this->employee();
        $service = app(KfosRememberedDeviceService::class);
        $a = $service->issueAfterCredentialLogin($user, true);
        $user->is_active = false;
        $user->save();
        $this->assertNull($service->validate($a));
        $user->is_active = true;
        $user->session_version = 2;
        $user->save();
        $this->assertNull($service->validate($a));
    }

    public function test_five_failure_restriction_does_not_revoke_valid_remembered_device(): void
    {
        $user = $this->employee();
        $service = app(KfosRememberedDeviceService::class);
        $token = $service->issueAfterCredentialLogin($user, true);
        DB::table('kfos_login_security_states')->insert([
            'user_id' => $user->id,
            'failed_attempts' => 5,
            'restricted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame($user->id, $service->validate($token)?->id);
    }
}
