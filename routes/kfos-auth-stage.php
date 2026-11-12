<?php

use App\Http\Controllers\Auth\KfosRememberBrowserController;
use App\Http\Middleware\EnsureKfosAuthStageLocal;
use App\Http\Middleware\KfosRestoreRememberedSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * LOCAL integration endpoints only. Included by routes/web.php while enabled.
 * Do not expose administrator restoration: the production role registry is pending.
 * web middleware is already supplied by Laravel's web.php route group.
 */
Route::prefix('kfos-auth-stage')
    ->name('kfos.auth.stage.')
    ->middleware([EnsureKfosAuthStageLocal::class])
    ->group(function (): void {
        Route::post('/login', [KfosRememberBrowserController::class, 'login'])
            ->name('login');

        Route::middleware([
            KfosRestoreRememberedSession::class,
            'auth:web',
            'kfos.account',
        ])->group(function (): void {
            Route::get('/session', function () {
                return response()->json([
                    'authenticated' => true,
                    'user_id' => Auth::guard('web')->id(),
                ]);
            })->name('session');

            Route::post('/logout', [KfosRememberBrowserController::class, 'logout'])
                ->name('logout');
            Route::post('/logout-all', [KfosRememberBrowserController::class, 'logoutAll'])
                ->name('logout.all');
        });

        // Step 5S: strictly local-only UI integration preview.
        Route::get('/ui/login', function () {
            if (\Illuminate\Support\Facades\Auth::guard('web')->check()) {
                return redirect()->route('kfos.auth.stage.ui.home');
            }
            return view('auth.kfos-stage-login');
        })->name('ui.login');

        Route::middleware([
            KfosRestoreRememberedSession::class,
            'auth:web',
            'kfos.account',
        ])->get('/ui/home', function () {
            return view('auth.kfos-stage-home');
        })->name('ui.home');
    });
