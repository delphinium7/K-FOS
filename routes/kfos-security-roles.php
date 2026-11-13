<?php

use App\Http\Controllers\Security\KfosRoleManagementActionController;
use App\Http\Controllers\Security\KfosRoleManagementUiController;
use Illuminate\Support\Facades\Route;

// Included ONLY by the preexisting local+debug+opt-in staging guard in routes/web.php.
// Uses normal Laravel web CSRF/session and current authenticated-account middleware.
Route::middleware(['web', 'auth:web', 'kfos.account', \App\Http\Middleware\KfosFreshSessionGate::class])->group(function (): void {
    Route::get('kfos/security/ui/roles', [KfosRoleManagementUiController::class, 'index'])
        ->name('kfos.security.roles.ui.index');
    Route::prefix('kfos/security/roles')->name('kfos.security.roles.')
        ->group(function (): void {
            Route::post('/', [KfosRoleManagementActionController::class, 'create'])->name('store');
            Route::post('/{role}/rename', [KfosRoleManagementActionController::class, 'rename'])
                ->whereNumber('role')->name('rename');
            Route::post('/{role}/status', [KfosRoleManagementActionController::class, 'setActive'])
                ->whereNumber('role')->name('status');
            Route::post('/{role}/permissions', [KfosRoleManagementActionController::class, 'permissions'])
                ->whereNumber('role')->name('permissions');
            Route::post('/employees/{user}/role', [KfosRoleManagementActionController::class, 'assign'])
                ->whereNumber('user')->name('employees.assign');
        });
});
