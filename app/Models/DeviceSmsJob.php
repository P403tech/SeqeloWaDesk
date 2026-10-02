<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued outbound SMS for a device gateway.
 * status: pending → dispatched (pulled by the app) → sent / failed → delivered
 */
class DeviceSmsJob extends Model
{
    protected $fillable = [
        'workspace_id', 'gateway_id', 'to_number', 'body', 'sim_slot',
        'status', 'error', 'source', 'source_ref',
        'claimed_at', 'sent_at', 'delivered_at',
    ];

    protected $casts = [
        'sim_slot'     => 'integer',
        'claimed_at'   => 'datetime',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(DeviceSmsGateway::class, 'gateway_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }
}
