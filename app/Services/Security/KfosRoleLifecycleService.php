<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Transactional, server-only role definition operations; no public routes. */
final class KfosRoleLifecycleService
{
    public function __construct(private KfosAuthorization $authorization) {}

    private function lock(): void
    {
        // Must remain identical to KfosRoleChangeService mutation lock.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261111, 5]);
    }

    private function requirePermission(User $actor, string $code): bool
    {
        $fresh = DB::table('users')->where('id', $actor->getKey())->lockForUpdate()->first();
        $currentId = Auth::guard('web')->id();
        $sessionVersion = session()->get('kfos_session_version');
        if ($currentId === null || (int) $currentId !== (int) $actor->getKey()
            || $fresh === null || $sessionVersion === null
            || (int) $sessionVersion !== (int) $fresh->session_version
            || ! $this->authorization->allows($actor, $code)) {
            throw new AuthorizationException('This action is not authorized.');
        }
        return $this->authorization->isSystemAdministrator($actor);
    }

    private function normalizedName(string $input): string
    {
        $name = trim(preg_replace('/[[:space:]]+/u', ' ', $input) ?? '');
        // ASCII for this first implementation prevents unreviewed Unicode impersonation.
        // Additional character sets require separately reviewed confusable normalization.
        if (strlen($name) < 2 || strlen($name) > 80
            || ! preg_match('/\A[A-Za-z0-9 ()\/&-]+\z/D', $name)) {
            throw ValidationException::withMessages(['name' => 'Invalid operational role name.']);
        }
        $stripped = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
        if (in_array($stripped, ['systemadministrator', 'systemadmin', 'sysadmin', 'superadmin', 'superadministrator'], true)) {
            throw ValidationException::withMessages(['name' => 'Protected role name is unavailable.']);
        }
        return $name;
    }

    private function reason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Reason is too long.']);
        }
        return $reason === '' ? null : $reason;
    }

    private function ensureNameAvailable(string $name, ?int $exceptId = null): void
    {
        // The PostgreSQL index is the final concurrency-safe duplicate control.
        $q = DB::table('kfos_roles')->whereRaw(
            "lower(regexp_replace(btrim(name), '[[:space:]]+', ' ', 'g')) = lower(regexp_replace(btrim(?), '[[:space:]]+', ' ', 'g'))",
            [$name]
        );
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages(['name' => 'Role name already exists.']);
        }
    }

    private function audit(User $actor, string $event, array $details): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $actor->getKey(), 'target_user_id' => null,
            'event_type' => $event, 'result' => 'success',
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'is_shared_dev_identity' => false, 'created_at' => now(),
        ]);
    }

    private function mapNameConflict(QueryException $e): never
    {
        if ($e->getCode() === '23505') {
            throw ValidationException::withMessages(['name' => 'Role name or code already exists.']);
        }
        throw $e;
    }

    /** Creates an unassigned active operational role; zero permissions is valid. */
    public function createOperationalRole(User $actor, string $name, array $permissionCodes = [], ?string $reason = null): int
    {
        try {
            return DB::transaction(function () use ($actor, $name, $permissionCodes, $reason): int {
                $this->lock();
                $admin = $this->requirePermission($actor, 'security.roles.create');
                $name = $this->normalizedName($name);
                $reason = $this->reason($reason);
                $this->ensureNameAvailable($name);
                if (count($permissionCodes) !== count(array_unique($permissionCodes, SORT_REGULAR))) {
                    throw ValidationException::withMessages(['permissions' => 'Duplicate permission codes.']);
                }
                foreach ($permissionCodes as $code) {
                    if (! is_string($code) || $code === '') {
                        throw ValidationException::withMessages(['permissions' => 'Invalid permission code.']);
                    }
                }
                sort($permissionCodes, SORT_STRING);
                $perms = DB::table('kfos_permissions')->whereIn('code', $permissionCodes)
                    ->get(['id', 'code', 'is_protected'])->keyBy('code');
                if ($perms->count() !== count($permissionCodes)) {
                    throw ValidationException::withMessages(['permissions' => 'Unknown permission code.']);
                }
                foreach ($permissionCodes as $code) {
                    if ((bool) $perms[$code]->is_protected && ! $admin) {
                        throw new AuthorizationException('Only System Administrator may select protected permissions.');
                    }
                }
                $roleId = DB::table('kfos_roles')->insertGetId([
                    'code' => 'op_'.strtolower(Str::random(30)), 'name' => $name,
                    'kind' => 'operational', 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($permissionCodes as $code) {
                    DB::table('kfos_role_permissions')->insert([
                        'role_id' => $roleId, 'permission_id' => $perms[$code]->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->audit($actor, 'kfos_operational_role_created', [
                    'role_id' => $roleId, 'before' => null,
                    'after' => ['name' => $name, 'status' => 'active', 'permissions' => $permissionCodes],
                    'actor_capacity' => $admin ? 'system_administrator' : 'add_role',
                    'reason' => $reason,
                ]);
                return $roleId;
            }, 3);
        } catch (QueryException $e) {
            $this->mapNameConflict($e);
        }
    }

    /** Name-only rename preserves role ID, membership, permissions and session versions. */
    public function renameOperationalRole(User $actor, int $roleId, string $newName, ?string $reason = null): bool
    {
        try {
            return DB::transaction(function () use ($actor, $roleId, $newName, $reason): bool {
                $this->lock();
                $admin = $this->requirePermission($actor, 'security.roles.edit_permissions');
                $role = DB::table('kfos_roles')->where('id', $roleId)->lockForUpdate()->first();
                if ($role === null || $role->kind !== 'operational') {
                    throw new AuthorizationException('Operational role is unavailable.');
                }
                $newName = $this->normalizedName($newName);
                $reason = $this->reason($reason);
                if ($role->name === $newName) {
                    return false;
                }
                $this->ensureNameAvailable($newName, $roleId);
                DB::table('kfos_roles')->where('id', $roleId)->update([
                    'name' => $newName, 'updated_at' => now(),
                ]);
                $this->audit($actor, 'kfos_operational_role_renamed', [
                    'role_id' => $roleId, 'before' => ['name' => $role->name],
                    'after' => ['name' => $newName],
                    'actor_capacity' => $admin ? 'system_administrator' : 'edit_role_permissions',
                    'reason' => $reason,
                ]);
                return true;
            }, 3);
        } catch (QueryException $e) {
            $this->mapNameConflict($e);
        }
    }

    /** Disable only when ZERO current members (including inactive login accounts). */
    public function setOperationalRoleActive(User $actor, int $roleId, bool $active, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($actor, $roleId, $active, $reason): bool {
            $this->lock();
            $admin = $this->requirePermission($actor, 'security.roles.edit_permissions');
            $role = DB::table('kfos_roles')->where('id', $roleId)->lockForUpdate()->first();
            if ($role === null || $role->kind !== 'operational') {
                throw new AuthorizationException('Operational role is unavailable.');
            }
            $reason = $this->reason($reason);
            if ((bool) $role->is_active === $active) {
                return false;
            }
            if (! $active && DB::table('kfos_operational_role_assignments')->where('role_id', $roleId)->exists()) {
                throw ValidationException::withMessages([
                    'role' => 'Role has current employee assignments and cannot be disabled.',
                ]);
            }
            DB::table('kfos_roles')->where('id', $roleId)->update([
                'is_active' => $active, 'updated_at' => now(),
            ]);
            $this->audit($actor, $active ? 'kfos_operational_role_reactivated' : 'kfos_operational_role_disabled', [
                'role_id' => $roleId, 'before' => ['status' => $active ? 'disabled' : 'active'],
                'after' => ['status' => $active ? 'active' : 'disabled'],
                'actor_capacity' => $admin ? 'system_administrator' : 'edit_role_permissions',
                'reason' => $reason,
            ]);
            return true;
        }, 3);
    }
}
