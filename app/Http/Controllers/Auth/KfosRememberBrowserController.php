<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Step 5N integration adapter. Do not add live routes until release review.
 * Uses the already-tested production credential policy and remembered-device service.
 */
final class KfosRememberBrowserController extends Controller
{
    public const COOKIE = 'kfos_remember_device';
    private const MINUTES = KfosRememberedDeviceService::DAYS * 24 * 60;

    public function login(
        Request $request,
        KfosEmployeeLoginSecurity $security,
        KfosRememberedDeviceService $remembered
    ): JsonResponse {
        $input = $request->validate([
            'employee_id' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'remember_me' => ['sometimes', 'boolean'],
        ]);

        $user = $security->verifyCredentials($input['employee_id'], $input['password']);
        if ($user === null) {
            return response()->json(['message' => 'Invalid Employee ID or password.'], 422);
        }

        // Never use Laravel's rolling remember_token mechanism.
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $request->session()->forget('kfos_remember_restored_hash');
        $request->session()->put('kfos_session_version', (int) $user->session_version);

        // Replace/revoke a previous remembered credential for this browser on a new
        // successful password login, even when the new opt-in is unchecked.
        $previousToken = $request->cookie(self::COOKIE);
        if (is_string($previousToken)) {
            $remembered->revokeOne($previousToken);
        }
        $token = $remembered->issueAfterCredentialLogin($user, (bool) ($input['remember_me'] ?? false));
        $response = response()->json(['authenticated' => true, 'remembered' => $token !== null]);

        // A fresh password login must remove an older device cookie when opt-in is unchecked.
        if ($token === null) {
            return $response->withCookie(self::forgetCookie($request));
        }

        // EncryptCookies (the normal 'web' middleware) encrypts this before transmission.
        return $response->withCookie(self::newCookie($request, $token));
    }

    public function logout(Request $request, KfosRememberedDeviceService $remembered): JsonResponse
    {
        $token = $request->cookie(self::COOKIE);
        if (is_string($token)) {
            $remembered->revokeOne($token);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['logged_out' => true])
            ->withCookie(self::forgetCookie($request));
    }

    public function logoutAll(Request $request, KfosRememberedDeviceService $remembered): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null && $actor->is_active && ! $actor->is_development_only
            && $actor->employee_id !== null, 403);

        // Incrementing session_version revokes other currently signed-in sessions as well.
        DB::transaction(function () use ($actor, $remembered): void {
            $fresh = \App\Models\User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->is_active && ! $fresh->is_development_only && $fresh->employee_id !== null, 403);
            $fresh->session_version = (int) $fresh->session_version + 1;
            $fresh->save();
            $remembered->revokeAllForUser((int) $fresh->id);
        });

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['logged_out_all' => true])
            ->withCookie(self::forgetCookie($request));
    }

    public static function newCookie(Request $request, string $token): Cookie
    {
        return cookie(
            self::COOKIE,
            $token,
            self::MINUTES,
            '/',
            null, // Host-only: don't expose auth credentials to subdomains.
            $request->isSecure() || ! app()->environment('local'),
            true,  // HttpOnly
            false, // raw
            'lax'  // SameSite
        );
    }

    public static function forgetCookie(Request $request): Cookie
    {
        return cookie(
            self::COOKIE,
            '',
            -60,
            '/',
            null,
            $request->isSecure() || ! app()->environment('local'),
            true,
            false,
            'lax'
        );
    }
}
