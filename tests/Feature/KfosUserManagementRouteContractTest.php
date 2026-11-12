<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class KfosUserManagementRouteContractTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'http://localhost:8080/kfos/security/users';
    private const PASSWORD = 'Synthetic-5AF-Initial-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        config(['session.driver' => 'array', 'app.url' => 'http://localhost:8080']);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        require base_path('routes/kfos-security-users.php');
    }

    private function employee(bool $admin = false, bool $dev = false): User
    {
        $code = 'T5AF_'.strtoupper(Str::random(14));
        $pk = $dev ? null : DB::table('employees')->insertGetId([
            'employee_id' => $code,
            'name' => 'Synthetic Existing Employee',
            'position' => 'Synthetic Operator',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User();
        $user->forceFill([
            'employee_id' => $pk, 'login_id' => $code,
            'name' => 'Synthetic Existing Employee', 'email' => null,
            'password' => Hash::make(self::PASSWORD),
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

    private function unregisteredEmployee(): object
    {
        $code = 'T5AF_'.strtoupper(Str::random(14));
        $pk = DB::table('employees')->insertGetId([
            'employee_id' => $code,
            'name' => 'Synthetic Existing Employee',
            'position' => 'Synthetic Operator',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('employees')->where('id', $pk)->first();
    }

    private function role(): int
    {
        return DB::table('kfos_roles')->insertGetId([
            'code' => 't5af_'.strtolower(Str::random(15)),
            'name' => 'T5AF Role '.strtoupper(Str::random(12)),
            'kind' => 'operational', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(User $actor, int $version = 1): void
    {
        $this->actingAs($actor, 'web')
            ->withSession(['kfos_session_version' => $version]);
    }

    private function payload(object $employee, int $roleId): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'name' => $employee->name,
            'position' => $employee->position,
            'role_id' => $roleId,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    public function test_genuine_administrator_creates_active_user_over_protected_http(): void
    {
        $admin = $this->employee(true);
        $master = $this->unregisteredEmployee();
        $roleId = $this->role();
        $this->login($admin);
        $response = $this->postJson(self::BASE, $this->payload($master, $roleId))
            ->assertCreated()->assertJson(['employee_id' => $master->employee_id, 'status' => 'active']);
        $newUserId = (int) $response->json('user_id');
        $this->assertGreaterThan(0, $newUserId);
        $this->assertDatabaseHas('kfos_operational_role_assignments', [
            'user_id' => $newUserId, 'role_id' => $roleId,
        ]);
        $this->assertDatabaseHas('security_audit_logs', [
            'actor_user_id' => $admin->id, 'target_user_id' => $newUserId,
            'event_type' => 'kfos_employee_account_created', 'result' => 'success',
        ]);
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
    }

    public function test_ordinary_employee_cannot_create_user(): void
    {
        $ordinary = $this->employee();
        $master = $this->unregisteredEmployee();
        $roleId = $this->role();
        $this->login($ordinary);
        $this->postJson(self::BASE, $this->payload($master, $roleId))->assertForbidden();
        $this->assertDatabaseMissing('users', ['login_id' => $master->employee_id]);
    }

    public function test_stale_administrator_session_cannot_create_user(): void
    {
        $admin = $this->employee(true);
        $master = $this->unregisteredEmployee();
        $roleId = $this->role();
        $this->login($admin, 0);
        $this->postJson(self::BASE, $this->payload($master, $roleId))->assertForbidden();
        $this->assertDatabaseMissing('users', ['login_id' => $master->employee_id]);
    }

    public function test_shared_development_identity_cannot_create_real_user(): void
    {
        $dev = $this->employee(false, true);
        $master = $this->unregisteredEmployee();
        $roleId = $this->role();
        $this->login($dev);
        $this->postJson(self::BASE, $this->payload($master, $roleId))->assertForbidden();
        $this->assertDatabaseMissing('users', ['login_id' => $master->employee_id]);
    }

    public function test_unknown_employee_master_is_rejected(): void
    {
        $admin = $this->employee(true);
        $roleId = $this->role();
        $this->login($admin);
        $unknown = (object) [
            'employee_id' => 'T5AF_'.strtoupper(Str::random(12)),
            'name' => 'Synthetic Existing Employee', 'position' => 'Synthetic Operator',
        ];
        $this->postJson(self::BASE, $this->payload($unknown, $roleId))
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertDatabaseMissing('users', ['login_id' => $unknown->employee_id]);
    }

    public function test_missing_credentials_returns_unauthenticated(): void
    {
        $master = $this->unregisteredEmployee();
        $roleId = $this->role();
        $this->postJson(self::BASE, $this->payload($master, $roleId))
            ->assertUnauthorized();
        $this->assertDatabaseMissing('users', ['login_id' => $master->employee_id]);
    }

    public function test_account_status_adapter_uses_existing_protected_service(): void
    {
        $admin = $this->employee(true);
        $target = $this->employee();
        $this->login($admin);
        $this->postJson(self::BASE.'/'.$target->id.'/status', [
            'status' => 'inactive', 'reason' => 'Synthetic HTTP integration verification',
        ])->assertOk()->assertJson(['changed' => true, 'status' => 'inactive']);
        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertSame(2, $target->session_version);
        $this->assertDatabaseHas('security_audit_logs', [
            'actor_user_id' => $admin->id, 'target_user_id' => $target->id,
            'event_type' => 'kfos_employee_account_deactivated', 'result' => 'success',
        ]);
    }
}
