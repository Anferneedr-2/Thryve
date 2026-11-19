<?php

namespace App\Http\Controllers;

use App\Attendance\Tracker;
use App\Http\Responses\Api;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

class SessionAdminController extends Controller
{
    /** Super Admin only (route guard): end a user's active session immediately (their next request is rejected). */
    public function terminate(User $user): JsonResponse
    {
        $user->forceFill(['session_id' => null])->save();
        Audit::log('auth.session_terminated', request()->user(), $user);
        app(Tracker::class)->safely(fn (Tracker $t) => $t->cut($user, 'terminated'));

        return Api::ok(['terminated' => true]);
    }
}
