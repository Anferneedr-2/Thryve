<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Agent;
use App\Support\AgentAvatar;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The optional picture of a chatbot. Anyone signed in may see it (it is only a picture); only a Super Admin changes it. */
class AgentAvatarController extends Controller
{
    public function show(int $id): Response
    {
        $agent = Agent::active()->findOrFail($id);
        $path = AgentAvatar::find($agent->id);
        abort_unless($path, 404);

        return response(AgentAvatar::read($path), 200, [
            'Content-Type' => AgentAvatar::contentType($path),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** Super Admin only (route guard). */
    public function upload(Request $request, int $id): JsonResponse
    {
        $agent = Agent::active()->findOrFail($id);
        $request->validate([
            'avatar' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ]);
        AgentAvatar::store($agent->id, $request->file('avatar'));
        Audit::log('agent.avatar_updated', $request->user(), null, ['agent_id' => $agent->id, 'action' => 'uploaded']);

        return Api::ok(['id' => $agent->id, 'avatar' => AgentAvatar::url($agent->id)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agent = Agent::active()->findOrFail($id);
        AgentAvatar::remove($agent->id);
        Audit::log('agent.avatar_updated', $request->user(), null, ['agent_id' => $agent->id, 'action' => 'removed']);

        return Api::ok(['id' => $agent->id, 'avatar' => null]);
    }
}
