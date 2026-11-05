<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\KfosRememberBrowserController;
use App\Services\Auth\KfosRememberedDeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mount after 'web' (session, decryption) but BEFORE 'auth:web' and 'kfos.account'.
 * Revalidates every session restored through a remembered cookie, without renewal.
 */
final class KfosRestoreRememberedSession
{
    public function __construct(private KfosRememberedDeviceService $remembered)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(KfosRememberBrowserController::COOKIE);
        $token = is_string($token) ? $token : '';
        $marker = $request->session()->get('kfos_remember_restored_hash');
        $user = Auth::guard('web')->user();

        if (is_string($marker) && $marker !== '') {
            // A session re-established via Remember me is bounded by the ORIGINAL grant.
            $valid = $token !== '' && hash_equals($marker, hash('sha256', $token))
                ? $this->remembered->validate($token)
                : null;

            if ($user === null || $valid === null || (int) $user->id !== (int) $valid->id) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(['message' => 'Unauthenticated.'], 401)
                    ->withCookie(KfosRememberBrowserController::forgetCookie($request));
            }
        } elseif ($user === null && $token !== '') {
            // No authenticated session: attempt a single cookie-based restoration.
            $valid = $this->remembered->validate($token);
            if ($valid !== null) {
                Auth::guard('web')->login($valid, false);
                $request->session()->regenerate();
                $request->session()->put('kfos_session_version', (int) $valid->session_version);
                $request->session()->put('kfos_remember_restored_hash', hash('sha256', $token));
            }
        }

        // Deliberately never re-issue the token or refresh cookie expiration.
        return $next($request);
    }
}
