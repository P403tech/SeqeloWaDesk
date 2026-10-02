<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a drip sequence, plus the wait that precedes it.
 *
 * The wait is stored as amount + unit rather than seconds so the UI can show
 * "3 days" back to the operator exactly as they typed it.
 */
class DripCampaignStep extends Model
{
    protected $fillable = [
        'drip_campaign_id', 'position',
        'delay_amount', 'delay_unit',
        'message_type', 'body', 'template_id', 'var_map',
        'media_path', 'media_type',
    ];

    protected $casts = [
        'position'     => 'integer',
        'delay_amount' => 'integer',
        'var_map'      => 'array',
        // Message bodies carry patient/customer detail — same protection the
        // rest of the app gives message content.
        'body'         => \App\Casts\SafeEncrypted::class,
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DripCampaign::class, 'drip_campaign_id');
    }

    /** The wait before this step, in seconds. */
    public function delaySeconds(): int
    {
        $mult = match ($this->delay_unit) {
            'minute', 'min'  => 60,
            'day', 'days'    => 86400,
            default          => 3600,   // hour
        };

        return max(0, (int) $this->delay_amount) * $mult;
    }

    /** "Immediately" / "after 3 days" — for the builder and the timeline. */
    public function delayLabel(): string
    {
        if ((int) $this->delay_amount <= 0) {
            return __('Immediately');
        }

        $unit = (int) $this->delay_amount === 1
            ? rtrim($this->delay_unit, 's')
            : rtrim($this->delay_unit, 's') . 's';

        return __('after :n :unit', ['n' => $this->delay_amount, 'unit' => $unit]);
    }
}
