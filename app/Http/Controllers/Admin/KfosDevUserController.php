<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class KfosDevUserController extends Controller
{
    private function authorizeDevAdmin(Request $request): void
    {
        $user = Auth::guard('web')->user();

        abort_unless(
            app()->environment('local')
            && config('app.debug') === true
            && config('kfos.allow_shared_dev_admin') === true
            && in_array(
                config('app.url'),
                ['http://localhost:8080', 'http://127.0.0.1:8080'],
                true
            )
            && in_array(
                $request->getHost(),
                ['localhost', '127.0.0.1'],
                true
            )
            && $user
            && $user->login_id === 'KFOS_ADMIN'
            && $user->is_active
            && $user->is_system_admin
            && $user->is_development_only,
            403
        );
    }

    public function create(Request $request)
    {
        $this->authorizeDevAdmin($request);

        return view('auth.kfos-dev-add-user');
    }

    public function store(Request $request)
    {
        $this->authorizeDevAdmin($request);

        $data = Validator::make($request->all(), [
            'employee_id' => [
                'required', 'string', 'max:100',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:16', 'confirmed'],
        ])->validate();

        $loginId = $data['employee_id'];

        if ($loginId === 'KFOS_ADMIN') {
            throw ValidationException::withMessages([
                'employee_id' => 'This login ID is reserved.',
            ]);
        }

        // Hash before opening the transaction.
        $passwordHash = Hash::make($data['password']);

        try {
            DB::transaction(function () use (
                $data,
                $loginId,
                $passwordHash
            ) {
                if (
                    DB::table('employee_id_reservations')
                        ->where('reserved_employee_id', $loginId)
                        ->exists()
                    || DB::table('employees')
                        ->where('employee_id', $loginId)
                        ->exists()
                    || DB::table('users')
                        ->where('login_id', $loginId)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Employee ID is unavailable.',
                    ]);
                }

                $employeeId = DB::table('employees')
                    ->insertGetId([
                        'employee_id' => $loginId,
                        'name' => $data['name'],
                        'position' => $data['position'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('employee_id_reservations')->insert([
                    'employee_id' => $employeeId,
                    'reserved_employee_id' => $loginId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $userId = DB::table('users')->insertGetId([
                    'employee_id' => $employeeId,
                    'login_id' => $loginId,
                    'name' => $data['name'],
                    'email' => null,
                    'password' => $passwordHash,
                    'is_active' => true,
                    'is_system_admin' => false,
                    'is_development_only' => true,
                    'session_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('security_audit_logs')->insert([
                    'actor_user_id' => Auth::id(),
                    'target_user_id' => $userId,
                    'event_type' => 'dev_user_created',
                    'result' => 'success',
                    'details' => json_encode([
                        'employee_id' => $loginId,
                        'initial_account_status' => 'active',
                    ]),
                    'is_shared_dev_identity' => true,
                    'created_at' => now(),
                ]);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Illuminate\Database\QueryException $exception) {
            // Includes uniqueness conflicts caused by concurrent requests.
            throw ValidationException::withMessages([
                'employee_id' =>
                    'Unable to create account. Check Employee ID and try again.',
            ]);
        }

        return redirect()
            ->route('kfos.auth.dev.users.create')
            ->with('success', 'Development test user created successfully.');
    }
}
