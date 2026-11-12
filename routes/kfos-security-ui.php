<?php

use App\Http\Controllers\Security\KfosUserManagementUiPreviewController;
use Illuminate\Support\Facades\Route;

/* Included ONLY inside Step 5AH's local/debug/opt-in guarded route group. */
Route::middleware(['web', 'auth:web', 'kfos.account'])
    ->get('kfos/security/ui/users', [KfosUserManagementUiPreviewController::class, 'index'])
    ->name('kfos.security.users.ui.index');

Route::middleware(['web', 'auth:web', 'kfos.account'])
    ->get('kfos/security/ui/users/create', [KfosUserManagementUiPreviewController::class, 'create'])
    ->name('kfos.security.users.ui.create');
