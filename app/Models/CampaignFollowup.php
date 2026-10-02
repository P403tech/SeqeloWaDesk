<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One follow-up RULE on a campaign: WHEN <trigger_event> [+ delay] THEN
 * <action_type>(<action_ref_id>). See the migration for the full field map.
 */
class CampaignFollowup extends Model
{
    /** Trigger events. The *_no_* ones are time-delayed (need delay_minutes). */
    public const EVENT_REPLIED           = 'replied';
    public const EVENT_CLICKED_BUTTON    = 'clicked_button';
    public const EVENT_CLICKED_LINK      = 'clicked_link';
    public const EVENT_READ              = 'read';
    public const EVENT_DELIVERED_NO_READ = 'delivered_no_read';
    public const EVENT_READ_NO_REPLY     = 'read_no_reply';
    public const EVENT_SENT_NO_REPLY     = 'sent_no_reply';
    public const EVENT_NOT_DELIVERED     = 'not_delivered';
    public const EVENT_FAILED            = 'failed';

    /** Events that leave the 24h window CLOSED — their action MUST be a template
     *  (or a flow/drip whose first outbound is a template). No free-form. */
    public const WINDOW_CLOSED_EVENTS = [
        self::EVENT_CLICKED_LINK, self::EVENT_READ, self::EVENT_DELIVERED_NO_READ,
        self::EVENT_READ_NO_REPLY, self::EVENT_SENT_NO_REPLY, self::EVENT_NOT_DELIVERED,
    ];

    /** Events evaluated by the sweeper after a delay (vs immediate at webhook). */
    public const DELAYED_EVENTS = [
        self::EVENT_DELIVERED_NO_READ, self::EVENT_READ_NO_REPLY,
        self::EVENT_SENT_NO_REPLY, self::EVENT_NOT_DELIVERED,
    ];

    public const ACTION_START_FLOW      = 'start_flow';
    public const ACTION_SEND_TEMPLATE   = 'send_template';
    public const ACTION_ENROLL_DRIP     = 'enroll_drip';
    public const ACTION_SEND_MESSAGE    = 'send_message';
    public const ACTION_ADD_TAG         = 'add_tag';
    public const ACTION_REMOVE_TAG      = 'remove_tag';
    public const ACTION_ASSIGN_AGENT    = 'assign_agent';
    public const ACTION_ADD_TO_CAMPAIGN = 'add_to_campaign';
    public const ACTION_OPT_OUT         = 'opt_out';

    protected $fillable = [
        'campaign_id', 'workspace_id',
        'trigger_event', 'delay_minutes', 'condition_json',
        'action_type', 'action_ref_id', 'action_payload_json',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'delay_minutes'       => 'integer',
        'condition_json'      => 'array',
        'action_ref_id'       => 'integer',
        'action_payload_json' => 'array',
        'is_active'           => 'boolean',
        'sort_order'          => 'integer',
    ];

    public function isDelayed(): bool
    {
        return in_array($this->trigger_event, self::DELAYED_EVENTS, true);
    }

    /** True when this rule's trigger leaves the 24h window closed, so a free-form
     *  action (send_message, or a flow with a free-form entry) is not allowed. */
    public function requiresTemplate(): bool
    {
        return in_array($this->trigger_event, self::WINDOW_CLOSED_EVENTS, true);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WpCampaign::class, 'campaign_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CampaignFollowupRun::class);
    }

    /**
     * Build the {id, name} option lists the rule-builder UI needs, from the
     * workspace's flows / templates / drips / tags (+ the campaign's existing
     * rules). SINGLE SOURCE OF TRUTH for both the create and edit blades and the
     * test — so a wrong label column (e.g. flows use `flow_name`, not `name`)
     * is caught once. Each entry falls back to "#id" only when the label is
     * genuinely blank.
     */
    public static function buildPickerOptions($flows, $templates, $drips, $tags, $campaign = null, $agents = null): array
    {
        $map = fn ($col, string $labelField) => collect($col)->map(function ($m) use ($labelField) {
            $label = trim((string) ($m->{$labelField} ?? ''));
            return ['id' => (int) $m->id, 'name' => $label !== '' ? $label : ('#' . $m->id)];
        })->values()->all();

        return [
            'templates' => $map($templates, 'template_name'),
            'flows'     => $map($flows, 'flow_name'),
            'drips'     => $map($drips, 'name'),
            'tags'      => $map($tags, 'name'),
            'agents'    => $map($agents ?? collect(), 'name'),
            'existing'  => $campaign
                ? collect($campaign->followups)->map(fn ($r) => [
                    'trigger_event' => $r->trigger_event,
                    'delay_minutes' => $r->delay_minutes,
                    'action_type'   => $r->action_type,
                    'action_ref_id' => $r->action_ref_id,
                ])->values()->all()
                : [],
        ];
    }
}
