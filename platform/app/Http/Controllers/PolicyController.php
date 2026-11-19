<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\PolicyAcknowledgment;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $policy = config('thryve.policy');

        return Api::ok([
            'version' => $policy['version'],
            'title' => $policy['title'],
            'body' => $policy['body'],
            'acknowledged' => $request->user()->hasAcknowledgedPolicy(),
        ]);
    }

    public function acknowledge(Request $request): JsonResponse
    {
        $request->validate(['accept' => ['required', 'accepted']]);
        $user = $request->user();
        $version = config('thryve.policy.version');

        $ack = PolicyAcknowledgment::firstOrCreate(
            ['user_id' => $user->id, 'policy_version' => $version],
            ['acknowledged_at' => now()],
        );
        if ($ack->wasRecentlyCreated) {
            Audit::log('policy.acknowledged', $user, $user, ['version' => $version]);
        }

        return Api::ok(['acknowledged' => true, 'version' => $version]);
    }
}
