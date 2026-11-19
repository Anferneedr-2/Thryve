<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What the browser may know about a user. Never includes password, session id or internal flags. */
class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->slug,
            'is_active' => $this->is_active,
            'daily_token_limit' => $this->daily_token_limit,
            'departments' => $this->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
