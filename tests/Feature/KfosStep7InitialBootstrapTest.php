<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\KfosAuthorization;
use App\Services\Security\KfosInitialAdministratorBootstrap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Synthetic original-employee test data in kfos_test only, always rolled back. */
final class KfosStep7InitialBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Step7-Admin-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        $this->assertTrue(Schema::hasTable('kfos_first_admin_bootstrap_events'));
        $this->assertTrue(Schema::hasTable('kfos_account_security_epochs'));
        // This synthetic transaction cannot reuse a real bootstrap or existing
        // genuine membership; all test changes are rolled back afterward.
        DB::table('kfos_system_administrators')->delete();
        DB::table('kfos_first_admin_bootstrap_events')->delete();
        DB::table('security_audit_logs')
            ->where('event_type', 'kfos_first_administrator_bootstrapped')->delete();
    }

    private function originalEmployee(bool $active = true): array
    {
        $id = 'S7ADMIN_'.strtoupper(Str::random(12));
        $pk = DB::table('employees')->insertGetId([
            'employee_id' => $id, 'name' => 'Synthetic Step7',
            'position' => 'Test Engineer', 'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$pk, $id];
    }

    private function bootstrap(string $employeeId, string $password = self::PASSWORD): User
    {
        return app(KfosInitialAdministratorBootstrap::class)
            ->initialize($employeeId, 'SYNTHETIC_OPERATOR', 'SYNTHETIC_APPROVAL', $password);
    }

    private function expectRefusal(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the prohibited first-bootstrap operation to be refused.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_initializes_only_a_real_original_employee_and_audits_without_password(): void
    {
        [$master, $id] = $this->originalEmployee();
        $admin = $this->bootstrap($id);
        $this->assertSame((int) $master, (int) $admin->employee_id);
        $this->assertSame($id, $admin->login_id);
        $this->assertFalse((bool) $admin->is_development_only);
        $this->assertFalse((bool) $admin->is_system_admin);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNotSame(self::PASSWORD, $admin->password);
        $this->assertTrue(app(KfosAuthorization::class)->isSystemAdministrator($admin));
        $this->assertDatabaseHas('kfos_first_admin_bootstrap_events', [
            'id' => 1, 'user_id' => $admin->id, 'official_employee_id' => $id,
        ]);
        $this->assertDatabaseHas('kfos_account_security_epochs', [
            'user_id' => $admin->id, 'epoch' => 0, 'revocation_pending' => false,
        ]);
        $audit = DB::table('security_audit_logs')
            ->where('event_type', 'kfos_first_administrator_bootstrapped')
            ->where('target_user_id', $admin->id)->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $audit->details);
        $this->assertNull($admin->email);
    }

    public function test_one_time_bootstrap_does_not_become_emergency_recovery(): void
    {
        [, $first] = $this->originalEmployee();
        [, $second] = $this->originalEmployee();
        $admin = $this->bootstrap($first);
        DB::table('kfos_system_administrators')->where('user_id', $admin->id)->delete();
        $this->expectRefusal(fn () => $this->bootstrap($second));
        $this->assertSame(1, DB::table('kfos_first_admin_bootstrap_events')->count());
        $this->assertSame(0, DB::table('kfos_system_administrators')->count());
    }

    public function test_refuses_inactive_original_employee(): void
    {
        [, $id] = $this->originalEmployee(false);
        $this->expectRefusal(fn () => $this->bootstrap($id));
        $this->assertSame(0, DB::table('kfos_first_admin_bootstrap_events')->count());
    }

    public function test_refuses_weak_password_without_creating_an_account(): void
    {
        [, $id] = $this->originalEmployee();
        $this->expectRefusal(fn () => $this->bootstrap($id, 'weak'));
        $this->assertFalse(DB::table('users')->where('login_id', $id)->exists());
    }

    public function test_refuses_existing_development_identity_collision(): void
    {
        [$pk, $id] = $this->originalEmployee();
        DB::table('users')->insert([
            'employee_id' => $pk, 'login_id' => $id, 'name' => 'Synthetic',
            'password' => Hash::make(self::PASSWORD), 'email' => null,
            'is_active' => true, 'is_system_admin' => true,
            'is_development_only' => true, 'session_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectRefusal(fn () => $this->bootstrap($id));
    }

    public function test_refuses_account_with_operational_role(): void
    {
        [$pk, $id] = $this->originalEmployee();
        $userId = DB::table('users')->insertGetId([
            'employee_id' => $pk, 'login_id' => $id, 'name' => 'Synthetic',
            'password' => Hash::make(self::PASSWORD), 'email' => null,
            'is_active' => true, 'is_system_admin' => false,
            'is_development_only' => false, 'session_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('kfos_roles')->insertGetId([
            'code' => 's7_synthetic_'.strtolower(Str::random(10)),
            'name' => 'Synthetic operational role', 'kind' => 'operational',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kfos_operational_role_assignments')->insert([
            'user_id' => $userId, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectRefusal(fn () => $this->bootstrap($id));
    }

    public function test_existing_unassigned_account_requires_and_receives_session_revocation(): void
    {
        [$pk, $id] = $this->originalEmployee();
        $oldPassword = Hash::make('Old-Synthetic-Account-2026!');
        $userId = DB::table('users')->insertGetId([
            'employee_id' => $pk, 'login_id' => $id, 'name' => 'Synthetic',
            'password' => $oldPassword, 'email' => null,
            'is_active' => true, 'is_system_admin' => false,
            'is_development_only' => false, 'session_version' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->bootstrap($id);
        $this->assertSame((int) $userId, (int) $admin->id);
        $this->assertSame(8, (int) $admin->session_version);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_command_cannot_bootstrap_in_testing_or_without_execute_flag(): void
    {
        $this->assertSame(1, Artisan::call('kfos:bootstrap:first-administrator'));
        $this->assertSame(1, Artisan::call('kfos:bootstrap:first-administrator', [
            '--execute' => true,
            '--employee-id' => 'SYNTHETIC',
            '--operator-ref' => 'SYNTHETIC_OPERATOR',
            '--authorization-ref' => 'SYNTHETIC_APPROVAL',
            '--acknowledge' => 'FIRST-BOOTSTRAP-NOT-RECOVERY',
        ]));
    }
}
