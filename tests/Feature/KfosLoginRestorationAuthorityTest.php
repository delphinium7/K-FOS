<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Exercises the CURRENT authenticated administrator, not a legacy boolean flag. */
final class KfosLoginRestorationAuthorityTest extends TestCase
{
    use DatabaseTransactions;
    private const PASSWORD = 'Synthetic-5AQ-Test-Only!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        config(['session.driver' => 'array']);
    }

    private function account(bool $universal = false, bool $legacy = false, bool $dev = false): User
    {
        $code = 'T5AQ_'.strtoupper(Str::random(14));
        $employee = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $code, 'name' => 'Synthetic '.$code,
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $employee, 'login_id' => $code,
            'name' => 'Synthetic '.$code, 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'is_development_only' => $dev,
            'is_system_admin' => $legacy, 'session_version' => 1,
        ])->save();
        if ($universal) DB::table('kfos_system_administrators')->insert([
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function lockNewLogin(User $target): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(app(KfosEmployeeLoginSecurity::class)
                ->verifyCredentials($target->login_id, 'Wrong-Synthetic-Only!'));
        }
        $this->assertSame(5, (int) DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->value('failed_attempts'));
    }

    private function authenticate(User $actor, int $sessionVersion = 1): void
    {
        $this->actingAs($actor, 'web')->withSession(['kfos_session_version' => $sessionVersion]);
    }

    private function assertDeniedWithoutRestoration(User $actor, User $target): void
    {
        $stateBefore = DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->first();
        $this->assertNotNull($stateBefore?->restricted_at);
        try {
            app(KfosEmployeeLoginSecurity::class)->restore($actor, $target->id);
            $this->fail('Unauthorized restoration succeeded');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $stateAfter = DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->first();
        $this->assertSame(5, (int) $stateAfter->failed_attempts);
        $this->assertEquals($stateBefore->restricted_at, $stateAfter->restricted_at);
        $this->assertFalse(DB::table('security_audit_logs')
            ->where('event_type', 'employee_login_restriction_restored')
            ->where('target_user_id', $target->id)->exists());
        $this->assertTrue(DB::table('kfos_login_restriction_notifications')
            ->where('target_user_id', $target->id)->whereNull('resolved_at')->exists());
    }

    public function test_genuine_logged_in_administrator_restores_once_and_resolves_original_alert(): void
    {
        $target = $this->account();
        $admin = $this->account(true, false);
        $this->lockNewLogin($target);
        $this->authenticate($admin);
        $service = app(KfosEmployeeLoginSecurity::class);
        $this->assertTrue($service->restore($admin, $target->id, 'Synthetic authorized action'));
        $this->assertSame(0, (int) DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->value('failed_attempts'));
        $this->assertNull(DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->value('restricted_at'));
        $this->assertTrue(DB::table('kfos_login_restriction_notifications')
            ->where('target_user_id', $target->id)->whereNotNull('resolved_at')->exists());
        $this->assertTrue(DB::table('security_audit_logs')
            ->where('actor_user_id', $admin->id)
            ->where('event_type', 'employee_login_restriction_restored')
            ->where('target_user_id', $target->id)->exists());
        $this->assertFalse($service->restore($admin, $target->id));
    }

    public function test_legacy_admin_boolean_does_not_grant_restoration(): void
    {
        $target = $this->account(); $legacy = $this->account(false, true);
        $this->lockNewLogin($target); $this->authenticate($legacy);
        $this->assertDeniedWithoutRestoration($legacy, $target);
    }

    public function test_delegate_account_status_permission_cannot_restore_login_restriction(): void
    {
        $target = $this->account(); $delegate = $this->account();
        $roleId = DB::table('kfos_roles')->insertGetId([
            'code' => 't5aq_'.strtolower(Str::random(14)),
            'name' => 'T5AQ Role '.strtoupper(Str::random(10)),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $delegate->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => DB::table('kfos_permissions')->where('code', 'security.accounts.status')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lockNewLogin($target); $this->authenticate($delegate);
        $this->assertDeniedWithoutRestoration($delegate, $target);
    }

    public function test_authenticated_other_user_cannot_impersonate_real_administrator_argument(): void
    {
        $target = $this->account(); $admin = $this->account(true); $ordinary = $this->account();
        $this->lockNewLogin($target); $this->authenticate($ordinary);
        $this->assertDeniedWithoutRestoration($admin, $target);
    }

    public function test_stale_administrator_session_cannot_restore(): void
    {
        $target = $this->account(); $admin = $this->account(true);
        $this->lockNewLogin($target); $this->authenticate($admin, 0);
        $this->assertDeniedWithoutRestoration($admin, $target);
    }

    public function test_inactive_employee_master_genuine_admin_retains_authorized_restoration(): void
    {
        // CDR-0637/0640: this exception is ONLY for current genuinely
        // authorized universal SysAdmin accounts, not delegated roles.
        $target = $this->account(); $admin = $this->account(true);
        $this->lockNewLogin($target); $this->authenticate($admin);
        DB::table('employees')->where('id', $admin->employee_id)->update(['is_active' => false]);
        $this->assertTrue(app(KfosEmployeeLoginSecurity::class)->restore($admin, $target->id));
        $this->assertNull(DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->value('restricted_at'));
        $this->assertTrue(DB::table('kfos_login_restriction_notifications')
            ->where('target_user_id', $target->id)->whereNotNull('resolved_at')->exists());
    }

    public function test_dev_identity_with_synthetic_universal_membership_still_cannot_restore(): void
    {
        $target = $this->account(); $dev = $this->account(true, true, true);
        $this->lockNewLogin($target); $this->authenticate($dev);
        $this->assertDeniedWithoutRestoration($dev, $target);
    }
}
