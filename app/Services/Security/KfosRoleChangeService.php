<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Auth\KfosRememberedDeviceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-only transactional role changes. Not exposed by any HTTP route.
 * All future K-FOS role writers MUST share the advisory lock and audit contract.
 */
final class KfosRoleChangeService
{
    public function __construct(
        private KfosAuthorization $authorization,
        private KfosRememberedDeviceService $remembered
    ) {}

    private function mutationLock(): void
    {
        // Serializes role membership/definition writers on PostgreSQL.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
    }

    private function requirePermission(User $actor, string $permission): bool
    {
        $fresh = DB::table('users')->where('id', $actor->getKey())
            ->lockForUpdate()->first();
        $currentId = Auth::guard('web')->id();
        $sessionVersion = session()->get('kfos_session_version');
        if ($currentId === null || (int) $currentId !== (int) $actor->getKey()
            || $fresh === null || $sessionVersion === null
            || (int) $sessionVersion !== (int) $fresh->session_version
            || ! $this->authorization->allows($actor, $permission)) {
            throw new AuthorizationException('This action is not authorized.');
        }
        return $this->authorization->isSystemAdministrator($actor);
    }

    private function checkReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Reason is too long.']);
        }
        return $reason === '' ? null : $reason;
    }

    private function audit(User $actor, ?int $targetUser, string $action, array $details): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $actor->getKey(),
            'target_user_id' => $targetUser,
            'event_type' => $action,
            'result' => 'success',
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'is_shared_dev_identity' => false,
            'created_at' => now(),
        ]);
    }

    private function revokeSessions(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update([
            'session_version' => DB::raw('session_version + 1'),
            'updated_at' => now(),
        ]);
        $this->remembered->revokeAllForUser($userId);
    }

    /**
     * Replace an operational role's full effective permission set.
     * Delegates may edit only business permissions; protected entries are immutable for them.
     * Returns false for no-op, without audit or session invalidation.
     */
    public function replacePermissions(User $actor, int $roleId, array $requestedCodes, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($actor, $roleId, $requestedCodes, $reason): bool {
            $this->mutationLock();
            $admin = $this->requirePermission($actor, 'security.roles.edit_permissions');
            $role = DB::table('kfos_roles')->where('id', $roleId)->lockForUpdate()->first();
            if ($role === null || $role->kind !== 'operational' || ! $role->is_active) {
                throw new AuthorizationException('Operational role is unavailable.');
            }

            if (count($requestedCodes) !== count(array_unique($requestedCodes, SORT_REGULAR))) {
                throw ValidationException::withMessages(['permissions' => 'Duplicate permissions.']);
            }
            foreach ($requestedCodes as $code) {
                if (! is_string($code) || $code === '') {
                    throw ValidationException::withMessages(['permissions' => 'Invalid permission code.']);
                }
            }
            sort($requestedCodes, SORT_STRING);
            $all = DB::table('kfos_permissions')->whereIn('code', $requestedCodes)
                ->get(['id', 'code', 'is_protected'])->keyBy('code');
            if ($all->count() !== count($requestedCodes)) {
                throw ValidationException::withMessages(['permissions' => 'Unknown permission code.']);
            }
            $currentRows = DB::table('kfos_role_permissions as rp')
                ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $roleId)->get(['p.id', 'p.code', 'p.is_protected']);
            $before = $currentRows->pluck('code')->all();
            sort($before, SORT_STRING);
            if ($before === $requestedCodes) {
                return false;
            }
            $beforeProtected = $currentRows->where('is_protected', true)->pluck('code')->all();
            $afterProtected = [];
            foreach ($requestedCodes as $code) {
                if ((bool) $all[$code]->is_protected) {
                    $afterProtected[] = $code;
                }
            }
            sort($beforeProtected, SORT_STRING);
            sort($afterProtected, SORT_STRING);
            if (! $admin && $beforeProtected !== $afterProtected) {
                throw new AuthorizationException('Protected permissions require System Administrator.');
            }

            $toRemove = array_diff($before, $requestedCodes);
            $toAdd = array_diff($requestedCodes, $before);
            if ($toRemove !== []) {
                DB::table('kfos_role_permissions')->where('role_id', $roleId)
                    ->whereIn('permission_id', $currentRows->whereIn('code', array_values($toRemove))->pluck('id')->all())
                    ->delete();
            }
            foreach ($toAdd as $code) {
                DB::table('kfos_role_permissions')->insert([
                    'role_id' => $roleId, 'permission_id' => $all[$code]->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit($actor, null, 'kfos_role_permissions_changed', [
                'role_id' => $roleId,
                'actor_capacity' => $admin ? 'system_administrator' : 'edit_role_permissions',
                'before' => $before, 'after' => $requestedCodes,
                'reason' => $this->checkReason($reason),
            ]);
            // AUTH-034: all CURRENT members lose sessions; role definition is shared.
            $members = DB::table('kfos_operational_role_assignments')
                ->where('role_id', $roleId)->orderBy('user_id')->pluck('user_id');
            foreach ($members as $userId) {
                $this->revokeSessions((int) $userId);
            }
            return true;
        }, 3);
    }

    /**
     * Assign, replace or clear exactly ONE operational role on an ordinary employee.
     * A delegate may transfer AWAY from a protected role but never INTO one.
     */
    public function assignOperationalRole(User $actor, int $targetUserId, ?int $newRoleId, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($actor, $targetUserId, $newRoleId, $reason): bool {
            $this->mutationLock();
            $admin = $this->requirePermission($actor, 'security.roles.assign');
            $target = DB::table('users')->where('id', $targetUserId)->lockForUpdate()->first();
            if ($target === null || $target->is_development_only || $target->employee_id === null
                || ! DB::table('employees')->where('id', $target->employee_id)->exists()) {
                throw new AuthorizationException('Eligible employee account required.');
            }
            // Coexistence of universal and operational membership is not approved yet.
            if (DB::table('kfos_system_administrators')->where('user_id', $targetUserId)->exists()) {
                throw new AuthorizationException('Universal administrator operational-role coexistence is pending.');
            }
            $old = DB::table('kfos_operational_role_assignments')
                ->where('user_id', $targetUserId)->lockForUpdate()->value('role_id');
            $oldRoleId = $old === null ? null : (int) $old;
            if ($newRoleId === $oldRoleId) {
                return false;
            }
            if ($newRoleId !== null) {
                $role = DB::table('kfos_roles')->where('id', $newRoleId)->lockForUpdate()->first();
                if ($role === null || $role->kind !== 'operational' || ! $role->is_active) {
                    throw new AuthorizationException('Destination operational role is unavailable.');
                }
                if (! $target->is_active || ! DB::table('employees')->where('id', $target->employee_id)->where('is_active', true)->exists()) {
                    throw new AuthorizationException('Inactive account cannot receive a role.');
                }
                $protected = DB::table('kfos_role_permissions as rp')
                    ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
                    ->where('rp.role_id', $newRoleId)->where('p.is_protected', true)->exists();
                if ($protected && ! $admin) {
                    throw new AuthorizationException('Only System Administrator may assign into a protected role.');
                }
            }
            if ($newRoleId === null) {
                DB::table('kfos_operational_role_assignments')->where('user_id', $targetUserId)->delete();
            } elseif ($oldRoleId === null) {
                DB::table('kfos_operational_role_assignments')->insert([
                    'user_id' => $targetUserId, 'role_id' => $newRoleId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('kfos_operational_role_assignments')->where('user_id', $targetUserId)
                    ->update(['role_id' => $newRoleId, 'updated_at' => now()]);
            }
            $this->audit($actor, $targetUserId, 'kfos_operational_role_assignment_changed', [
                'actor_capacity' => $admin ? 'system_administrator' : 'assign_employee_role',
                'before_role_id' => $oldRoleId, 'after_role_id' => $newRoleId,
                'reason' => $this->checkReason($reason),
            ]);
            // AUTH-033: invalidate all target sessions, including Remember me continuation.
            $this->revokeSessions($targetUserId);
            return true;
        }, 3);
    }
}
