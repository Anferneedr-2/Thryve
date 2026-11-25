<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\User;
use App\Support\Audit;
use App\Support\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * A Super Admin views the app as an employee. Nothing on the employee's record changes: no session id, no last sign-in,
 * no attendance. Every start and end is audited with the Super Admin as the actor.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        Gate::authorize('impersonate', $user);
        $actor = $request->user();
        if ($reason = Impersonation::blockedReason($actor, $user)) {
            return Api::error('cannot_view_as', $reason, 422);
        }

        $session = $request->session();
        $session->put(Auth::guard('web')->getName(), $user->getAuthIdentifier()); // same session id, different account
        $session->put(Impersonation::KEY, $actor->id);
        Audit::log('impersonation.started', $actor, $user);

        return Api::ok(['viewing_as' => $user->name]);
    }

    public function stop(Request $request): JsonResponse
    {
        $real = Impersonation::impersonator($request);
        if (! $real) {
            return Api::error('not_viewing_as', 'You are not viewing as anyone.', 409);
        }
        $employee = $request->user();

        $session = $request->session();
        $session->put(Auth::guard('web')->getName(), $real->getAuthIdentifier());
        $session->forget(Impersonation::KEY);
        Audit::log('impersonation.ended', $real, $employee);

        return Api::ok(['returned' => true]);
    }
}
