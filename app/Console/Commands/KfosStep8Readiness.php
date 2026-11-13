<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Reporting is not deployment authorization; no changes to any account. */
final class KfosStep8Readiness extends Command
{
    protected $signature = 'kfos:security:step8-readiness {--json : Emit machine-readable report}';
    protected $description = 'Read-only Step 8 epoch-admission readiness and blocked production gates';

    public function handle(): int
    {
        $checks = [
            'step8_epoch_admission_automatic_activation' =>
                config('kfos_step8.epoch_admission_enabled', false) ? 'EXPLICIT_TEST_OPT_IN' : 'OFF_BY_DEFAULT',
            'kfos_step8_epoch_route_activation' => 'NOT_ACTIVATED',
            'CDR_0646_0716_original_HR_writer_and_commit_barrier' => 'NOT_INTEGRATED',
            'CDR_0670_physical_session_deletion_retry_and_proof' => 'NOT_IMPLEMENTED',
            'CDR_0702_0705_immutable_clearance_fencing_and_reconciliation' => 'NOT_IMPLEMENTED',
            'CDR_0714_denied_issuance_audit_failure_fail_closed' => 'EXISTING_ISSUER_POLICY_MUST_BE_VALIDATED',
            'CDR_0716_SO_Production_double_audit_outage_gate' => 'NOT_INTEGRATED',
            'AUTH_086_099_verified_company_email_reset_and_ownership_workflow' => 'NOT_IMPLEMENTED',
            'AUTH_091_company_first_admin_and_operator_evidence' => 'EXTERNAL_EVIDENCE_REQUIRED',
            'AUTH_092_email_delivery_and_reset_e2e_release_gate' => 'NOT_VERIFIED',
        ];
        try {
            $checks['step7_epoch_schema'] = Schema::hasTable('kfos_account_security_epochs')
                ? 'PRESENT' : 'MISSING';
            $checks['step8_remember_epoch_column'] = Schema::hasColumn(
                'kfos_remembered_devices', 'security_epoch_at_issue') ? 'PRESENT' : 'MISSING';
        } catch (Throwable $e) {
            $checks['step7_epoch_schema'] = 'UNAVAILABLE';
            $checks['step8_remember_epoch_column'] = 'UNAVAILABLE';
        }
        $report = [
            'step' => 8,
            'result' => 'NOT_READY',
            'production_release_approved' => false,
            'at_utc' => now('UTC')->toIso8601String(),
            'checks' => $checks,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('STEP 8 PRODUCTION SECURITY: NOT READY');
            foreach ($checks as $key => $status) {
                $this->line($key.': '.$status);
            }
        }
        return self::SUCCESS;
    }
}
