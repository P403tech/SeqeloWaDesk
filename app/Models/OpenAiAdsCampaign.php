<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Local mirror of an OpenAI Ads campaign (remote_id = cmpn_...). */
class OpenAiAdsCampaign extends Model
{
    use SoftDeletes;

    // Explicit — Laravel would otherwise infer `open_ai_ads_campaigns`.
    protected $table = 'openai_ads_campaigns';

    protected $fillable = [
        'workspace_id', 'user_id', 'remote_id', 'name', 'status',
        'bidding_type', 'budget_micros', 'conversion_event_setting_ids',
        'last_synced_at', 'meta_json',
    ];

    protected $casts = [
        'budget_micros'                => 'integer',
        'conversion_event_setting_ids' => 'array',
        'meta_json'                    => 'array',
        'last_synced_at'               => 'datetime',
    ];

    public function adGroups(): HasMany
    {
        return $this->hasMany(OpenAiAdsAdGroup::class, 'campaign_id');
    }

    public function scopeForWorkspace(Builder $q, ?int $wsId): Builder
    {
        return $wsId ? $q->where('workspace_id', $wsId) : $q->whereRaw('1=0');
    }
}
