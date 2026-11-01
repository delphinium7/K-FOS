
<?php

use App\Livewire\DevCounter;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application.
|
*/

Route::get('/', function () {
    return view('welcome');
});

if (App::environment('local')) {
    Route::get('/kfos-dev-test', DevCounter::class);
}
