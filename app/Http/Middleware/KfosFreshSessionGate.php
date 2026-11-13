<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 6: additional original-account / Employee Master eligibility gate.
 * This is NOT the approved durable HR revocation epoch/barrier implementation.
 * Mounted only in existing opt-in LOCAL stage routes by the Step 6 runner.
 */
final class KfosFreshSessionGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            // Query the ORIGINAL relationship, never a user-entered employee name/ID.
            // A connection/read failure propagates as an error (never a pass).
            $fresh = DB::table('users as u')
                ->join('employees as e', 'e.id', '=', 'u.employee_id')
                ->where('u.id', $user->getKey())
                ->first(['u.id', 'u.employee_id', 'u.is_active',
                    'u.is_development_only', 'u.session_version',
                    'e.is_active as employment_is_active']);

            $allowed = $fresh !== null
                && (bool) $fresh->is_active
                && ! (bool) $fresh->is_development_only
                && $request->session()->has('kfos_session_version')
                && (int) $request->session()->get('kfos_session_version')
                    === (int) $fresh->session_version;

            if ($allowed && ! (bool) $fresh->employment_is_active) {
                // CDR-0646 genuine UNIVERSAL admin employment-inactive exception.
                // This does NOT grant permissions or bypass Account Active status.
                $allowed = DB::table('kfos_system_administrators as a')
                    ->where('a.user_id', $fresh->id)
                    ->whereExists(static function ($q): void {
                        $q->selectRaw('1')->from('kfos_roles as r')
                            ->where('r.code', 'system_administrator')
                            ->where('r.kind', 'universal')
                            ->where('r.is_active', true);
                    })->exists();
            }
        } catch (\Illuminate\Database\QueryException $error) {
            // Do not transform an unavailable authoritative source into authorization.
            return response()->json(['message' => 'Security verification unavailable.'], 503);
        }

        if (! $allowed) {
            // Revokes this request's session; global original-HR-event revocation
            // and durable epoch/barrier are still separate outstanding work.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return response()->json(['message' => 'Account access unavailable.'], 403);
        }
        return $next($request);
    }
}
