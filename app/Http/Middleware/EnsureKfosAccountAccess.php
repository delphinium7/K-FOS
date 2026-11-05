<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureKfosAccountAccess
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = Auth::guard('web')->user();

        if (! $user) {
            abort(401, 'Authentication required.');
        }

        // Inactive users must not retain access.
        if (! $user->is_active) {
            return $this->reject($request);
        }

        // Development identities must never work
        // outside the explicitly permitted local setup.
        if ($user->is_development_only) {
            $allowed = app()->environment('local')
                && config('app.debug') === true
                && config('kfos.allow_shared_dev_admin') === true
                && in_array(
                    config('app.url'),
                    [
                        'http://localhost:8080',
                        'http://127.0.0.1:8080',
                    ],
                    true
                )
                && in_array(
                    $request->getHost(),
                    ['localhost', '127.0.0.1'],
                    true
                );

            if (! $allowed) {
                return $this->reject($request);
            }
        }

        // A fresh credential login must record
        // the current version in the session.
        $sessionVersion = $request->session()
            ->get('kfos_session_version');

        if (
            $sessionVersion === null ||
            (int) $sessionVersion !== (int) $user->session_version
        ) {
            return $this->reject($request);
        }

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(403, 'Account access is unavailable.');
    }
}
