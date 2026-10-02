<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI SDR campaign — a cadence flow wrapped with qualification (enrol gate),
 * scoring-based human handoff (route_score → route_team_id) and stop-on-
 * reply/convert controls.
 */
class SdrCampaign extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'is_active', 'flow_id',
        'enroll_min_score', 'route_score', 'route_team_id',
        'stop_on_reply', 'stop_on_convert', 'enroll_conditions',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'flow_id'           => 'integer',
        'enroll_min_score'  => 'integer',
        'route_score'       => 'integer',
        'route_team_id'     => 'integer',
        'stop_on_reply'     => 'boolean',
        'stop_on_convert'   => 'boolean',
        'enroll_conditions' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SdrEnrollment::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForWorkspace(Builder $q, int $workspaceId): Builder
    {
        return $q->where('workspace_id', $workspaceId);
    }
}
