<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Agent extends Model
{
    protected $guarded = [];

    // Keys never leave the server in a response; the one-time reveal is built by hand in the controller.
    protected $hidden = ['outbound_key', 'pending_key', 'inbound_key_hash'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean', 'is_default' => 'boolean',
            'outbound_key' => 'encrypted', 'pending_key' => 'encrypted',
            'inbound_last_seen_at' => 'datetime', 'last_checked_at' => 'datetime', 'removed_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AgentCheck::class);
    }

    public const TYPE_CHAT = 'chat';

    public const TYPE_REPORT = 'report';

    public function scopeActive($q)
    {
        return $q->whereNull('removed_at');
    }

    /** Agents people can talk to. Report agents are background-only and never appear in any chat list. */
    public function scopeChat($q)
    {
        return $q->where('type', self::TYPE_CHAT);
    }

    /**
     * Chatbots that can be given to an individual employee: every active chat agent except the default,
     * which is open to everyone.
     *
     * @return array<int, int>
     */
    public static function assignableIds(): array
    {
        return self::active()->chat()->where('is_default', false)->pluck('id')->all();
    }

    public function scopeReport($q)
    {
        return $q->where('type', self::TYPE_REPORT);
    }

    public function isReport(): bool
    {
        return $this->type === self::TYPE_REPORT;
    }

    /** Full address of this agent's Hermes profile. */
    public function url(?string $profile = null): string
    {
        $profile ??= $this->profile;

        return rtrim($this->base_url, '/').($profile ? "/p/{$profile}" : '');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('granted_by', 'created_at');
    }

    /**
     * Chatbots this person may start or continue: enabled agents that have a key. Admins and Super Admins get all of
     * them; everyone else gets the default agent plus the agents assigned to them one by one.
     */
    public static function availableTo(User $user)
    {
        $q = static::active()->chat()->where('is_enabled', true)->whereNotNull('outbound_key');
        if (! $user->hasRole(Role::ADMIN, Role::SUPER_ADMIN)) {
            $q->where(fn ($w) => $w->where('is_default', true)
                ->orWhereIn('id', DB::table('agent_user')->where('user_id', $user->id)->select('agent_id')));
        }

        return $q;
    }

    public static function canUse(User $user, Agent $agent): bool
    {
        return static::availableTo($user)->whereKey($agent->id)->exists();
    }
}
