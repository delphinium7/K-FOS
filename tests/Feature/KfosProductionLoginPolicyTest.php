<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KfosProductionLoginPolicyTest extends TestCase
{
    use DatabaseTransactions;
    private const PASSWORD = 'Synthetic-Test-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName(),
            'STOP: Set DB_DATABASE=kfos_test before any integration test.');
    }

    private function employee(string $id, bool $admin = false): User
    {
        $employeePk = DB::table('employees')->insertGetId([
            'employee_id' => $id, 'name' => 'Synthetic '. $id,
            'position' => 'Test position', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employeePk, 'login_id' => $id,
            'name' => 'Synthetic '. $id, 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'is_system_admin' => $admin,
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

    public function test_five_failures_lock_across_sources_without_revoking_existing_session(): void
    {
        $user = $this->employee('POLICY_TEST_001');
        $user->forceFill(['remember_token' => 'synthetic-token'])->save();
        $service = app(KfosEmployeeLoginSecurity::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($service->verifyCredentials($user->login_id, 'wrong-password'));
        }
        $state = DB::table('kfos_login_security_states')->where('user_id', $user->id)->first();
        $this->assertSame(5, (int) $state->failed_attempts);
        $this->assertNotNull($state->restricted_at);
        $this->assertNull($service->verifyCredentials($user->login_id, self::PASSWORD));
        $this->assertDatabaseHas('kfos_login_restriction_notifications', [
            'target_user_id' => $user->id, 'resolved_at' => null,
        ]);
        $user->refresh();
        $this->assertSame(1, $user->session_version);
        $this->assertSame('synthetic-token', $user->remember_token);
    }

    public function test_successful_login_resets_only_prelock_failures(): void
    {
        $user = $this->employee('POLICY_TEST_002');
        $service = app(KfosEmployeeLoginSecurity::class);
        for ($i = 0; $i < 3; $i++) {
            $service->verifyCredentials($user->login_id, 'wrong-password');
        }
        $this->assertSame($user->id, $service->verifyCredentials($user->login_id, self::PASSWORD)?->id);
        $state = DB::table('kfos_login_security_states')->where('user_id', $user->id)->first();
        $this->assertSame(0, (int) $state->failed_attempts);
        $this->assertNull($state->restricted_at);
    }

    public function test_only_eligible_real_administrator_can_restore_and_resolve_notification(): void
    {
        $user = $this->employee('POLICY_TEST_003');
        $admin = $this->employee('POLICY_ADMIN_003', true);
        $this->actingAs($admin, 'web')->withSession(['kfos_session_version' => 1]);
        $service = app(KfosEmployeeLoginSecurity::class);
        for ($i = 0; $i < 5; $i++) {
            $service->verifyCredentials($user->login_id, 'wrong-password');
        }
        $this->assertTrue($service->restore($admin, $user->id));
        $this->assertSame($user->id, $service->verifyCredentials($user->login_id, self::PASSWORD)?->id);
        $this->assertDatabaseHas('kfos_login_restriction_notifications', [
            'target_user_id' => $user->id, 'resolved_by_user_id' => $admin->id,
        ]);
        $this->assertFalse($service->restore($admin, $user->id));
    }

    public function test_unknown_id_is_audited_without_exposing_identifier_or_password(): void
    {
        $this->assertNull(app(KfosEmployeeLoginSecurity::class)
            ->verifyCredentials('SYNTHETIC_UNKNOWN_LOGIN', 'wrong-password'));
        $this->assertDatabaseHas('security_audit_logs', [
            'event_type' => 'employee_login_unknown_id', 'result' => 'failed',
            'target_user_id' => null,
        ]);
    }
}
