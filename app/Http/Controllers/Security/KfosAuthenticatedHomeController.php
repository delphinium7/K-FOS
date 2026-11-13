<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\KfosAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/** Local-only post-login navigation; never an authorization bypass. */
final class KfosAuthenticatedHomeController extends Controller
{
    public function show(KfosAuthorization $authorization): View
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor !== null, 401);

        // Every navigation decision uses the same authoritative role registry
        // as the protected HTTP controllers, never the legacy dev-admin flag.
        $isAdministrator = $authorization->isSystemAdministrator($actor);
        $canManageUsers = $isAdministrator
            || $authorization->allows($actor, 'security.accounts.status');
        $canManageRoles = $isAdministrator;
        if (! $canManageRoles) {
            foreach (['security.roles.create', 'security.roles.edit_permissions', 'security.roles.assign'] as $right) {
                if ($authorization->allows($actor, $right)) {
                    $canManageRoles = true;
                    break;
                }
            }
        }

        // The stage login and optional User Management route mounts have
        // INDEPENDENT flags. Never render a named link when routes are absent.
        return view('auth.kfos-stage-home', [
            'canManageUsers' => $canManageUsers && Route::has('kfos.security.users.ui.index'),
            'canManageRoles' => $canManageRoles && Route::has('kfos.security.roles.ui.index'),
            'canAddUser' => $isAdministrator && Route::has('kfos.security.users.ui.create'),
        ]);
    }
}
