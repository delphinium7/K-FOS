<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KfosUserManagementIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable CSRF only for automated test requests.
        // Real browser requests retain CSRF protection.
        $this->withoutMiddleware(
          \App\Http\Middleware\VerifyCsrfToken::class
);

        // Never run these database-writing tests
        // against the normal development database.
        $this->assertSame(
            'kfos_test',
            DB::connection()->getDatabaseName(),
            'STOP: This test requires the kfos_test database.'
        );

        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'kfos.allow_shared_dev_admin' => true,
            'session.driver' => 'array',
        ]);

        $this->assertTrue(app()->environment('local'));
    }

    private function createAdmin(): User
    {
        return tap(User::query()->create([
            'login_id' => 'KFOS_ADMIN',
            'name' => 'Synthetic Development Admin',
            'email' => null,
            'employee_id' => null,
            'password' => Hash::make(Str::random(32)),
        ])->forceFill([
            'is_active' => true,
            'is_system_admin' => true,
            'is_development_only' => true,
            'session_version' => 1,
        ]), function (User $user) {
            $user->save();
        });
    }

    private function createTestEmployee(): User
    {
        $loginId = 'AUTO_' . strtoupper(Str::random(12));

        $employeeId = DB::table('employees')
            ->insertGetId([
                'employee_id' => $loginId,
                'name' => 'Synthetic Test Employee',
                'position' => 'Test Operator',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('employee_id_reservations')->insert([
            'employee_id' => $employeeId,
            'reserved_employee_id' => $loginId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return tap(User::query()->create([
            'employee_id' => $employeeId,
            'login_id' => $loginId,
            'name' => 'Synthetic Test Employee',
            'email' => null,
            'password' => Hash::make(Str::random(32)),
        ])->forceFill([
            'is_active' => true,
            'is_system_admin' => false,
            'is_development_only' => true,
            'session_version' => 1,
        ]), function (User $user) {
            $user->save();
        });
    }

    private function loginAsAdmin(User $admin): void
    {
        $this->actingAs($admin, 'web')
            ->withSession([
                'kfos_session_version' =>
                    (int) $admin->session_version,
            ]);
    }

    public function test_account_lifecycle_and_audit(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createTestEmployee();

        $this->loginAsAdmin($admin);

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users/'
                . $target->id . '/status',
            [
                'status' => 'inactive',
                'reason' => 'Automated deactivation test',
            ]
        )->assertRedirect(
            route('kfos.auth.dev.users.index')
        );

        $target->refresh();

        $this->assertFalse($target->is_active);
        $this->assertSame(2, $target->session_version);

        $this->assertDatabaseHas(
            'employees',
            [
                'id' => $target->employee_id,
                'is_active' => true,
            ]
        );

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'actor_user_id' => $admin->id,
                'target_user_id' => $target->id,
                'event_type' => 'dev_user_deactivated',
                'result' => 'success',
                'is_shared_dev_identity' => true,
            ]
        );

        $this->assertNotNull($target->remember_token);

        $tokenAfterDeactivation = $target->remember_token;

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users/'
                . $target->id . '/status',
            [
                'status' => 'active',
                'reason' => 'Automated reactivation test',
            ]
        )->assertRedirect(
            route('kfos.auth.dev.users.index')
        );

        $target->refresh();

        $this->assertTrue($target->is_active);
        $this->assertSame(3, $target->session_version);

        $this->assertNotSame(
            $tokenAfterDeactivation,
            $target->remember_token
        );

        $this->assertDatabaseHas(
            'security_audit_logs',
            [
                'actor_user_id' => $admin->id,
                'target_user_id' => $target->id,
                'event_type' => 'dev_user_activated',
                'result' => 'success',
            ]
        );
    }

    public function test_duplicate_employee_id_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createTestEmployee();

        $this->loginAsAdmin($admin);

        $before = DB::table('employees')->count();

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users',
            [
                'employee_id' => $target->login_id,
                'name' => 'Duplicate Test',
                'position' => 'Test Operator',
                'password' => 'Temporary-Synthetic-Password-123!',
                'password_confirmation' =>
                    'Temporary-Synthetic-Password-123!',
            ]
        )->assertSessionHasErrors('employee_id');

        $this->assertSame(
            $before,
            DB::table('employees')->count()
        );
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->createAdmin();

        $this->loginAsAdmin($admin);

        $this->post(
            'http://localhost:8080/kfos-auth-dev/users/'
                . $admin->id . '/status',
            [
                'status' => 'inactive',
                'reason' => 'Prohibited self-deactivation test',
            ]
        )->assertForbidden();

        $admin->refresh();

        $this->assertTrue($admin->is_active);
    }
}
