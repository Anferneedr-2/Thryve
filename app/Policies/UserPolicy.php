<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Role boundary (assumption pending decision D07):
 *  - Super Admin manages every account.
 *  - Admin manages Employee accounts only and cannot create or edit Admins or Super Admins.
 *  - Employees manage nothing (they only change their own password).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(Role::ADMIN, Role::SUPER_ADMIN);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->viewAny($actor);
    }

    /** May the actor create (or assign) an account with this role? */
    public function assignRole(User $actor, string $role): bool
    {
        return match (true) {
            $actor->hasRole(Role::SUPER_ADMIN) => array_key_exists($role, Role::ALL),
            $actor->hasRole(Role::ADMIN) => $role === Role::EMPLOYEE,
            default => false,
        };
    }

    /** Only a Super Admin may view as someone; which people qualify is decided by Impersonation::blockedReason(). */
    public function impersonate(User $actor, User $target): bool
    {
        return $actor->hasRole(Role::SUPER_ADMIN);
    }

    public function manage(User $actor, User $target): bool
    {
        return match (true) {
            $actor->hasRole(Role::SUPER_ADMIN) => true,
            $actor->hasRole(Role::ADMIN) => $target->hasRole(Role::EMPLOYEE),
            default => false,
        };
    }
}
