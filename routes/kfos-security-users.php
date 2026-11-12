<?php

use App\Http\Controllers\Security\KfosEmployeeAccountCreationController;
use App\Http\Controllers\Security\KfosEmployeeAccountStatusController;
use Illuminate\Support\Facades\Route;

/*
 * PENDING ROUTE CONTRACT. Deliberately NOT required from routes/web.php.
 * For now, only isolated integration tests include this file.
 * Do not mount until the genuine employee login and approved Security UI
 * are reconciled with current CDR authorization and CSRF requirements.
 */
Route::middleware(['web', 'auth:web', 'kfos.account'])
    ->prefix('kfos/security/users')
    ->name('kfos.security.users.')
    ->group(function (): void {
        Route::post('/', [KfosEmployeeAccountCreationController::class, 'store'])
            ->name('store');
        Route::post('/{user}/status', [KfosEmployeeAccountStatusController::class, 'update'])
            ->whereNumber('user')->name('status.update');
    });
