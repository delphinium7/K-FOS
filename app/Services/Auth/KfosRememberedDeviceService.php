<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Security\KfosAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Storage and validation for device-specific, non-rolling remembered credentials.
 * Not itself a cookie/session guard: an HTTP adapter must issue an encrypted,
 * HttpOnly, SameSite cookie and enforce grant expiry on every protected request.
 */
final class KfosRememberedDeviceService
{
    public const DAYS = 90;

    public function __construct(private KfosAuthorization $authorization) {}

    private function employmentEligible(User $user): bool
    {
        if ($user->employee_id === null) {
            return false;
        }
        $employee = DB::table('employees')->where('id', $user->employee_id)->first(['is_active']);
        return $employee !== null && ((bool) $employee->is_active
            || $this->authorization->isSystemAdministrator($user));
    }

    /**
     * Call ONLY after a successful fresh Employee ID/password login.
     * Returns an opaque bearer credential ONCE; never persist or log plaintext.
     * Unchecked opt-in generates no credential.
     */
    public function issueAfterCredentialLogin(User $user, bool $remember): ?string
    {
        if (! $remember) {
            return null;
        }

        // Fresh authoritative state: refuse synthetic/shared, inactive or unlinked identities.
        $fresh = User::query()->find($user->id);
        if ($fresh === null || ! $fresh->is_active || $fresh->is_development_only ||
            $fresh->employee_id === null || ! $this->employmentEligible($fresh)) {
            return null;
        }

        // Step 8: opt-in remembered grant cannot outlive its issuing epoch.
        $epochAtIssue = null;
        if (config('kfos_step8.epoch_admission_enabled', false)) {
            $epochState = DB::table('kfos_account_security_epochs')
                ->where('user_id', $fresh->id)->lockForUpdate()->first();
            if ($epochState === null || (bool) $epochState->revocation_pending) {
                return null;
            }
            $epochAtIssue = (int) $epochState->epoch;
        }

        // 256 bits of randomness, base64url encoding, no embedded identifiers.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $issued = CarbonImmutable::now('UTC');
        $grantFields = [
            'user_id' => $fresh->id,
            'token_hash' => hash('sha256', $token),
            'session_version_at_issue' => (int) $fresh->session_version,
            'issued_at' => $issued,
            'expires_at' => $issued->addDays(self::DAYS),
            'revoked_at' => null,
            'created_at' => $issued,
            'updated_at' => $issued,
        ];
        if ($epochAtIssue !== null) {
            $grantFields['security_epoch_at_issue'] = $epochAtIssue;
        }
        DB::table('kfos_remembered_devices')->insert($grantFields);
        return $token;
    }

    /**
     * Read-only validation: MUST NOT refresh issued_at or expires_at.
     * A fifth failed attempt does not revoke an already-valid credential.
     */
    public function validate(string $token): ?User
    {
        // Reject empty/malformed credentials without querying by token text.
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            return null;
        }

        $grant = DB::table('kfos_remembered_devices')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();


if (
    $grant === null ||
    CarbonImmutable::parse($grant->expires_at, 'UTC')
        ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
) {
    return null;
}

$user = User::query()->find($grant->user_id);

        if ($user === null || ! $user->is_active || $user->is_development_only ||
            $user->employee_id === null ||
            (int) $user->session_version !== (int) $grant->session_version_at_issue
            || ! $this->employmentEligible($user)) {
            return null;
        }

        if (config('kfos_step8.epoch_admission_enabled', false)) {
            // Old/unstamped or pending grants fail closed; never renew TTL.
            $issuedEpoch = $grant->security_epoch_at_issue ?? null;
            if ($issuedEpoch === null) {
                return null;
            }
            $epochState = DB::table('kfos_account_security_epochs')
                ->where('user_id', $user->id)->first(['epoch', 'revocation_pending']);
            if ($epochState === null || (bool) $epochState->revocation_pending
                || (int) $issuedEpoch !== (int) $epochState->epoch) {
                return null;
            }
        }
        return $user;
    }

    /**
     * The original epoch of a still-valid remembered credential. Never
     * stamp a current/new epoch onto a token issued under an older epoch.
     */
    public function boundEpochForValidToken(string $token): ?int
    {
        if (! config('kfos_step8.epoch_admission_enabled', false)
            || $this->validate($token) === null) {
            return null;
        }
        $issued = DB::table('kfos_remembered_devices')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->value('security_epoch_at_issue');
        return $issued === null ? null : (int) $issued;
    }

    /** Remove only the initiating device's remembered credential. */
    public function revokeOne(string $token): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            return;
        }
        DB::table('kfos_remembered_devices')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /** For Logout From All Devices, deactivation, reset and ID correction. */
    public function revokeAllForUser(int $userId): int
    {
        return DB::table('kfos_remembered_devices')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
