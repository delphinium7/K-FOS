<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\KfosCredentialSessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Production-policy integration adapter. No live routes are registered for this
 * controller until roles, recovery and session-persistence reviews are complete.
 */
final class KfosEmployeeCredentialController extends Controller
{
    public function store(Request $request, KfosCredentialSessionIssuer $issuer): JsonResponse
    {
        $input = $request->validate([
            'employee_id' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $issued = $issuer->issue($request, $input['employee_id'], $input['password']);

        if ($issued === null) {
            return response()->json([
                'message' => 'Invalid Employee ID or password.',
            ], 422);
        }

        // This adapter never opts into Remember me. Session issuance and
        // success audit have already passed the authoritative final checks.
        return response()->json([
            'authenticated' => true,
            'user_id' => $issued['user_id'],
        ]);
    }
}
