<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lead-scoring rule: when {signal} fires and {conditions} match, add
 * {points} to the contact's lead_score. Same conditions shape as RoutingRule
 * ([[field, op, value], …], AND-ed) so the builder UI can be shared.
 */
class LeadScoringRule extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'signal', 'points', 'conditions',
        'is_active', 'sort', 'fired_count', 'last_fired_at',
    ];

    protected $casts = [
        'conditions'    => 'array',
        'points'        => 'integer',
        'is_active'     => 'boolean',
        'sort'          => 'integer',
        'fired_count'   => 'integer',
        'last_fired_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForWorkspace(Builder $q, int $workspaceId): Builder
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function scopeForSignal(Builder $q, string $signal): Builder
    {
        return $q->where('signal', $signal);
    }
}
