<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\KfosAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Local-only UI preview. Genuine user eligibility is checked through the central registry. */
final class KfosUserManagementUiPreviewController extends Controller
{
    /** Separate SEC-UI-02 preview: only a genuine current universal administrator. */
    public function create(Request $request, KfosAuthorization $authorization): View
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null, 401);
        abort_unless($authorization->isSystemAdministrator($actor), 403);

        $roles = DB::table('kfos_roles')->where('kind', 'operational')
            ->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('auth.kfos-stage-user-create', [
            'isAdministrator' => true,
            'roles' => $roles,
        ]);
    }

    public function index(Request $request, KfosAuthorization $authorization): View
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null, 401);

        // Do not grant privilege based on is_system_admin legacy/dev flag.
        $isAdministrator = $authorization->isSystemAdministrator($actor);
        $canChangeStatus = $isAdministrator
            || $authorization->allows($actor, 'security.accounts.status');
        abort_unless($canChangeStatus, 403);

        // Only a real universal administrator may see Add User inputs and role choices.
        $roles = $isAdministrator
            ? DB::table('kfos_roles')->where('kind', 'operational')
                ->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        // Search/filter are GET-only, and always run after the authorization decision.
        // A request may reduce, but never expand, a delegate's permitted target list.
        $searchValue = $request->query('q', '');
        $statusValue = $request->query('status', 'all');
        $search = is_string($searchValue) ? trim($searchValue) : '';
        $status = is_string($statusValue) ? $statusValue : 'all';
        abort_if(mb_strlen($search) > 100 || ! in_array($status, ['all', 'active', 'inactive'], true), 422);

        // Delegates never see universal administrators in the target list.
        // Read-only list remains capped; no data modification occurs on GET.
        $users = DB::table('users as u')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->leftJoin('kfos_operational_role_assignments as a', 'a.user_id', '=', 'u.id')
            ->leftJoin('kfos_roles as r', 'r.id', '=', 'a.role_id')
            ->leftJoin('kfos_system_administrators as sa', 'sa.user_id', '=', 'u.id')
            ->where('u.is_development_only', false)
            ->when(! $isAdministrator, fn ($query) => $query->whereNull('sa.user_id'))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $inner->where('u.login_id', 'ilike', $pattern)
                    ->orWhere('u.name', 'ilike', $pattern)
                    ->orWhere('e.position', 'ilike', $pattern);
            }))
            ->when($status !== 'all', fn ($query) => $query->where('u.is_active', $status === 'active'))
            ->orderBy('e.name')->orderBy('u.id')->limit(50)
            ->get([
                'u.id', 'u.login_id', 'u.name', 'u.is_active',
                'e.position', 'r.name as role_name', 'sa.user_id as universal_admin_id',
            ]);

        return view('auth.kfos-stage-users', [
            'actorId' => (int) $actor->getKey(),
            'isAdministrator' => $isAdministrator,
            'roles' => $roles,
            'users' => $users,
            'search' => $search,
            'statusFilter' => $status,
        ]);
    }
}
