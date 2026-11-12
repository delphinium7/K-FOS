<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
{
    if ($request->expectsJson()) {
        return null;
    }

    if (
        $request->is('kfos-auth-stage/*')
        && app()->environment('local')
        && config('kfos_auth_stage.enabled', false)
    ) {
        return route('kfos.auth.stage.ui.login');
    }

    return route('login');
}
}
