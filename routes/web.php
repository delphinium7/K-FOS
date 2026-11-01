<?php

use App\Livewire\DevCounter;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (App::environment('local')) {
    Route::get('/kfos-dev-test', DevCounter::class);

    Route::view(
        '/kfos-shell-preview',
        'kfos-shell-preview'
    );
}