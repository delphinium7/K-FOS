<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\KfosEmployeeLoginSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Integration adapter only. The domain service uses the interim admin flag;
 * do not publish an HTTP route until the authoritative role registry is wired.
 */
final class KfosLoginRestorationController extends Controller
{
    public function store(Request $request, int $targetUserId, KfosEmployeeLoginSecurity $security): JsonResponse
    {
        $input = $request->validate([
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $restored = $security->restore(
            $request->user('web'),
            $targetUserId,
            $input['remark'] ?? null
        );

        return response()->json(['restored' => $restored]);
    }
}
