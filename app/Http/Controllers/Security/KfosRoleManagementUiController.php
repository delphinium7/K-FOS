<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\KfosAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Local UI preview only. All writes go to transactional, audited role services. */
final class KfosRoleManagementUiController extends Controller
{
    public function index(Request $request, KfosAuthorization $authorization): View
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null, 401);
        $isAdministrator = $authorization->isSystemAdministrator($actor);
        $canCreate = $isAdministrator || $authorization->allows($actor, 'security.roles.create');
        $canEdit = $isAdministrator || $authorization->allows($actor, 'security.roles.edit_permissions');
        $canAssign = $isAdministrator || $authorization->allows($actor, 'security.roles.assign');
        abort_unless($canCreate || $canEdit || $canAssign, 403);

        $roles = DB::table('kfos_roles')->where('kind', 'operational')
            ->orderBy('name')->limit(100)->get(['id', 'name', 'is_active']);
        $permissions = DB::table('kfos_permissions')->orderBy('category')->orderBy('name')
            ->get(['id', 'code', 'name', 'category', 'is_protected']);
        $selected = DB::table('kfos_role_permissions as rp')
            ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $roles->pluck('id')->all())
            ->get(['rp.role_id', 'p.code'])->groupBy('role_id')
            ->map(fn ($items) => $items->pluck('code')->all());
        $protectedRoles = DB::table('kfos_role_permissions as rp')
            ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.is_protected', true)->distinct()->pluck('rp.role_id')->all();
        $destinations = $isAdministrator ? $roles->where('is_active', true)
            : $roles->where('is_active', true)->whereNotIn('id', $protectedRoles);
        // Delegates can only assign ordinary employees; universal administrators
        // are never targetable through this operational-role interface.
        $users = $canAssign ? DB::table('users as u')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->leftJoin('kfos_operational_role_assignments as a', 'a.user_id', '=', 'u.id')
            ->where('u.is_development_only', false)->where('u.is_active', true)
            ->where('e.is_active', true)
            ->whereNotIn('u.id', DB::table('kfos_system_administrators')->select('user_id'))
            ->orderBy('u.login_id')->limit(50)->get(['u.id', 'u.login_id', 'u.name', 'a.role_id'])
            : collect();

        return view('auth.kfos-stage-roles', compact(
            'isAdministrator', 'canCreate', 'canEdit', 'canAssign',
            'roles', 'permissions', 'selected', 'destinations', 'users'
        ));
    }
}
