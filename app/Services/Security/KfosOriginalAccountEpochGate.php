<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Isolated, read-only original-account epoch/barrier eligibility primitive.
 * NOT YET WIRED to HTTP sessions, remembered device issuance or HR commits.
 * A caller must supply generation and session_version stored AT ISSUE TIME.
 * Missing original records, missing epoch, DB failure => false (fail-closed).
 */
final class KfosOriginalAccountEpochGate
{
    public function __construct(private KfosAuthorization $authorization) {}

    public function eligible(User $account, int $issuedEpoch, int $issuedSessionVersion): bool
    {
        if ($issuedEpoch < 0 || $issuedSessionVersion < 1 || ! $account->exists) {
            return false;
        }
        try {
            $current = DB::table('users as u')
                ->join('employees as e', 'e.id', '=', 'u.employee_id')
                ->join('kfos_account_security_epochs as x', 'x.user_id', '=', 'u.id')
                ->where('u.id', $account->getKey())
                ->first(['u.is_active', 'u.is_development_only', 'u.session_version',
                    'e.is_active as employment_active', 'x.epoch', 'x.revocation_pending']);
            if ($current === null || ! (bool) $current->is_active
                || (bool) $current->is_development_only
                || (bool) $current->revocation_pending
                || (int) $current->session_version !== $issuedSessionVersion
                || (int) $current->epoch !== $issuedEpoch) {
                return false;
            }
            // Genuine universal administrators retain the narrowly approved
            // employment-Inactive exception, but not an epoch/barrier bypass.
            return (bool) $current->employment_active
                || $this->authorization->isSystemAdministrator($account);
        } catch (Throwable $error) {
            return false;
        }
    }
}
