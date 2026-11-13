<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\KfosOriginalAccountEpochGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Test ONLY the isolated read-only primitive, NOT live HR event integration. */
final class KfosStep7EpochGateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    private function original(bool $employmentActive = true, bool $admin = false): User
    {
        $id = 'S7EPOCH_'.strtoupper(Str::random(12));
        $pk = DB::table('employees')->insertGetId([
            'employee_id' => $id, 'name' => 'Synthetic',
            'position' => 'Test operator', 'is_active' => $employmentActive,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'employee_id' => $pk, 'login_id' => $id, 'name' => 'Synthetic',
            'email' => null, 'password' => Hash::make('Synthetic-Password-2026!'),
            'is_active' => true, 'is_development_only' => false,
            'is_system_admin' => false, 'session_version' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return User::findOrFail($userId);
    }

    private function insertEpoch(User $user, int $epoch = 0, bool $barrier = false): void
    {
        DB::table('kfos_account_security_epochs')->insert([
            'user_id' => $user->id, 'epoch' => $epoch,
            'revocation_pending' => $barrier,
            'original_hr_event_reference' => $barrier ? 'SYNTHETIC_HR_EVENT' : null,
            'barrier_set_at' => $barrier ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_missing_epoch_fails_closed(): void
    {
        $user = $this->original();
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)
            ->eligible($user, 0, 4));
    }

    public function test_matching_original_state_allows_only_when_generation_matches(): void
    {
        $user = $this->original();
        $this->insertEpoch($user, 8);
        $gate = app(KfosOriginalAccountEpochGate::class);
        $this->assertTrue($gate->eligible($user, 8, 4));
        $this->assertFalse($gate->eligible($user, 7, 4));
        $this->assertFalse($gate->eligible($user, 8, 3));
    }

    public function test_pending_barrier_blocks_all_even_if_original_master_is_active(): void
    {
        $user = $this->original();
        $this->insertEpoch($user, 3, true);
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)
            ->eligible($user, 3, 4));
    }

    public function test_new_epoch_permanently_rejects_old_issued_generation(): void
    {
        $user = $this->original();
        $this->insertEpoch($user, 1);
        $gate = app(KfosOriginalAccountEpochGate::class);
        $this->assertTrue($gate->eligible($user, 1, 4));
        DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
            ->update(['epoch' => 2, 'updated_at' => now()]);
        $this->assertFalse($gate->eligible($user, 1, 4));
        $this->assertTrue($gate->eligible($user, 2, 4));
    }

    public function test_ordinary_inactive_original_master_denied(): void
    {
        $user = $this->original(false);
        $this->insertEpoch($user);
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)
            ->eligible($user, 0, 4));
    }

    public function test_genuine_administrator_inactive_original_master_exception(): void
    {
        $user = $this->original(false, true);
        $this->insertEpoch($user);
        $gate = app(KfosOriginalAccountEpochGate::class);
        $this->assertTrue($gate->eligible($user, 0, 4));
        DB::table('kfos_system_administrators')->where('user_id', $user->id)->delete();
        $this->assertFalse($gate->eligible($user, 0, 4));
    }

    public function test_inactive_login_account_is_denied_independently(): void
    {
        $user = $this->original();
        $this->insertEpoch($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)
            ->eligible($user, 0, 4));
    }
}
