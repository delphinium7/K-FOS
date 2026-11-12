<?php

namespace Tests\Feature;

use App\Http\Controllers\Security\KfosEmployeeAccountStatusController;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use App\Services\Auth\KfosRememberedDeviceService;
use App\Services\Security\KfosEmployeeAccountStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosEmployeeAccountStatusHttpTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'http://localhost:8080/__kfos_5ac/account-status/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8080']);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Route::middleware(['web', 'auth:web', 'kfos.account'])
            ->post('/__kfos_5ac/account-status/{user}',
                [KfosEmployeeAccountStatusController::class, 'update']);
    }

    private function employee(bool $admin = false, bool $dev = false): User
    {
        $login = 'T5AC_'.strtoupper(Str::random(14));
        $pk = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic 5AC Person',
            'position' => 'Test Worker', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($pk !== null) {
            DB::table('employee_id_reservations')->insert([
                'employee_id' => $pk, 'reserved_employee_id' => $login,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $user = new User();
        $user->forceFill([
            'employee_id' => $pk, 'login_id' => $login,
            'name' => 'Synthetic 5AC Person', 'email' => null,
            'password' => Hash::make('Synthetic-Account-Status-5AC-Password!'),
            'is_active' => true, 'is_system_admin' => $dev,
            'is_development_only' => $dev, 'session_version' => 1,
        ])->save();
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function delegate(bool $granted = true): User
    {
        $actor = $this->employee();
        $roleId = DB::table('kfos_roles')->insertGetId([
            'code' => 't5ac_'.strtolower(Str::random(17)),
            'name' => 'T5AC Delegate '.Str::random(10),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($granted) {
            $permissionId = DB::table('kfos_permissions')
                ->where('code', 'security.accounts.status')->value('id');
            $this->assertNotNull($permissionId);
            DB::table('kfos_role_permissions')->insert([
                'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $actor->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $actor;
    }

    private function login(User $actor): void
    {
        $this->actingAs($actor, 'web')
            ->withSession(['kfos_session_version' => (int) $actor->session_version]);
    }

    private function endpoint(User $target): string
    {
        return self::BASE.$target->id;
    }

    private function auditCount(User $target): int
    {
        return (int) DB::table('security_audit_logs')
            ->where('target_user_id', $target->id)
            ->whereIn('event_type', [
                'kfos_employee_account_deactivated', 'kfos_employee_account_reactivated',
            ])->count();
    }

    public function test_admin_deactivation_revokes_sessions_and_keeps_employee_history_and_password(): void
    {
        $admin = $this->employee(true);
        $target = $this->employee();
        $password = DB::table('users')->where('id', $target->id)->value('password');
        $token = app(KfosRememberedDeviceService::class)
            ->issueAfterCredentialLogin($target, true);
        $this->assertIsString($token);
        $this->login($admin);
        $this->postJson($this->endpoint($target), ['status' => 'inactive', 'reason' => 'Authorized test'])
            ->assertOk()->assertJson(['changed' => true, 'status' => 'inactive']);
        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertSame(2, $target->session_version);
        $this->assertSame($password, DB::table('users')->where('id', $target->id)->value('password'));
        $this->assertDatabaseHas('employees', [
            'id' => $target->employee_id, 'is_active' => true,
        ]);
        $this->assertNull(app(KfosRememberedDeviceService::class)->validate($token));
        $audit = DB::table('security_audit_logs')->where('target_user_id', $target->id)
            ->where('event_type', 'kfos_employee_account_deactivated')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, (int) $audit->actor_user_id);
        $details = json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['login_account_status' => 'active'], $details['before']);
        $this->assertSame(['login_account_status' => 'inactive'], $details['after']);
        $this->assertSame('Authorized test', $details['reason']);
    }

    public function test_reactivation_preserves_hash_and_does_not_clear_fifth_failure_lock(): void
    {
        $admin = $this->employee(true);
        $target = $this->employee();
        $this->login($admin);
        // Restrict while target is ACTIVE. Status changes must not clear the lock.
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(app(KfosEmployeeLoginSecurity::class)
                ->verifyCredentials($target->login_id, 'Bad-Synthetic-Password!'));
        }
        $restricted = DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->value('restricted_at');
        $this->assertNotNull($restricted);
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])
            ->assertOk();
        $password = DB::table('users')->where('id', $target->id)->value('password');
        $this->assertSame(2, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->postJson($this->endpoint($target), ['status' => 'active'])
            ->assertOk()->assertJson(['changed' => true, 'status' => 'active']);
        $this->assertSame($password, DB::table('users')->where('id', $target->id)->value('password'));
        $this->assertSame(3, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->assertSame($restricted, DB::table('kfos_login_security_states')
            ->where('user_id', $target->id)->value('restricted_at'));
        $this->assertSame(2, $this->auditCount($target));
    }

    public function test_delegate_can_deactivate_and_reactivate_another_ordinary_employee(): void
    {
        $actor = $this->delegate();
        $target = $this->employee();
        $this->login($actor);
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])
            ->assertOk()->assertJson(['changed' => true]);
        $this->postJson($this->endpoint($target), ['status' => 'active'])
            ->assertOk()->assertJson(['changed' => true]);
        $this->assertSame(2, $this->auditCount($target));
        $this->assertSame(3, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $audit = DB::table('security_audit_logs')->where('target_user_id', $target->id)
            ->where('event_type', 'kfos_employee_account_deactivated')->first();
        $this->assertSame('manage_employee_account_status',
            json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR)['actor_capacity']);
    }

    public function test_delegate_cannot_deactivate_their_own_login(): void
    {
        $actor = $this->delegate();
        $this->login($actor);
        $this->postJson($this->endpoint($actor), ['status' => 'inactive'])->assertForbidden();
        $this->assertTrue((bool) DB::table('users')->where('id', $actor->id)->value('is_active'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $actor->id)->value('session_version'));
        $this->assertSame(0, $this->auditCount($actor));
    }

    public function test_unprivileged_actor_cannot_change_account_status(): void
    {
        $actor = $this->delegate(false);
        $target = $this->employee();
        $this->login($actor);
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])->assertForbidden();
        $this->assertTrue((bool) DB::table('users')->where('id', $target->id)->value('is_active'));
        $this->assertSame(0, $this->auditCount($target));
    }

    public function test_delegate_cannot_change_any_universal_administrator(): void
    {
        $actor = $this->delegate();
        $target = $this->employee(true);
        $this->login($actor);
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])->assertForbidden();
        $this->postJson($this->endpoint($target), ['status' => 'active'])->assertForbidden();
        $this->assertTrue($target->isKfosSystemAdministrator());
        $this->assertSame(1, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->assertSame(0, $this->auditCount($target));
    }

    public function test_admin_cannot_deactivate_self_even_with_another_usable_admin(): void
    {
        $actor = $this->employee(true);
        $other = $this->employee(true);
        $this->login($actor);
        $this->postJson($this->endpoint($actor), ['status' => 'inactive'])->assertForbidden();
        $this->assertSame(0, $this->auditCount($actor));
        $this->assertTrue($other->isKfosSystemAdministrator());
    }

    public function test_cannot_remove_last_usable_admin_by_deactivating_other_admin(): void
    {
        $actor = $this->employee(true);
        $target = $this->employee(true);
        $this->login($actor);
        // Existing session survives fifth-failure lock, but not a usable new login.
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(app(KfosEmployeeLoginSecurity::class)
                ->verifyCredentials($actor->login_id, 'Bad-Synthetic-Password!'));
        }
        $this->assertNotNull(DB::table('kfos_login_security_states')
            ->where('user_id', $actor->id)->value('restricted_at'));
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])->assertForbidden();
        $this->assertTrue((bool) DB::table('users')->where('id', $target->id)->value('is_active'));
        $this->assertSame(0, $this->auditCount($target));
    }

    public function test_rejects_legacy_development_admin_flag(): void
    {
        $actor = $this->employee(false, true);
        $target = $this->employee();
        $this->login($actor);
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])->assertForbidden();
        $this->assertSame(0, $this->auditCount($target));
    }

    public function test_stale_session_cannot_change_status(): void
    {
        $actor = $this->employee(true);
        $target = $this->employee();
        $this->login($actor);
        DB::table('users')->where('id', $actor->id)->update(['session_version' => 2]);
        $this->postJson($this->endpoint($target), ['status' => 'inactive'])->assertForbidden();
        $this->assertTrue((bool) DB::table('users')->where('id', $target->id)->value('is_active'));
        $this->assertSame(0, $this->auditCount($target));
    }

    public function test_noop_does_not_revoke_session_or_write_success_audit(): void
    {
        $actor = $this->employee(true);
        $target = $this->employee();
        $this->login($actor);
        $this->postJson($this->endpoint($target), ['status' => 'active'])
            ->assertOk()->assertJson(['changed' => false]);
        $this->assertSame(1, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->assertSame(0, $this->auditCount($target));
    }
}
