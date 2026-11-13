<?php

namespace App\Http\Middleware;

use App\Services\Security\KfosOriginalAccountEpochGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 8 opt-in proof gate. No production route mounts this middleware.
 * Requires the original account epoch and session version set at fresh issue.
 * Never promotes legacy sessions or treats restored Employment Active as a
 * reason to resurrect a pre-revocation session.
 */
final class KfosEpochSessionGate
{
    public function __construct(private KfosOriginalAccountEpochGate $epochs) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Fail closed if someone mounts this gate before the approved rollout.
        if (! config('kfos_step8.epoch_admission_enabled', false)) {
            return response()->json(['message' => 'Security admission unavailable.'], 503);
        }

        $user = Auth::guard('web')->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $issuedEpoch = $request->session()->get('kfos_security_epoch');
        $issuedVersion = $request->session()->get('kfos_session_version');
        $validEpoch = (is_int($issuedEpoch) && $issuedEpoch >= 0)
            || (is_string($issuedEpoch) && ctype_digit($issuedEpoch));
        $validVersion = (is_int($issuedVersion) && $issuedVersion > 0)
            || (is_string($issuedVersion) && ctype_digit($issuedVersion)
                && (int) $issuedVersion > 0);
        if (! $validEpoch || ! $validVersion
            || ! $this->epochs->eligible($user, (int) $issuedEpoch, (int) $issuedVersion)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return response()->json(['message' => 'Account access unavailable.'], 403);
        }

        return $next($request);
    }
}
