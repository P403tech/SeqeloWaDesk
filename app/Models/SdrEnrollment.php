<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lead's journey through an SDR campaign.
 * status: active | routed | converted | stopped | completed
 */
class SdrEnrollment extends Model
{
    protected $fillable = [
        'workspace_id', 'sdr_campaign_id', 'contact_id', 'status',
        'score_at_enroll', 'enrolled_at', 'routed_at', 'stopped_at', 'stopped_reason',
    ];

    protected $casts = [
        'score_at_enroll' => 'integer',
        'enrolled_at'     => 'datetime',
        'routed_at'       => 'datetime',
        'stopped_at'      => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SdrCampaign::class, 'sdr_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeForWorkspace(Builder $q, int $workspaceId): Builder
    {
        return $q->where('workspace_id', $workspaceId);
    }
}
