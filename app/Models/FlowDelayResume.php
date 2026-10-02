<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One pending "resume this flow at a later time" record — the durable form of a
 * long duration-delay node. Written by Node when it parks a long delay; drained
 * by FlowDelayResumeService on the heartbeat. See the migration for the why.
 */
class FlowDelayResume extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_DONE      = 'done';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'flow_id', 'flow_subscriber_id',
        'session_key', 'device_phone', 'customer_phone',
        'node_id', 'provider', 'variables',
        'resume_at', 'status', 'attempts', 'last_error', 'resumed_at',
    ];

    protected $casts = [
        'variables'  => 'array',
        'resume_at'  => 'datetime',
        'resumed_at' => 'datetime',
        'attempts'   => 'int',
    ];
}
