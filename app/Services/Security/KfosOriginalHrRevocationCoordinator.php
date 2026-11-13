<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * STEP 9 — opt-in domain integration primitive, NOT an authorized HR writer.
 *
 * Sequence: (1) prepare a durable original-account pending barrier;
 * (2) commit an original Master Inactive transition + monotonic epoch and
 * session_version advancement, remembered-grant revoke, and immutable audits.
 * Both stages share the existing writers' PostgreSQL advisory lock. A failed
 * stage 2 leaves stage 1's durable barrier pending. The barrier is NEVER
 * cleared here: independent physical-session proof, audit recovery, original
 * source-event authority, and 0702/0705 readback/CAS remain release blockers.
 *
 * Only a genuine, current System Administrator may exercise this synthetic
 * integration primitive. This is NOT a new HR role/approval policy, and
 * production routes/authoritative HR writer are deliberately NOT mounted.
 */
final class KfosOriginalHrRevocationCoordinator
{
    public function __construct(
        private KfosAuthorization $authorization,
        private KfosRememberedDeviceService $remembered
    ) {}

    private function enabled(): void
    {
        if (config('kfos_step9.hr_revocation_coordinator_enabled', false) !== true
            || config('kfos_step8.epoch_admission_enabled', false) !== true) {
            throw new AuthorizationException('HR revocation integration is not activated.');
        }
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
    }

    private function checkActor(User $actor): void
    {
        $authenticated = Auth::guard('web')->id();
        $version = session()->get('kfos_session_version');
        $current = DB::table('users')->where('id', $actor->getKey())->lockForUpdate()->first();
        if ($authenticated === null || (int) $authenticated !== (int) $actor->getKey()
            || $version === null || $current === null
            || (int) $version !== (int) $current->session_version
            || ! $this->authorization->isSystemAdministrator($actor)) {
            throw new AuthorizationException('Current genuine System Administrator session required.');
        }
    }

    private function eventReference(string $reference): string
    {
        if (strlen($reference) < 8 || strlen($reference) > 100
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $reference) !== 1) {
            throw ValidationException::withMessages([
                'event_reference' => 'A valid non-secret original HR event reference is required.',
            ]);
        }
        return $reference;
    }

    private function audit(string $event, int $actorId, int $userId, array $detail): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $actorId,
            'target_user_id' => $userId,
            'event_type' => $event,
            'result' => 'success',
            'details' => json_encode($detail, JSON_THROW_ON_ERROR),
            'is_shared_dev_identity' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * Create a durable fail-closed barrier BEFORE an original HR status save.
     * The unique original event identity is supplied by a FUTURE independently
     * authenticated, authorized, immutable HR event source; not by a browser.
     * No production caller is provided in this bundle.
     */
    public function prepare(User $actor, int $originalEmployeeMasterId, string $originalEventReference): int
    {
        $this->enabled();
        $ref = $this->eventReference($originalEventReference);
        return DB::transaction(function () use ($actor, $originalEmployeeMasterId, $ref): int {
            $this->lock();
            $this->checkActor($actor);

            // Exact retries are idempotent; no second audit or security version.
            $existing = DB::table('kfos_original_hr_revocation_events')
                ->where('event_reference', $ref)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->employee_master_id !== $originalEmployeeMasterId
                    || (int) $existing->recorded_by_user_id !== (int) $actor->id) {
                    throw new AuthorizationException('Original event identity conflict.');
                }
                return (int) $existing->id;
            }

            $employee = DB::table('employees')->where('id', $originalEmployeeMasterId)
                ->lockForUpdate()->first();
            if ($employee === null || ! (bool) $employee->is_active) {
                throw new AuthorizationException('Original active Employee Master required.');
            }
            $target = DB::table('users')->where('employee_id', $originalEmployeeMasterId)
                ->lockForUpdate()->first();
            if ($target === null || $target->is_development_only
                || ! $target->is_active || $target->employee_id === null
                || $this->authorization->isSystemAdministrator(User::findOrFail($target->id))) {
                throw new AuthorizationException('Eligible original ordinary account required.');
            }
            $epoch = DB::table('kfos_account_security_epochs')->where('user_id', $target->id)
                ->lockForUpdate()->first();
            if ($epoch === null || (bool) $epoch->revocation_pending) {
                throw new AuthorizationException('Missing epoch or unresolved original barrier.');
            }
            if ((int) $epoch->epoch >= PHP_INT_MAX - 1
                || (int) $target->session_version >= PHP_INT_MAX - 1) {
                throw new AuthorizationException('Security generation capacity unavailable.');
            }

            // The protected marker commits BEFORE any HR status mutation.
            DB::table('kfos_account_security_epochs')->where('user_id', $target->id)
                ->update([
                    'revocation_pending' => true,
                    'original_hr_event_reference' => $ref,
                    'barrier_set_at' => now(),
                    'barrier_cleared_at' => null,
                    'updated_at' => now(),
                ]);
            $id = DB::table('kfos_original_hr_revocation_events')->insertGetId([
                'event_reference' => $ref,
                'employee_master_id' => $originalEmployeeMasterId,
                'account_user_id' => $target->id,
                'recorded_by_user_id' => $actor->id,
                'epoch_before' => $epoch->epoch,
                'session_version_before' => $target->session_version,
                'state' => 'prepared',
                'prepared_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('kfos_original_hr_barrier_prepared', (int) $actor->id,
                (int) $target->id, [
                    'original_event_reference' => $ref,
                    'employee_master_id' => (int) $employee->id,
                    'epoch_before' => (int) $epoch->epoch,
                    'barrier' => 'pending',
                    'hr_status_changed' => false,
                ]);
            return (int) $id;
        }, 3);
    }

    /**
     * Simulation-compatible status writer core. Requires later real HR source
     * provenance/authorization before ANY production connection. The only
     * allowed status transition is matching original Active -> Inactive.
     * On failure a previously committed barrier remains pending. No clearance.
     */
    public function commitInactive(User $actor, int $eventId): bool
    {
        $this->enabled();
        return DB::transaction(function () use ($actor, $eventId): bool {
            $this->lock();
            $this->checkActor($actor);
            $event = DB::table('kfos_original_hr_revocation_events')->where('id', $eventId)
                ->lockForUpdate()->first();
            if ($event === null || (int) $event->recorded_by_user_id !== (int) $actor->id) {
                throw new AuthorizationException('Original event not authorized for this actor.');
            }
            if ($event->state === 'committed_pending_proof') {
                return false; // Accurate idempotent retry: no new HR event or audit.
            }
            if ($event->state !== 'prepared') {
                throw new AuthorizationException('Original revocation state is invalid.');
            }

            $employee = DB::table('employees')->where('id', $event->employee_master_id)
                ->lockForUpdate()->first();
            $user = DB::table('users')->where('id', $event->account_user_id)
                ->lockForUpdate()->first();
            $epoch = DB::table('kfos_account_security_epochs')->where('user_id', $event->account_user_id)
                ->lockForUpdate()->first();
            $valid = $employee !== null && (bool) $employee->is_active
                && $user !== null && (int) $user->employee_id === (int) $employee->id
                && (bool) $user->is_active && ! (bool) $user->is_development_only
                && $epoch !== null && (bool) $epoch->revocation_pending
                && hash_equals((string) $event->event_reference,
                    (string) $epoch->original_hr_event_reference)
                && (int) $epoch->epoch === (int) $event->epoch_before
                && (int) $user->session_version === (int) $event->session_version_before;
            if (! $valid || $this->authorization->isSystemAdministrator(User::findOrFail($user->id))) {
                throw new AuthorizationException('Original account, barrier or generation changed.');
            }

            $newEpoch = (int) $epoch->epoch + 1;
            $newVersion = (int) $user->session_version + 1;
            DB::table('employees')->where('id', $employee->id)->update([
                'is_active' => false, 'updated_at' => now(),
            ]);
            DB::table('kfos_account_security_epochs')->where('user_id', $user->id)
                ->update(['epoch' => $newEpoch, 'updated_at' => now()]);
            DB::table('users')->where('id', $user->id)->update([
                'session_version' => $newVersion, 'updated_at' => now(),
            ]);
            $actualRevoked = $this->remembered->revokeAllForUser((int) $user->id);
            DB::table('kfos_original_hr_revocation_events')->where('id', $eventId)->update([
                'epoch_after' => $newEpoch,
                'session_version_after' => $newVersion,
                'remembered_grants_revoked' => $actualRevoked,
                'state' => 'committed_pending_proof',
                'committed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('kfos_original_hr_employment_inactivated', (int) $actor->id,
                (int) $user->id, [
                    'original_event_reference' => $event->event_reference,
                    'employee_master_id' => (int) $employee->id,
                    'before' => ['employment_active' => true,
                        'epoch' => (int) $epoch->epoch],
                    'after' => ['employment_active' => false,
                        'epoch' => $newEpoch],
                    'revocation_barrier' => 'PENDING_INDEPENDENT_PROOF',
                    'remembered_grants_actually_revoked' => $actualRevoked,
                    'physical_session_deletion_verified' => false,
                ]);
            if ($actualRevoked > 0) {
                // Never fabricate an account-level forced-logout audit at zero.
                $this->audit('kfos_original_remembered_grants_revoked',
                    (int) $actor->id, (int) $user->id, [
                        'original_event_reference' => $event->event_reference,
                        'actually_revoked_grants' => $actualRevoked,
                        'physical_sessions_removed' => 'UNVERIFIED',
                    ]);
            }
            return true;
        }, 3);
    }

    /**
     * Read-only durable readback for authorized reconciliation. It never clears
     * a barrier, even when an epoch advance is visible: missing original audit,
     * physical sessions, independently verified proof or newer HR events must
     * fail closed. No false clearance event is generated.
     */
    public function inspectPending(User $actor, int $eventId): array
    {
        $this->enabled();
        return DB::transaction(function () use ($actor, $eventId): array {
            $this->lock();
            $this->checkActor($actor);
            $event = DB::table('kfos_original_hr_revocation_events')->where('id', $eventId)
                ->lockForUpdate()->first();
            if ($event === null) {
                throw new AuthorizationException('Original event unavailable.');
            }
            $epoch = DB::table('kfos_account_security_epochs')
                ->where('user_id', $event->account_user_id)->lockForUpdate()->first();
            $user = DB::table('users')->where('id', $event->account_user_id)
                ->lockForUpdate()->first();
            $same = $epoch !== null && $user !== null
                && (bool) $epoch->revocation_pending
                && (string) $epoch->original_hr_event_reference === (string) $event->event_reference;
            $advance = $same && $event->epoch_after !== null
                && (int) $epoch->epoch === (int) $event->epoch_after
                && (int) $user->session_version === (int) $event->session_version_after;
            return [
                'original_event_id' => (int) $event->id,
                'state' => (string) $event->state,
                'barrier_matches_original_event' => $same,
                'persisted_epoch_and_session_version_match' => (bool) $advance,
                'barrier_pending' => $epoch !== null && (bool) $epoch->revocation_pending,
                'clearance_approved' => false,
                'why_not' => 'Independent permanent token rejection, physical session cleanup, immutable audit and newer-event fencing not verified',
            ];
        });
    }
}
