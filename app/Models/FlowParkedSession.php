<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A snapshot of a flow session parked waiting for a customer reply — the
 * durable form of an in-memory activeFlowSessions entry, so a Node restart
 * can't drop a parked flow. One row per session_key. See the migration.
 */
class FlowParkedSession extends Model
{
    protected $fillable = [
        'session_key', 'flow_id', 'flow_subscriber_id',
        'device_phone', 'customer_phone', 'node_id',
        'waiting', 'variables', 'provider',
    ];

    protected $casts = [
        'waiting'   => 'array',
        'variables' => 'array',
    ];
}
