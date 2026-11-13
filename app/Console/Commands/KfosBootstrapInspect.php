<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH-091 prerequisite inspection ONLY. Does not create users, credentials,
 * roles, emails, permissions, audit-success events or initial administrators.
 * --operator is a claim; company authorization must be verified externally.
 */
final class KfosBootstrapInspect extends Command
{
    protected $signature = 'kfos:bootstrap:inspect
        {employee_id : Company-designated Employee ID, not a password}
        {--operator= : Company-designated technical operator reference}';
    protected $description = 'Read-only named first-admin bootstrap prerequisite inspection';

    public function handle(): int
    {
        $id = (string) $this->argument('employee_id');
        $operator = trim((string) $this->option('operator'));
        if ($id === '' || mb_strlen($id) > 100 || $operator === '' || mb_strlen($operator) > 150) {
            $this->error('BLOCKED: real Employee ID and a named operator reference are required.');
            return self::FAILURE;
        }
        foreach (['employees','users','kfos_system_administrators','kfos_roles'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('BLOCKED: required security schema is not installed.');
                return self::FAILURE;
            }
        }
        $employee = DB::table('employees')->where('employee_id', $id)->first(['id','is_active']);
        $account = $employee === null ? null : DB::table('users')
            ->where('employee_id', $employee->id)
            ->where('is_development_only', false)
            ->first(['id','login_id','is_active']);
        $existing = DB::table('kfos_system_administrators')->count();
        $eligible = $employee !== null && (bool) $employee->is_active
            && $account !== null && (bool) $account->is_active
            && hash_equals($id, (string) $account->login_id) && $existing === 0;
        $this->line('K-FOS INITIAL BOOTSTRAP INSPECT: '.($eligible ? 'PRECONDITIONS FOUND' : 'BLOCKED'));
        $this->line('Named operator identity/authorization: UNVERIFIED (argument is not authentication)');
        $this->line('Original Employee Master and existing account eligibility: '.($eligible ? 'FOUND' : 'MISSING_OR_INELIGIBLE'));
        $this->line('Actual protected CLI bootstrap: NOT EXECUTED');
        $this->line('Email recovery enrollment / verification: NOT VERIFIED');
        return $eligible ? self::SUCCESS : self::FAILURE;
    }
}
