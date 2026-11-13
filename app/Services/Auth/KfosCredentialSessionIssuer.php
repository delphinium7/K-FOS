<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Http\Controllers\Auth\KfosRememberBrowserController;
use App\Services\Security\KfosAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LOCAL STAGING INTEGRATION, not a production route activation.
 * The credential check, authoritative last read, mandatory success audit,
 * remembered-device grant and web-session attachment share one transaction.
 * Row locks serialize with changes to the original user/employee rows.
 * Full HR-event epoch/barrier, audit outage recovery, and deployed session
 * storage proof remain separate release blockers (CDR-0693--0716).
 */
final class KfosCredentialSessionIssuer
{
    public function __construct(
        private KfosEmployeeLoginSecurity $security,
        private KfosAuthorization $authorization,
        private KfosRememberedDeviceService $remembered
    ) {}

    /** @return array{user_id:int,token:?string}|null */
    public function issue(
        Request $request,
        string $employeeId,
        string $password,
        bool $remember = false
    ): ?array {
        $attachedSession = false;
        try {
            return DB::transaction(function () use (
                $request, $employeeId, $password, $remember, &$attachedSession
            ): ?array {
                // Match existing transactional role/admin writers. This lock is
                // not a replacement for the independently required HR barrier.
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);

                // Inner verification writes failed-attempt audit but does NOT
                // claim a successful credential login before final issuance.
                $verified = $this->security->verifyCredentials($employeeId, $password, false);
                if ($verified === null) {
                    return null;
                }

                $fresh = User::query()->whereKey($verified->getKey())
                    ->lockForUpdate()->first();
                $employee = $fresh?->employee_id === null ? null : DB::table('employees')
                    ->where('id', $fresh->employee_id)->lockForUpdate()->first();
                $state = $fresh === null ? null : DB::table('kfos_login_security_states')
                    ->where('user_id', $fresh->getKey())->lockForUpdate()->first();

                $eligible = $fresh !== null
                    && $fresh->is_active
                    && ! $fresh->is_development_only
                    && $employee !== null
                    && $state !== null
                    && $state->restricted_at === null
                    && (int) $fresh->session_version === (int) $verified->session_version
                    && ((bool) $employee->is_active
                        || $this->authorization->isSystemAdministrator($fresh));

                if (! $eligible) {
                    // This is a true post-credential eligibility denial, not
                    // a wrong password and not a fifth-failure increment.
                    DB::table('security_audit_logs')->insert([
                        'actor_user_id' => null,
                        'target_user_id' => $verified->getKey(),
                        'event_type' => 'employee_login_session_issuance_denied',
                        'result' => 'failed',
                        'details' => json_encode(['reason' => 'final_eligibility_mismatch'], JSON_THROW_ON_ERROR),
                        'is_shared_dev_identity' => false,
                        'created_at' => now(),
                    ]);
                    return null;
                }

                // A successful final issuance (not merely a correct password)
                // clears the incomplete 1–4 failure counter under the same lock.
                DB::table('kfos_login_security_states')
                    ->where('user_id', $fresh->getKey())->update([
                        'failed_attempts' => 0,
                        'updated_at' => now(),
                    ]);

                // Revocation and replacement are part of the same transaction
                // as the new remembered credential and the successful audit.
                $previous = $request->cookie(KfosRememberBrowserController::COOKIE);
                if (is_string($previous)) {
                    $this->remembered->revokeOne($previous);
                }
                $token = $this->remembered->issueAfterCredentialLogin($fresh, $remember);
                if ($remember && $token === null) {
                    throw new \RuntimeException('Remembered-device eligibility not verified.');
                }

                DB::table('security_audit_logs')->insert([
                    'actor_user_id' => $fresh->getKey(),
                    'target_user_id' => $fresh->getKey(),
                    'event_type' => 'employee_credential_login_success',
                    'result' => 'success',
                    'is_shared_dev_identity' => false,
                    'created_at' => now(),
                ]);

                // If commit fails after attaching the guard, catch below
                // invalidates this candidate session; never return success.
                $attachedSession = true;
                Auth::guard('web')->login($fresh, false);
                $request->session()->regenerate();
                $request->session()->forget('kfos_remember_restored_hash');
                $request->session()->put('kfos_session_version', (int) $fresh->session_version);

                return ['user_id' => (int) $fresh->getKey(), 'token' => $token];
            }, 3);
        } catch (Throwable $error) {
            if ($attachedSession) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            throw $error; // Cannot transform a failed audit/commit into success.
        }
    }
}
