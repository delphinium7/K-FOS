<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KfosDevAccountStatusController extends Controller
{
    private function authorizeDevAdmin(Request $request): void
    {
        $actor = Auth::guard('web')->user();

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
            && $actor
            && $actor->login_id === 'KFOS_ADMIN'
            && $actor->is_active
            && $actor->is_system_admin
            && $actor->is_development_only,
            403
        );
    }

    public function index(Request $request)
    {
        $this->authorizeDevAdmin($request);

        $users = DB::table('users')
            ->where('is_development_only', true)
            ->orderBy('login_id')
            ->get([
                'id',
                'login_id',
                'name',
                'is_active',
                'is_system_admin',
                'is_development_only',
            ]);

        return view('auth.kfos-dev-users', [
            'users' => $users,
        ]);
    }

    public function update(Request $request, int $user)
    {
        $this->authorizeDevAdmin($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['active', 'inactive']),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $actorId = Auth::id();
        $newStatus = $data['status'] === 'active';

        DB::transaction(function () use (
            $user,
            $actorId,
            $newStatus,
            $data
        ) {
            $target = DB::table('users')
                ->where('id', $user)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $target && $target->is_development_only,
                404
            );

            // Shared development administrator cannot
            // deactivate or reactivate its own account here.
            abort_if(
                (int) $target->id === (int) $actorId,
                403,
                'Self-status changes are prohibited.'
            );

            // This development-only function handles ordinary
            // synthetic accounts, not administrator accounts.
            abort_if(
                (bool) $target->is_system_admin,
                403,
                'Administrator status changes are unavailable.'
            );

            $oldStatus = (bool) $target->is_active;

            if ($oldStatus === $newStatus) {
                return;
            }

            DB::table('users')
                ->where('id', $target->id)
                ->update([
                    'is_active' => $newStatus,
                    'session_version' =>
                        (int) $target->session_version + 1,
                    'remember_token' => Str::random(60),
                    'updated_at' => now(),
                ]);

            DB::table('security_audit_logs')->insert([
                'actor_user_id' => $actorId,
                'target_user_id' => $target->id,
                'event_type' => $newStatus
                    ? 'dev_user_activated'
                    : 'dev_user_deactivated',
                'result' => 'success',
                'details' => json_encode([
                    'old_account_status' => $oldStatus
                        ? 'active' : 'inactive',
                    'new_account_status' => $newStatus
                        ? 'active' : 'inactive',
                    'reason' => $data['reason'],
                ]),
                'is_shared_dev_identity' => true,
                'created_at' => now(),
            ]);
        });

        return redirect()
            ->route('kfos.auth.dev.users.index')
            ->with('success', 'Account status request processed.');
    }
}
