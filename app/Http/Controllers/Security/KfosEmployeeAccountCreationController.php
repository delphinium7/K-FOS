<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\KfosEmployeeAccountCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The route file is intentionally not mounted in routes/web.php yet.
 * The service performs transaction-time genuine administrator checks.
 * AUTH-001/006/007/009/015/017/025.
 */
final class KfosEmployeeAccountCreationController extends Controller
{
    public function store(Request $request, KfosEmployeeAccountCreationService $accounts): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null, 401);

        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'password' => [
                'required', 'string', 'confirmed', 'min:8',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
                'regex:/[^\\pL\\pN\\s]/u',
            ],
            'role_id' => ['required', 'integer'],
        ]);

        $newUser = $accounts->create(
            $actor,
            $data['employee_id'],
            $data['name'],
            $data['position'],
            $data['password'],
            (int) $data['role_id']
        );

        // Never return the initial password or stored hash in an HTTP response.
        return response()->json([
            'user_id' => (int) $newUser->id,
            'employee_id' => $newUser->login_id,
            'status' => 'active',
        ], 201);
    }
}
