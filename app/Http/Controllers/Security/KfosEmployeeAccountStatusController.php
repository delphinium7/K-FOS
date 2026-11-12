<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\KfosEmployeeAccountStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Only test routes mount this controller until the production Security UI is reviewed. */
final class KfosEmployeeAccountStatusController extends Controller
{
    public function update(Request $request, int $user, KfosEmployeeAccountStatusService $status): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null, 401);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $changed = $status->setActive(
            $actor, $user, $data['status'] === 'active', $data['reason'] ?? null
        );
        return response()->json(['changed' => $changed, 'status' => $data['status']]);
    }
}
