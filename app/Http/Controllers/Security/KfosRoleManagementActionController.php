<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\KfosAuthorization;
use App\Services\Security\KfosRoleChangeService;
use App\Services\Security\KfosRoleLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Thin local-development HTTP adapters. Transactional authorization remains in the services. */
final class KfosRoleManagementActionController extends Controller
{
    private function actor(): \App\Models\User
    {
        $actor = Auth::guard('web')->user();
        if ($actor === null) {
            throw new AuthorizationException('Authentication required.');
        }
        return $actor;
    }

    public function create(Request $request, KfosRoleLifecycleService $roles): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        // Begin with zero permissions; protected checkboxes are set separately by System Admin.
        $roleId = $roles->createOperationalRole($this->actor(), $data['name'], [], $data['reason'] ?? null);
        return response()->json(['role_id' => $roleId, 'created' => true], 201);
    }

    public function rename(Request $request, int $role, KfosRoleLifecycleService $roles): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['changed' => $roles->renameOperationalRole($this->actor(), $role, $data['name'], $data['reason'] ?? null)]);
    }

    public function setActive(Request $request, int $role, KfosRoleLifecycleService $roles): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['changed' => $roles->setOperationalRoleActive(
            $this->actor(), $role, (bool) $data['is_active'], $data['reason'] ?? null
        )]);
    }

    public function permissions(Request $request, int $role, KfosAuthorization $authorization, KfosRoleChangeService $changes): JsonResponse
    {
        $data = $request->validate([
            'permission_codes' => ['sometimes', 'array', 'max:200'],
            'permission_codes.*' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $actor = $this->actor();
        $codes = $data['permission_codes'] ?? [];
        if (! $authorization->isSystemAdministrator($actor)) {
            // A delegated editor may submit BUSINESS codes only. Existing protected
            // settings are copied into the request but still revalidated by the service
            // after acquiring the common transactional mutation lock.
            if (DB::table('kfos_permissions')->whereIn('code', $codes)
                ->where('is_protected', true)->exists()) {
                throw ValidationException::withMessages(['permission_codes' => 'Protected permissions are System Administrator-only.']);
            }
            $protected = DB::table('kfos_role_permissions as rp')
                ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role)->where('p.is_protected', true)
                ->pluck('p.code')->all();
            $codes = array_merge($codes, $protected);
        }
        $changed = $changes->replacePermissions($actor, $role, $codes, $data['reason'] ?? null);
        return response()->json(['changed' => $changed]);
    }

    public function assign(Request $request, int $user, KfosRoleChangeService $changes): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['changed' => $changes->assignOperationalRole(
            $this->actor(), $user, $data['role_id'] ?? null, $data['reason'] ?? null
        )]);
    }
}
