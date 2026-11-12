<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use App\Services\Auth\KfosRememberedDeviceService;
use App\Services\Security\KfosUniversalAdministratorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosUniversalAdministratorServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    private function employee(bool $admin = false, bool $development = false): User
    {
        $login = 'T5AB_'.strtoupper(Str::random(13));
        $pk = $development ? null : DB::table('employees')->insertGetId([
            'employee_id' => $login, 'name' => 'Synthetic Test Person',
            'position' => 'Test Operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($pk !== null) {
            DB::table('employee_id_reservations')->insert([
                'employee_id' => $pk, 'reserved_employee_id' => $login,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $u = new User();
        $u->forceFill([
            'employee_id' => $pk, 'login_id' => $login,
            'name' => 'Synthetic Test Person', 'email' => null,
            'password' => Hash::make('Synthetic-Test-5AB-Password-2026!'),
            'is_active' => true, 'is_system_admin' => $development,
            'is_development_only' => $development, 'session_version' => 1,
        ])->save();
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $u;
    }

    private function login(User $actor): void
    {
        $this->actingAs($actor, 'web')->withSession(['kfos_session_version' => 1]);
    }

    private function service(): KfosUniversalAdministratorService
    {
        return app(KfosUniversalAdministratorService::class);
    }

    public function test_real_admin_grants_universal_role_with_audit_and_session_revocation(): void
    {
        $admin = $this->employee(true);
        $target = $this->employee();
        $token = app(KfosRememberedDeviceService::class)->issueAfterCredentialLogin($target, true);
        $this->assertIsString($token);
        $this->login($admin);

        $this->assertTrue($this->service()->grant($admin, $target->id, 'Authorized assignment'));
        $this->assertTrue($target->isKfosSystemAdministrator());
        $this->assertFalse((bool) DB::table('users')->where('id', $target->id)->value('is_system_admin'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->assertNull(app(KfosRememberedDeviceService::class)->validate($token));
        $audit = DB::table('security_audit_logs')->where('event_type', 'kfos_universal_admin_granted')
            ->where('target_user_id', $target->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, (int) $audit->actor_user_id);
        $details = json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['universal_member' => false], $details['before']);
        $this->assertSame(['universal_member' => true], $details['after']);
        $this->assertSame('Authorized assignment', $details['reason']);
        $this->assertFalse($this->service()->grant($admin, $target->id));
        $this->assertSame(1, DB::table('security_audit_logs')->where('event_type', 'kfos_universal_admin_granted')
            ->where('target_user_id', $target->id)->count());
    }

    public function test_authorized_admin_revokes_another_admin_but_not_their_own(): void
    {
        $actor = $this->employee(true);
        $target = $this->employee(true);
        $token = app(KfosRememberedDeviceService::class)->issueAfterCredentialLogin($target, true);
        $this->login($actor);
        $this->assertTrue($this->service()->revoke($actor, $target->id));
        $this->assertFalse($target->isKfosSystemAdministrator());
        $this->assertSame(2, (int) DB::table('users')->where('id', $target->id)->value('session_version'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $actor->id)->value('session_version'));
        $this->assertNull(app(KfosRememberedDeviceService::class)->validate($token));
        $audit = DB::table('security_audit_logs')->where('event_type','kfos_universal_admin_revoked')
            ->where('target_user_id', $target->id)->first();
        $this->assertNotNull($audit);
        $values = json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['universal_member' => true], $values['before']);
        $this->assertSame(['universal_member' => false], $values['after']);
        $this->assertFalse($this->service()->revoke($actor, $target->id));
        try {
            $this->service()->revoke($actor, $actor->id);
            $this->fail('Universal administrator self-revocation allowed.');
        } catch (AuthorizationException $e) {
            $this->assertTrue($actor->isKfosSystemAdministrator());
        }
    }

    public function test_delegated_and_legacy_flag_users_cannot_grant_or_revoke(): void
    {
        $admin = $this->employee(true);
        $delegate = $this->employee();
        $target = $this->employee();
        $role = DB::table('kfos_roles')->insertGetId([
            'code' => 't5ab_'.strtolower(Str::random(15)),
            'name' => 'T5AB Delegate '.Str::random(8), 'kind' => 'operational',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('kfos_permissions')->where('code', 'security.roles.assign')->value('id');
        $this->assertNotNull($permissionId);
        DB::table('kfos_role_permissions')->insert([
            'role_id' => $role, 'permission_id' => $permissionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $delegate->id, 'role_id' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($delegate);
        try {
            $this->service()->grant($delegate, $target->id);
            $this->fail('Delegate obtained universal grant.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($target->isKfosSystemAdministrator());
        }
        try {
            $this->service()->revoke($delegate, $admin->id);
            $this->fail('Delegate revoked universal administrator.');
        } catch (AuthorizationException $e) {
            $this->assertTrue($admin->isKfosSystemAdministrator());
        }
        $legacy = $this->employee(false, true); // local-only flag is not universal membership
        $this->login($legacy);
        try {
            $this->service()->grant($legacy, $target->id);
            $this->fail('Development-only legacy flag bypassed membership.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($target->isKfosSystemAdministrator());
        }
        // Passing a genuine admin model does not impersonate the current web guard.
        $this->login($delegate);
        try {
            $this->service()->grant($admin, $target->id);
            $this->fail('Service accepted an unauthenticated different actor.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($target->isKfosSystemAdministrator());
        }
    }

    public function test_blocks_last_usable_admin_when_other_admin_login_is_restricted(): void
    {
        $actor = $this->employee(true);
        $onlyUsable = $this->employee(true);
        $this->login($actor);
        // A fifth failed login does not revoke an already-established session,
        // but this actor can no longer establish a fresh usable login.
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(app(KfosEmployeeLoginSecurity::class)
                ->verifyCredentials($actor->login_id, 'incorrect synthetic password'));
        }
        $this->assertNotNull(DB::table('kfos_login_security_states')
            ->where('user_id', $actor->id)->value('restricted_at'));
        $before = DB::table('users')->where('id', $onlyUsable->id)->value('session_version');
        try {
            $this->service()->revoke($actor, $onlyUsable->id);
            $this->fail('Removed the last usable universal System Administrator.');
        } catch (AuthorizationException $e) {
            $this->assertTrue($onlyUsable->isKfosSystemAdministrator());
            $this->assertSame($before, DB::table('users')->where('id', $onlyUsable->id)->value('session_version'));
            $this->assertSame(0, DB::table('security_audit_logs')
                ->where('event_type', 'kfos_universal_admin_revoked')->where('target_user_id', $onlyUsable->id)->count());
        }
    }

    public function test_stale_admin_session_version_cannot_grant_universal_membership(): void
    {
        $actor = $this->employee(true);
        $target = $this->employee();
        $this->login($actor);
        DB::table('users')->where('id', $actor->id)->update(['session_version' => 2]);
        try {
            $this->service()->grant($actor, $target->id);
            $this->fail('Old authenticated session issued a universal administrator grant.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($target->isKfosSystemAdministrator());
            $this->assertSame(0, DB::table('security_audit_logs')
                ->where('event_type', 'kfos_universal_admin_granted')->where('target_user_id', $target->id)->count());
        }
    }

    public function test_universal_grant_requires_active_eligible_user_without_operational_role(): void
    {
        $actor = $this->employee(true);
        $target = $this->employee();
        $this->login($actor);
        $role = DB::table('kfos_roles')->insertGetId([
            'code' => 't5ab_'.strtolower(Str::random(15)),
            'name' => 'T5AB Ordinary '.Str::random(8), 'kind' => 'operational',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $target->id, 'role_id' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $this->service()->grant($actor, $target->id);
            $this->fail('Approved universal/operational coexistence without decision.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($target->isKfosSystemAdministrator());
        }
        DB::table('kfos_operational_role_assignments')->where('user_id', $target->id)->delete();
        DB::table('users')->where('id', $target->id)->update(['is_active' => false]);
        try {
            $this->service()->grant($actor, $target->id);
            $this->fail('Assigned universal membership to inactive user.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($target->isKfosSystemAdministrator());
        }
        $dev = $this->employee(false, true);
        try {
            $this->service()->grant($actor, $dev->id);
            $this->fail('Shared development account became a universal administrator.');
        } catch (AuthorizationException $e) {
            $this->assertFalse($dev->isKfosSystemAdministrator());
        }
    }
}
