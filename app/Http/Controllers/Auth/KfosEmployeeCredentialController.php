<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Production-policy integration adapter. No live routes are registered for this
 * controller until roles, recovery and session-persistence reviews are complete.
 */
final class KfosEmployeeCredentialController extends Controller
{
    public function store(Request $request, KfosEmployeeLoginSecurity $security): JsonResponse
    {
        $input = $request->validate([
            'employee_id' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $user = $security->verifyCredentials($input['employee_id'], $input['password']);

        if ($user === null) {
            return response()->json([
                'message' => 'Invalid Employee ID or password.',
            ], 422);
        }

        // Intentionally no Remember me yet. AUTH-047/048 requires a fixed,
        // non-rolling 90-day limit, not Laravel's default rotating token alone.
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $request->session()->put('kfos_session_version', (int) $user->session_version);

        return response()->json([
            'authenticated' => true,
            'user_id' => $user->id,
        ]);
    }
}
