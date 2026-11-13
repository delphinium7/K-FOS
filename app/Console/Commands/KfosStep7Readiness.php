<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only evidence inventory: this command NEVER approves deployment. */
final class KfosStep7Readiness extends Command
{
    protected $signature = 'kfos:security:step7-readiness {--json : Print machine-readable result}';
    protected $description = 'Read-only Step 7 release blockers (all production gates remain independent)';

    public function handle(): int
    {
        $checks = [];
        foreach ([
            'kfos_first_admin_bootstrap_events',
            'kfos_admin_recovery_contacts',
            'kfos_account_security_epochs',
            'security_audit_logs',
            'kfos_system_administrators',
        ] as $table) {
            try {
                $checks['schema_'.$table] = Schema::hasTable($table) ? 'PRESENT' : 'MISSING';
            } catch (Throwable $error) {
                $checks['schema_'.$table] = 'UNAVAILABLE';
            }
        }
        if ($checks['schema_kfos_first_admin_bootstrap_events'] === 'PRESENT') {
            try {
                $checks['AUTH_005_first_bootstrap_receipt'] =
                    DB::table('kfos_first_admin_bootstrap_events')->where('id', 1)->exists()
                    ? 'RECEIPT_FOUND_REQUIRES_INDEPENDENT_VERIFICATION' : 'NOT_EXECUTED';
            } catch (Throwable $e) {
                $checks['AUTH_005_first_bootstrap_receipt'] = 'UNAVAILABLE';
            }
        } else {
            $checks['AUTH_005_first_bootstrap_receipt'] = 'NOT_VERIFIED';
        }
        if ($checks['schema_kfos_admin_recovery_contacts'] === 'PRESENT') {
            try {
                $checks['AUTH_092_verified_admin_email_records'] =
                    (string) DB::table('kfos_admin_recovery_contacts')
                        ->whereNotNull('verified_company_email')->whereNotNull('verified_at')->count();
            } catch (Throwable $e) {
                $checks['AUTH_092_verified_admin_email_records'] = 'UNAVAILABLE';
            }
        } else {
            $checks['AUTH_092_verified_admin_email_records'] = 'NOT_VERIFIED';
        }
        $checks['AUTH_091_company_designation_and_operator_authority'] = 'EXTERNAL_EVIDENCE_REQUIRED';
        $checks['AUTH_086_089_email_enrollment_reset_routes_delivery'] = 'NOT_ACTIVATED';
        $checks['AUTH_092_reset_delivery_reuse_session_revocation_e2e'] = 'NOT_VERIFIED';
        $checks['AUTH_090_emergency_recovery'] = 'NOT_ALLOWED';
        $checks['CDR_0646_0716_hr_event_epoch_barrier_full_integration'] = 'NOT_IMPLEMENTED';
        $checks['CDR_0714_0716_audit_recovery_and_status_change_gates'] = 'NOT_IMPLEMENTED';
        $checks['production_security_audit_and_release_approval'] = 'NOT_VERIFIED';
        $report = [
            'step' => 7,
            'result' => 'NOT_READY',
            'production_release_approved' => false,
            'at_utc' => now('UTC')->toIso8601String(),
            'checks' => $checks,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('STEP 7 PRODUCTION SECURITY: NOT READY');
            foreach ($checks as $key => $value) {
                $this->line($key.': '.$value);
            }
        }
        return self::SUCCESS; // Running diagnostics succeeded, NOT production ready.
    }
}
