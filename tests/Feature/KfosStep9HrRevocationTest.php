<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\KfosCredentialSessionIssuer;
use App\Services\Auth\KfosRememberedDeviceService;
use App\Services\Security\KfosOriginalAccountEpochGate;
use App\Services\Security\KfosOriginalHrRevocationCoordinator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic domain-level tests. Never use kfos_dev or actual HR accounts. */
final class KfosStep9HrRevocationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-S9-Domain-Password-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        $this->assertTrue(Schema::hasTable('kfos_original_hr_revocation_events'));
        $this->assertTrue(Schema::hasTable('kfos_account_security_epochs'));
        $this->assertTrue(Schema::hasColumn('kfos_remembered_devices', 'security_epoch_at_issue'));
        app()->detectEnvironment(fn () => 'local');
        config([
            'app.debug' => true,
            'app.url' => 'http://localhost:8080',
            'session.driver' => 'array',
            'kfos_step8.epoch_admission_enabled' => true,
            'kfos_step9.hr_revocation_coordinator_enabled' => true,
        ]);
    }

    private function makeAccount(bool $admin = false, bool $withEpoch = true): User
    {
        $code = 'S9_'.strtoupper(Str::random(17));
        $masterId = DB::table('employees')->insertGetId([
            'employee_id' => $code,
            'name' => 'Synthetic Step9 '.$code,
            'position' => 'Synthetic Test Role',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $accountId = DB::table('users')->insertGetId([
            'employee_id' => $masterId, 'login_id' => $code,
            'name' => 'Synthetic Step9 '.$code, 'email' => null,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'is_development_only' => false,
            'is_system_admin' => false, 'session_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($admin) {
            DB::table('kfos_system_administrators')->insert([
                'user_id' => $accountId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($withEpoch) {
            DB::table('kfos_account_security_epochs')->insert([
                'user_id' => $accountId, 'epoch' => 5,
                'revocation_pending' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return User::findOrFail($accountId);
    }

    private function asAdmin(User $admin): void
    {
        $this->actingAs($admin, 'web')
            ->withSession(['kfos_session_version' => (int) $admin->session_version]);
    }

    private function service(): KfosOriginalHrRevocationCoordinator
    {
        return app(KfosOriginalHrRevocationCoordinator::class);
    }

    private function reference(): string
    {
        return 'HR-S9-'.strtoupper(Str::random(18));
    }

    public function test_compiled_off_by_default_cannot_prepare_even_as_genuine_administrator(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        config(['kfos_step9.hr_revocation_coordinator_enabled' => false]);
        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($admin, $target->employee_id, $this->reference());
    }

    public function test_prepares_durable_original_barrier_without_changing_HR_status(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $ref = $this->reference();
        $id = $this->service()->prepare($admin, $target->employee_id, $ref);
        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('kfos_account_security_epochs', [
            'user_id' => $target->id, 'epoch' => 5,
            'revocation_pending' => true, 'original_hr_event_reference' => $ref,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $target->employee_id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_logs', [
            'target_user_id' => $target->id,
            'event_type' => 'kfos_original_hr_barrier_prepared', 'result' => 'success',
        ]);
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)->eligible($target, 5, 1));
    }

    public function test_same_original_event_retry_is_idempotent(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $ref = $this->reference();
        $first = $this->service()->prepare($admin, $target->employee_id, $ref);
        $second = $this->service()->prepare($admin, $target->employee_id, $ref);
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('kfos_original_hr_revocation_events')
            ->where('event_reference', $ref)->count());
        $this->assertSame(1, DB::table('security_audit_logs')
            ->where('target_user_id', $target->id)
            ->where('event_type', 'kfos_original_hr_barrier_prepared')->count());
    }

    public function test_pending_barrier_denies_new_credentials_without_success_login_audit(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $result = app(KfosCredentialSessionIssuer::class)->issue(
            Request::create('http://localhost:8080/synthetic-step9', 'POST'),
            $target->login_id, self::PASSWORD, false
        );
        $this->assertNull($result);
        $this->assertSame(0, DB::table('security_audit_logs')
            ->where('target_user_id', $target->id)
            ->where('event_type', 'employee_credential_login_success')->count());
    }

    public function test_commit_inactive_advances_durable_epoch_version_and_revokes_remember_grant(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $remember = app(KfosRememberedDeviceService::class);
        $token = $remember->issueAfterCredentialLogin($target, true);
        $this->assertNotNull($token);
        $this->assertNotNull($remember->validate($token));
        $eventId = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $this->assertTrue($this->service()->commitInactive($admin, $eventId));
        $this->assertDatabaseHas('employees', [
            'id' => $target->employee_id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('kfos_account_security_epochs', [
            'user_id' => $target->id, 'epoch' => 6, 'revocation_pending' => true,
        ]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'session_version' => 2]);
        $this->assertDatabaseHas('kfos_original_hr_revocation_events', [
            'id' => $eventId, 'epoch_before' => 5, 'epoch_after' => 6,
            'session_version_before' => 1, 'session_version_after' => 2,
            'remembered_grants_revoked' => 1, 'state' => 'committed_pending_proof',
        ]);
        $this->assertNull($remember->validate($token));
        $this->assertDatabaseHas('security_audit_logs', [
            'target_user_id' => $target->id,
            'event_type' => 'kfos_original_hr_employment_inactivated',
        ]);
        $this->assertDatabaseHas('security_audit_logs', [
            'target_user_id' => $target->id,
            'event_type' => 'kfos_original_remembered_grants_revoked',
        ]);
    }

    public function test_no_separate_actual_remembered_revocation_audit_when_no_grants(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $eventId = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $this->assertTrue($this->service()->commitInactive($admin, $eventId));
        $this->assertDatabaseHas('kfos_original_hr_revocation_events', [
            'id' => $eventId, 'remembered_grants_revoked' => 0,
        ]);
        $this->assertDatabaseMissing('security_audit_logs', [
            'target_user_id' => $target->id,
            'event_type' => 'kfos_original_remembered_grants_revoked',
        ]);
    }

    public function test_commit_retry_does_not_increment_or_double_audit(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $id = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $this->assertTrue($this->service()->commitInactive($admin, $id));
        $this->assertFalse($this->service()->commitInactive($admin, $id));
        $this->assertDatabaseHas('kfos_account_security_epochs', [
            'user_id' => $target->id, 'epoch' => 6,
        ]);
        $this->assertSame(1, DB::table('security_audit_logs')
            ->where('target_user_id', $target->id)
            ->where('event_type', 'kfos_original_hr_employment_inactivated')->count());
    }

    public function test_HR_active_correction_cannot_resurrect_old_grant_or_clear_barrier(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $remember = app(KfosRememberedDeviceService::class);
        $old = $remember->issueAfterCredentialLogin($target, true);
        $id = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $this->service()->commitInactive($admin, $id);
        DB::table('employees')->where('id', $target->employee_id)->update([
            'is_active' => true, 'updated_at' => now(),
        ]); // synthetic authorized correction fixture, NOT a real HR writer.
        $this->assertNull($remember->validate($old));
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)->eligible($target, 5, 1));
        $this->assertFalse(app(KfosOriginalAccountEpochGate::class)->eligible($target, 6, 2));
        $proof = $this->service()->inspectPending($admin, $id);
        $this->assertTrue($proof['barrier_pending']);
        $this->assertTrue($proof['persisted_epoch_and_session_version_match']);
        $this->assertFalse($proof['clearance_approved']);
    }

    public function test_pending_original_event_blocks_a_different_later_event(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($admin, $target->employee_id, $this->reference());
    }

    public function test_stale_epoch_fences_commit_and_leaves_original_HR_active(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $id = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        DB::table('kfos_account_security_epochs')->where('user_id', $target->id)
            ->update(['epoch' => 7, 'updated_at' => now()]);
        $this->expectException(AuthorizationException::class);
        try {
            $this->service()->commitInactive($admin, $id);
        } finally {
            $this->assertDatabaseHas('employees', [
                'id' => $target->employee_id, 'is_active' => true,
            ]);
            $this->assertDatabaseHas('kfos_account_security_epochs', [
                'user_id' => $target->id, 'revocation_pending' => true,
            ]);
        }
    }

    public function test_an_inactive_administrator_target_uses_separate_confirmed_exception(): void
    {
        $admin = $this->makeAccount(true);
        $otherAdmin = $this->makeAccount(true);
        $this->asAdmin($admin);
        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($admin, $otherAdmin->employee_id, $this->reference());
    }

    public function test_missing_original_epoch_never_silently_backfills_or_mutates_HR(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount(false, false);
        $this->asAdmin($admin);
        $this->expectException(AuthorizationException::class);
        try {
            $this->service()->prepare($admin, $target->employee_id, $this->reference());
        } finally {
            $this->assertDatabaseHas('employees', [
                'id' => $target->employee_id, 'is_active' => true,
            ]);
            $this->assertDatabaseMissing('kfos_account_security_epochs', ['user_id' => $target->id]);
        }
    }

    public function test_stale_or_unrelated_administrator_session_cannot_commit_an_original_event(): void
    {
        $admin = $this->makeAccount(true);
        $other = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $id = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $this->asAdmin($other);
        $this->expectException(AuthorizationException::class);
        try {
            $this->service()->commitInactive($other, $id);
        } finally {
            $this->assertDatabaseHas('employees', [
                'id' => $target->employee_id, 'is_active' => true,
            ]);
        }
    }

    public function test_exact_original_event_reference_cannot_be_reused_for_another_master(): void
    {
        $admin = $this->makeAccount(true);
        $first = $this->makeAccount();
        $second = $this->makeAccount();
        $this->asAdmin($admin);
        $ref = $this->reference();
        $this->service()->prepare($admin, $first->employee_id, $ref);
        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($admin, $second->employee_id, $ref);
    }

    public function test_pending_inspection_is_read_only_and_cannot_clear_a_barrier(): void
    {
        $admin = $this->makeAccount(true);
        $target = $this->makeAccount();
        $this->asAdmin($admin);
        $id = $this->service()->prepare($admin, $target->employee_id, $this->reference());
        $result = $this->service()->inspectPending($admin, $id);
        $this->assertSame($id, $result['original_event_id']);
        $this->assertSame('prepared', $result['state']);
        $this->assertTrue($result['barrier_matches_original_event']);
        $this->assertFalse($result['persisted_epoch_and_session_version_match']);
        $this->assertFalse($result['clearance_approved']);
        $this->assertDatabaseHas('kfos_account_security_epochs', [
            'user_id' => $target->id, 'revocation_pending' => true,
        ]);
    }
}
