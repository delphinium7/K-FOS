<?php

use App\Livewire\DevCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (App::environment('local')) {

    Route::get('/kfos-dev-test', DevCounter::class);

    Route::view('/kfos-shell-preview', 'kfos-shell-preview');

    Route::view('/kfos-login-preview', 'kfos-login-preview')
        ->name('kfos.demo.login');

    Route::post('/kfos-login-preview', function (Request $request) {

        $credentials = $request->validate([
            'employee_id' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $key = 'kfos-demo-login:' . sha1($request->ip() ?? 'local');

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return back()->withErrors([
                'login' => 'Too many test attempts. Please try again later.',
            ])->onlyInput('employee_id');
        }

        $expectedId = config('kfos_demo.employee_id');
        $expectedHash = config('kfos_demo.password_hash');

        $valid = is_string($expectedId)
            && $expectedId !== ''
            && is_string($expectedHash)
            && $expectedHash !== ''
            && hash_equals($expectedId, $credentials['employee_id'])
            && Hash::check($credentials['password'], $expectedHash);

        if (! $valid) {
            RateLimiter::hit($key, 60);

            return back()->withErrors([
                'login' => 'Invalid Employee ID or password.',
            ])->onlyInput('employee_id');
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();

        $request->session()->put(
            'kfos_demo_employee_id',
            $expectedId
        );

        return redirect()->route('kfos.demo.home');

    })->name('kfos.demo.login.submit');

    Route::get('/kfos-demo-home', function (Request $request) {

        if (! $request->session()->has('kfos_demo_employee_id')) {
            return redirect()->route('kfos.demo.login');
        }

        $employeeId = $request->session()->get(
            'kfos_demo_employee_id'
        );

        return view('kfos-demo-home', [
            'employeeId' => $employeeId,
        ]);

    })->name('kfos.demo.home');

    Route::post('/kfos-demo-logout', function (Request $request) {

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('kfos.demo.login');

    })->name('kfos.demo.logout');
}
