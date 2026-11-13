<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only effective authorization. No role-changing HTTP routes are exposed. */
final class KfosAuthorization
{
    private function schemaReady(): bool
    {
        foreach ([
            'kfos_roles', 'kfos_permissions', 'kfos_role_permissions',
            'kfos_operational_role_assignments', 'kfos_system_administrators',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false; // Fail closed until the foundation is migrated.
            }
        }
        return true;
    }

    private function eligible(User $user, bool $requireActiveEmployment = true): bool
    {
        if (! $user->exists) {
            return false;
        }
        $query = DB::table('users as u')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->where('u.id', $user->getKey())
            ->where('u.is_active', true)
            ->where('u.is_development_only', false);
        if ($requireActiveEmployment) {
            $query->where('e.is_active', true);
        }
        return $query->exists();
    }

    public function isSystemAdministrator(User $user): bool
    {
        if (! $this->schemaReady() || ! $this->eligible($user, false)) {
            return false;
        }

        return DB::table('kfos_system_administrators as a')
            ->where('a.user_id', $user->getKey())
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('kfos_roles as r')
                    ->where('r.code', 'system_administrator')
                    ->where('r.kind', 'universal')
                    ->where('r.is_active', true);
            })->exists();
    }

    public function allows(User $user, string $permissionCode): bool
    {
        if ($permissionCode === '' || ! $this->schemaReady()) {
            return false;
        }

        // Unknown permission codes are never silently authorized.
        if (! DB::table('kfos_permissions')->where('code', $permissionCode)->exists()) {
            return false;
        }

        // The genuine universal administrator is the ONLY exception to the
        // original Employee Master's Employment Inactive restriction.
        if ($this->isSystemAdministrator($user)) {
            return true;
        }
        if (! $this->eligible($user, true)) {
            return false;
        }

        return DB::table('kfos_operational_role_assignments as a')
            ->join('kfos_roles as r', 'r.id', '=', 'a.role_id')
            ->join('kfos_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('kfos_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.user_id', $user->getKey())
            ->where('r.kind', 'operational')
            ->where('r.is_active', true)
            ->where('p.code', $permissionCode)
            ->exists();
    }
}
