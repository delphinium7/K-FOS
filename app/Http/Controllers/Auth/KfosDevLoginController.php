<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class KfosDevLoginController extends Controller
{
    private const GENERIC_ERROR =
        'Invalid Employee ID or password.';

    private function allowed(Request $request): bool
    {
        return app()->environment('local')
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
    }

    public function show(Request $request)
    {
        abort_unless($this->allowed($request), 404);

        return view('auth.kfos-dev-login');
    }

    private function audit(
        string $event,
        string $result,
        ?User $target = null
    ): void {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $result === 'success'
                ? $target?->id
                : null,
            'target_user_id' => $target?->id,
            'event_type' => $event,
            'result' => $result,
            'is_shared_dev_identity' => true,
            'created_at' => now(),
        ]);
    }

    public function login(Request $request)
    {
        abort_unless($this->allowed($request), 404);

        $data = $request->validate([
            'employee_id' => [
                'required',
                'string',
                'max:100',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        $loginId = $data['employee_id'];

        // Temporary local development rate limiter.
        // Not the final approved production policy.
        $key = 'kfos-dev-login:' . hash(
            'sha256',
            ($request->ip() ?? 'unknown') . '|' . $loginId
        );

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->audit(
                'dev_login_rate_limited',
                'failed'
            );

            return back()
                ->withErrors([
                    'login' => self::GENERIC_ERROR,
                ])
                ->onlyInput('employee_id');
        }

        $user = User::query()
            ->where('login_id', $loginId)
            ->where('is_development_only', true)
            ->first();

        $valid = $user !== null
            && $user->login_id === 'KFOS_ADMIN'
            && $user->is_active
            && $user->is_system_admin
            && Hash::check(
                $data['password'],
                $user->password
            );

        if (! $valid) {
            RateLimiter::hit($key, 60);

            $this->audit(
                $user === null
                    ? 'dev_login_unknown_id'
                    : 'dev_login_failed',
                'failed',
                $user
            );

            return back()
                ->withErrors([
                    'login' => self::GENERIC_ERROR,
                ])
                ->onlyInput('employee_id');
        }

        RateLimiter::clear($key);

        // Audit successful credential authentication.
        $this->audit(
            'dev_login_success',
            'success',
            $user
        );

        Auth::guard('web')->login($user, false);

        $request->session()->regenerate();

        $request->session()->put(
            'kfos_session_version',
            (int) $user->session_version
        );

        return redirect()
            ->route('kfos.auth.dev.home');
    }

    public function home(Request $request)
    {
        return view('auth.kfos-dev-home', [
            'user' => Auth::guard('web')->user(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('kfos.auth.dev.login');
    }
}
