<?php

namespace App\Console\Commands;

use App\Services\Security\KfosInitialAdministratorBootstrap;
use Illuminate\Console\Command;
use Throwable;

/** AUTH-005/091: restricted, interactive FIRST bootstrap, never recovery. */
final class KfosFirstAdministratorInitialize extends Command
{
    protected $signature = 'kfos:bootstrap:first-administrator
        {--execute : Explicitly request the one-time write operation}
        {--employee-id= : Company-designated real Employee ID}
        {--operator-ref= : Company-verified named technical operator reference}
        {--authorization-ref= : Company authorization record reference}
        {--acknowledge= : Must equal FIRST-BOOTSTRAP-NOT-RECOVERY}';

    protected $description = 'Restricted initial genuine System Administrator provisioning (AUTH-005/091)';

    public function handle(KfosInitialAdministratorBootstrap $service): int
    {
        // No output of passwords, hashes, mailbox contents or reset credentials.
        if (! $this->option('execute')) {
            $this->error('REFUSED: no execution flag. This command does not run from the Step 7 batch.');
            return self::FAILURE;
        }
        // The test suite invokes the domain service directly inside kfos_test.
        // The operational CLI is restricted to a separately prepared production
        // server; a local/kfos_test execution cannot provision a fake first admin.
        if (! app()->environment('production') || config('app.debug') !== false) {
            $this->error('REFUSED: controlled production server with APP_DEBUG=false required.');
            return self::FAILURE;
        }
        if ((string) $this->option('acknowledge') !== 'FIRST-BOOTSTRAP-NOT-RECOVERY') {
            $this->error('REFUSED: irreversible first-bootstrap confirmation missing.');
            return self::FAILURE;
        }
        $employeeId = trim((string) $this->option('employee-id'));
        $operatorRef = trim((string) $this->option('operator-ref'));
        $authorizationRef = trim((string) $this->option('authorization-ref'));
        if ($employeeId === '' || $operatorRef === '' || $authorizationRef === '') {
            $this->error('REFUSED: named real employee, operator and authorization references required.');
            return self::FAILURE;
        }
        $this->warn('Company authorization MUST be independently verified outside this command.');
        $this->warn('This initializes the FIRST administrator only; never use for account recovery.');
        if (! $this->confirm('Have you verified the actual named employee and authorized operator with company records?', false)) {
            $this->error('REFUSED: company verification not confirmed.');
            return self::FAILURE;
        }
        if (! $this->confirm('Is this the FIRST bootstrap with NO prior production administrator?', false)) {
            $this->error('REFUSED: not an initial bootstrap.');
            return self::FAILURE;
        }
        $password = $this->secret('Enter initial password (hidden; not supplied as CLI argument)');
        $confirmation = $this->secret('Confirm initial password (hidden)');
        if (! is_string($password) || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('REFUSED: password confirmation failed.');
            return self::FAILURE;
        }
        try {
            $user = $service->initialize($employeeId, $operatorRef, $authorizationRef, $password);
        } catch (Throwable $e) {
            $this->error('REFUSED: first administrator bootstrap did not complete; review secure server logs.');
            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }
        $this->info('FIRST ADMIN BOOTSTRAP COMMITTED for original user ID '.$user->getKey().'.');
        $this->warn('NO production readiness implied. AUTH-092 verified email reset and audit/HR barriers remain required.');
        return self::SUCCESS;
    }
}
