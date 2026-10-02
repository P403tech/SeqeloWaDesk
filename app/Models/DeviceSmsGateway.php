<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A linked Android phone acting as an SMS gateway (sends via its own SIM).
 * `device_token` is the phone's identity + auth (sent as the X-Gateway-Token
 * header on every device-facing API call).
 */
class DeviceSmsGateway extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'device_token', 'fcm_token', 'sims',
        'default_sim_slot', 'status', 'battery', 'last_seen_at',
        'per_sim_daily_quota', 'delay_ms', 'app_version',
    ];

    protected $casts = [
        'sims'                => 'array',
        'default_sim_slot'    => 'integer',
        'battery'             => 'integer',
        'last_seen_at'        => 'datetime',
        'per_sim_daily_quota' => 'integer',
        'delay_ms'            => 'integer',
    ];

    protected $hidden = ['device_token'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(DeviceSmsJob::class, 'gateway_id');
    }

    public function scopeForWorkspace(Builder $q, int $workspaceId): Builder
    {
        return $q->where('workspace_id', $workspaceId);
    }

    /** A phone counts as online if it heartbeat within the last ~2 minutes. */
    public function isOnline(): bool
    {
        return $this->status === 'online'
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    /** Generate a unique, unguessable device token for a new link. */
    public static function newToken(): string
    {
        do {
            $token = 'dsg_' . Str::random(48);
        } while (self::where('device_token', $token)->exists());

        return $token;
    }
}
