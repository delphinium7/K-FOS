<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Local-only guard for pre-production-policy HTTP integration exercises. */
final class EnsureKfosAuthStageLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $configuredHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (! app()->environment('local')
            || config('app.debug') !== true
            || config('kfos_auth_stage.enabled') !== true
            || ! in_array($host, ['localhost', '127.0.0.1'], true)
            || ! in_array($configuredHost, ['localhost', '127.0.0.1'], true)) {
            abort(404);
        }

        return $next($request);
    }
}
