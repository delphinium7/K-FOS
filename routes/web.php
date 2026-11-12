<?php

use App\Livewire\DevCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| K-FOS Web Routes
|--------------------------------------------------------------------------
|
| Existing development previews and database-backed
| authentication routes are kept separate.
|
| The development authentication system is not
| the final production implementation.
|
*/

/*
|--------------------------------------------------------------------------
| Default Laravel Homepage
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| K-FOS Local Development Preview Routes
|--------------------------------------------------------------------------
*/

if (App::environment('local')) {

    /*
    |--------------------------------------------------------------------------
    | Livewire Development Test
    |--------------------------------------------------------------------------
    */

    Route::get('/kfos-dev-test', DevCounter::class);

    /*
    |--------------------------------------------------------------------------
    | Existing K-FOS Login Preview
    |--------------------------------------------------------------------------
    */

    Route::get('/kfos-login-preview', function (Request $request) {

        if ($request->session()->has('kfos_demo_employee_id')) {
            return redirect()->route('kfos.demo.home');
        }

        return view('kfos-login-preview');

    })->name('kfos.demo.login');

    /*
    |--------------------------------------------------------------------------
    | Existing Demo Login Submission
    |--------------------------------------------------------------------------
    */

    Route::post('/kfos-login-preview', function (Request $request) {

        $credentials = $request->validate([
            'employee_id' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        /*
         * Temporary demonstration rate limiter.
         * Not the approved production AUTH restriction.
         */

        $key = 'kfos-demo-login:' . sha1(
            $request->ip() ?? 'local'
        );

        if (RateLimiter::tooManyAttempts($key, 10)) {

            return back()
                ->withErrors([
                    'login' => 'Too many test attempts. Please try again later.',
                ])
                ->onlyInput('employee_id');
        }

        /*
         * Demonstration credentials from local configuration.
         */

        $expectedId = config('kfos_demo.employee_id');
        $expectedHash = config('kfos_demo.password_hash');

        $valid = is_string($expectedId)
            && $expectedId !== ''
            && is_string($expectedHash)
            && $expectedHash !== ''
            && hash_equals(
                $expectedId,
                $credentials['employee_id']
            )
            && Hash::check(
                $credentials['password'],
                $expectedHash
            );

        if (! $valid) {

            RateLimiter::hit($key, 60);

            return back()
                ->withErrors([
                    'login' => 'Invalid Employee ID or password.',
                ])
                ->onlyInput('employee_id');
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();

        $request->session()->put(
            'kfos_demo_employee_id',
            $expectedId
        );

        return redirect()->route('kfos.demo.home');

    })->name('kfos.demo.login.submit');

    /*
    |--------------------------------------------------------------------------
    | Existing Demo Homepage
    |--------------------------------------------------------------------------
    */

    Route::get('/kfos-demo-home', function (Request $request) {

        if (! $request->session()->has('kfos_demo_employee_id')) {
            return redirect()->route('kfos.demo.login');
        }

        return view('kfos-demo-home', [
            'employeeId' => $request->session()->get(
                'kfos_demo_employee_id'
            ),
        ]);

    })->name('kfos.demo.home');

    /*
    |--------------------------------------------------------------------------
    | Existing K-FOS Dashboard / Shell Preview
    |--------------------------------------------------------------------------
    */

    Route::get('/kfos-shell-preview', function (Request $request) {

        if (! $request->session()->has('kfos_demo_employee_id')) {
            return redirect()->route('kfos.demo.login');
        }

        return view('kfos-shell-preview');

    })->name('kfos.demo.dashboard');

    /*
    |--------------------------------------------------------------------------
    | Existing SO-01 Sales Order Frontend Preview
    |--------------------------------------------------------------------------
    |
    | Local demonstration only.
    | No actual Sales Order database write.
    |
    */

    Route::get('/kfos-so-preview', function (Request $request) {

        if (! $request->session()->has('kfos_demo_employee_id')) {
            return redirect()->route('kfos.demo.login');
        }

        return view('kfos-so-preview');

    })->name('kfos.demo.so.preview');

    /*
    |--------------------------------------------------------------------------
    | Existing Demo Logout
    |--------------------------------------------------------------------------
    */

    Route::post('/kfos-demo-logout', function (Request $request) {

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('kfos.demo.login');

    })->name('kfos.demo.logout');
}

/*
|--------------------------------------------------------------------------
| K-FOS Database-backed Authentication — Local Development
|--------------------------------------------------------------------------
|
| These routes require the explicitly enabled local
| development configuration.
|
| They remain separate from the existing UI/UX previews.
|
*/

if (
    App::environment('local') &&
    config('kfos.allow_shared_dev_admin') === true
) {

    /*
    |--------------------------------------------------------------------------
    | Development Login Page
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/kfos-auth-dev/login',
        [
            \App\Http\Controllers\Auth\KfosDevLoginController::class,
            'show'
        ]
    )->name('kfos.auth.dev.login');

    /*
    |--------------------------------------------------------------------------
    | Development Login Submission
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/kfos-auth-dev/login',
        [
            \App\Http\Controllers\Auth\KfosDevLoginController::class,
            'login'
        ]
    )->name('kfos.auth.dev.submit');

    /*
    |--------------------------------------------------------------------------
    | Protected Development Routes
    |--------------------------------------------------------------------------
    |
    | auth:
    |   Requires a Laravel authenticated web session.
    |
    | kfos.account:
    |   Verifies account Active status, environment
    |   restrictions and session version.
    |
    */

    Route::middleware([
        'auth',
        'kfos.account',
    ])->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Authenticated Development Homepage
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/kfos-auth-dev/home',
            [
                \App\Http\Controllers\Auth\KfosDevLoginController::class,
                'home'
            ]
        )->name('kfos.auth.dev.home');

        /*
        |--------------------------------------------------------------------------
        | Authenticated Development Logout
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/kfos-auth-dev/logout',
            [
                \App\Http\Controllers\Auth\KfosDevLoginController::class,
                'logout'
            ]
        )->name('kfos.auth.dev.logout');

        /*
        |--------------------------------------------------------------------------
        | Development User Management — Add User
        |--------------------------------------------------------------------------
        |
        | Additional authorization is performed inside
        | KfosDevUserController.
        |
        */

        Route::get(
            '/kfos-auth-dev/users/create',
            [
                \App\Http\Controllers\Admin\KfosDevUserController::class,
                'create'
            ]
        )->name('kfos.auth.dev.users.create');

        Route::post(
            '/kfos-auth-dev/users',
            [
                \App\Http\Controllers\Admin\KfosDevUserController::class,
                'store'
            ]
        )->name('kfos.auth.dev.users.store');

        /*
        |--------------------------------------------------------------------------
        | Development User Management — Account Status
        |--------------------------------------------------------------------------
        |
        | List local test users.
        | Activate or deactivate ordinary test accounts.
        |
        | Administrator accounts are protected separately
        | by KfosDevAccountStatusController.
        |
        */

        Route::get(
            '/kfos-auth-dev/users',
            [
                \App\Http\Controllers\Admin\KfosDevAccountStatusController::class,
                'index'
            ]
        )->name('kfos.auth.dev.users.index');

        Route::post(
            '/kfos-auth-dev/users/{user}/status',
            [
                \App\Http\Controllers\Admin\KfosDevAccountStatusController::class,
                'update'
            ]
        )
            ->whereNumber('user')
            ->name('kfos.auth.dev.users.status');

    });
}

/*
|--------------------------------------------------------------------------
| K-FOS Step 5Q — Local Authentication Integration
|--------------------------------------------------------------------------
| Disabled by default; not for production deployment.
*/

if (
    \Illuminate\Support\Facades\App::environment('local')
    && config('kfos_auth_stage.enabled', false) === true
) {
    require __DIR__.'/kfos-auth-stage.php';
}

/*
 * K-FOS STEP 5AH — LOCAL-ONLY USER MANAGEMENT ROUTE MOUNT
 * OFF by default. This must NOT be used as production route activation.
 * Requires an explicit local-only flag, app debug, existing stage guard,
 * normal web CSRF/session middleware, web authentication and kfos.account.
 * Real production activation remains subject to separate security review.
 */
if (app()->environment('local')
    && config('app.debug')
    && filter_var(env('KFOS_USER_MANAGEMENT_LOCAL_STAGE', false), FILTER_VALIDATE_BOOLEAN)) {
    \Illuminate\Support\Facades\Route::middleware([
        \App\Http\Middleware\EnsureKfosAuthStageLocal::class,
        \App\Http\Middleware\KfosRestoreRememberedSession::class,
    ])->group(static function (): void {
        require __DIR__.'/kfos-security-users.php';
        require __DIR__.'/kfos-security-ui.php';
        require __DIR__.'/kfos-security-roles.php';
    });
}
