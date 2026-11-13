<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only inventory, never an approval or automated activation. */
final class KfosStep9Readiness extends Command
{
    protected $signature = 'kfos:security:step9-readiness {--json : Report JSON}';
    protected $description = 'Read-only Step 9 HR revocation/barrier readiness inventory';

    public function handle(): int
    {
        $checks = [
            'coordinator_activation' => config('kfos_step9.hr_revocation_coordinator_enabled', false)
                ? 'TEST_OPT_IN_ONLY' : 'OFF_BY_DEFAULT',
            'epoch_admission_default' => config('kfos_step8.epoch_admission_enabled', false)
                ? 'EXPLICIT_TEST_OPT_IN' : 'OFF_BY_DEFAULT',
            'authoritative_HR_event_source_and_approved_actors' => 'NOT_CONNECTED',
            'authorized_HR_write_route' => 'NOT_MOUNTED',
            'original_HR_event_identity_proven_by_source' => 'UNVERIFIED',
            'physical_server_session_deletion_retry_and_proof' => 'NOT_IMPLEMENTED',
            'original_barrier_clearance_CDR_0702_0705' => 'NOT_IMPLEMENTED',
            'pending_barrier_audit_recovery_CDR_0714_0716' => 'NOT_INTEGRATED',
            'recovery_email_AUTH_086_099' => 'NOT_IMPLEMENTED',
            'production_release_approved' => 'NO',
        ];
        try {
            $checks['original_epoch_table'] = Schema::hasTable('kfos_account_security_epochs')
                ? 'PRESENT' : 'MISSING';
            $checks['original_revocation_ledger_table'] = Schema::hasTable('kfos_original_hr_revocation_events')
                ? 'PRESENT' : 'MISSING';
        } catch (Throwable $ignored) {
            $checks['original_epoch_table'] = 'UNAVAILABLE';
            $checks['original_revocation_ledger_table'] = 'UNAVAILABLE';
        }
        $report = [
            'step' => 9, 'result' => 'NOT_READY', 'production_release_approved' => false,
            'checked_at_utc' => now('UTC')->toIso8601String(),
            'checks' => $checks,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('STEP 9 SECURITY READINESS: NOT READY (never release approval)');
            foreach ($checks as $name => $value) {
                $this->line($name.': '.$value);
            }
        }
        return self::SUCCESS;
    }
}
