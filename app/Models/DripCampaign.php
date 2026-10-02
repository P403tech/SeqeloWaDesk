<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drip campaign: an ordered set of steps, each with a wait before it,
 * delivered to enrolled contacts over days or weeks.
 *
 * Distinct from a Flow. A Flow is conversational and reacts to what the
 * contact says; a drip is a one-way schedule that runs whether or not the
 * contact replies — and stops the moment they do, if configured to.
 */
class DripCampaign extends Model
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const TRIGGERS = [
        'manual'          => 'Manual — I pick the contacts',
        'contact_created' => 'When a contact is created',
        'tag_added'       => 'When a tag is added',
        'group_added'     => 'When added to a contact group',
        'deal_created'    => 'When a CRM deal is created',
    ];

    /** What ends a sequence early because it already worked. */
    public const GOALS = [
        ''                   => 'No goal — run every step',
        'tag_added'          => 'A tag is added',
        'appointment_booked' => 'They book an appointment',
        'order_placed'       => 'They place an order',
    ];

    protected $fillable = [
        'workspace_id', 'user_id', 'name', 'status',
        'trigger_type', 'trigger_value',
        'device_id', 'provider',
        'timezone', 'quiet_start_hour', 'quiet_end_hour',
        'stop_on_reply', 'stop_on_deal_won',
        'goal_type', 'goal_value',
    ];

    protected $casts = [
        'stop_on_reply'    => 'boolean',
        'stop_on_deal_won' => 'boolean',
        'quiet_start_hour' => 'integer',
        'quiet_end_hour'   => 'integer',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(DripCampaignStep::class)->orderBy('position');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(DripSubscriber::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Only a campaign with at least one step can meaningfully run. */
    public function isLaunchable(): bool
    {
        return $this->steps()->count() > 0;
    }

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }
}
