<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class KfosSecurityReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('kfos_test', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }

    public function test_readiness_never_reports_production_approval(): void
    {
        $this->assertSame(0, Artisan::call('kfos:security:readiness', ['--json' => true]));
        $out = Artisan::output();
        $report = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['production_release_approved']);
        $this->assertContains($report['result'], ['NOT_READY','BLOCKED']);
        $this->assertSame('UNVERIFIED',
            $report['checks']['AUTH_086_092_verified_company_email_and_reset_test']);
        $this->assertSame('NOT_IMPLEMENTED_OR_UNVERIFIED',
            $report['checks']['CDR_0693_0716_durable_epochs_barriers_audit_recovery']);
    }

    public function test_nonexistent_employee_cannot_pass_bootstrap_inspection(): void
    {
        $missing = 'T6_MISSING_'.strtoupper(Str::random(16));
        $before = DB::table('kfos_system_administrators')->count();
        $this->assertSame(1, Artisan::call('kfos:bootstrap:inspect', [
            'employee_id' => $missing, '--operator' => 'Company designated operator not yet verified',
        ]));
        $this->assertStringContainsString('BLOCKED', Artisan::output());
        $this->assertSame($before, DB::table('kfos_system_administrators')->count());
    }

    public function test_bootstrap_inspection_rejects_missing_named_operator(): void
    {
        $this->assertSame(1, Artisan::call('kfos:bootstrap:inspect', [
            'employee_id' => 'UNSPECIFIED', '--operator' => '',
        ]));
    }
}
