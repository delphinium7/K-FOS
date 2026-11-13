<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only readiness inventory. A successful command is NEVER production approval.
 * Human verification is mandatory for actual identity, operator and email evidence.
 */
final class KfosSecurityReadiness extends Command
{
    protected $signature = 'kfos:security:readiness {--json : Print machine-readable results}';
    protected $description = 'K-FOS read-only security go-live blocker inventory';

    public function handle(): int
    {
        $checks = [];
        $required = [
            'employees', 'users', 'kfos_roles', 'kfos_permissions',
            'kfos_system_administrators', 'kfos_login_security_states',
            'kfos_login_restriction_notifications', 'kfos_remembered_devices',
            'security_audit_logs',
        ];
        try {
            foreach ($required as $table) {
                $checks['schema_'.$table] = Schema::hasTable($table) ? 'PASS' : 'BLOCKED';
            }
            if (! in_array('BLOCKED', $checks, true)) {
                $count = DB::table('kfos_system_administrators as a')
                    ->join('users as u', 'u.id', '=', 'a.user_id')
                    ->join('employees as e', 'e.id', '=', 'u.employee_id')
                    ->where('u.is_active', true)
                    ->where('u.is_development_only', false)
                    ->where('e.is_active', true)
                    ->whereExists(static function ($q): void {
                        $q->selectRaw('1')->from('kfos_roles as r')
                            ->where('r.code', 'system_administrator')
                            ->where('r.kind', 'universal')->where('r.is_active', true);
                    })->count();
                $checks['genuine_current_active_administrators_present'] = $count > 0 ? 'PASS' : 'BLOCKED';
            } else {
                $checks['genuine_current_active_administrators_present'] = 'BLOCKED';
            }
        } catch (\Throwable $e) {
            // No exception messages or credentials in a report destined for sharing.
            $checks['database_and_schema_read'] = 'BLOCKED';
        }

        // None of these can be proven from one database query or a completed UI test.
        $checks['AUTH_091_named_person_and_authorized_operator_evidence'] = 'UNVERIFIED';
        $checks['AUTH_005_protected_initial_admin_bootstrap_execution'] = 'UNVERIFIED';
        $checks['AUTH_086_092_verified_company_email_and_reset_test'] = 'UNVERIFIED';
        $checks['CDR_0693_0716_durable_epochs_barriers_audit_recovery'] = 'NOT_IMPLEMENTED_OR_UNVERIFIED';
        $checks['production_routes_security_review_and_deployment'] = 'UNVERIFIED';
        $status = in_array('BLOCKED', $checks, true) ? 'BLOCKED' : 'NOT_READY';
        $report = ['result' => $status, 'production_release_approved' => false,
            'checked_at_utc' => now('UTC')->toIso8601String(), 'checks' => $checks];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('K-FOS SECURITY READINESS: '.$status.' (never automatic release authorization)');
            foreach ($checks as $name => $value) $this->line($name.': '.$value);
        }
        // Command ran successfully; NOT a ready-to-deploy exit code.
        return self::SUCCESS;
    }
}
