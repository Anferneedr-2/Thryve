<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Log in as": a Super Admin's browser acts as an employee without touching the employee's own session.
 * The Super Admin's id is kept in the browser session; the signed-in account is switched inside that same session
 * (no new session id), so the Super Admin's own single session stays valid the whole time.
 */
final class Impersonation
{
    public const KEY = 'impersonator_id';

    /** The Super Admin behind this browser, or null when nobody is being viewed as. */
    public static function impersonatorId(?Request $request = null): ?int
    {
        $request ??= request();

        return $request && $request->hasSession() ? ($request->session()->get(self::KEY) ?: null) : null;
    }

    public static function active(?Request $request = null): bool
    {
        return self::impersonatorId($request) !== null;
    }

    public static function impersonator(?Request $request = null): ?User
    {
        $id = self::impersonatorId($request);

        return $id ? User::find($id) : null;
    }

    /**
     * Why this person cannot be viewed as, in words an admin can read. Null means it is allowed.
     * The same rules decide the button on the People page and the server's answer.
     */
    public static function blockedReason(User $actor, User $target): ?string
    {
        return match (true) {
            ! $actor->hasRole(Role::SUPER_ADMIN) => 'Only a Super Admin can view as another person.',
            $actor->is($target) => 'This is your own account.',
            ! $target->hasRole(Role::EMPLOYEE) => 'Only employee accounts can be viewed.',
            ! $target->is_active => 'This account is deactivated.',
            $target->must_change_password => 'They have not set their own password yet.',
            ! $target->hasAcknowledgedPolicy() => 'They have not accepted the usage policy yet.',
            default => null,
        };
    }
}
