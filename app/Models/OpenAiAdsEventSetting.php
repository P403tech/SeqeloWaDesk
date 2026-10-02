<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Local mirror of an OpenAI Ads conversion event setting (remote_id = ces_...). */
class OpenAiAdsEventSetting extends Model
{
    protected $table = 'openai_ads_event_settings';

    protected $fillable = [
        'workspace_id', 'remote_id', 'name', 'event_type', 'custom_event_name',
        'attribution_window_days', 'source_ids', 'pixel_local_id', 'meta_json',
    ];

    protected $casts = [
        'source_ids'              => 'array',
        'attribution_window_days' => 'integer',
        'meta_json'               => 'array',
    ];

    public function scopeForWorkspace(Builder $q, ?int $wsId): Builder
    {
        return $wsId ? $q->where('workspace_id', $wsId) : $q->whereRaw('1=0');
    }
}
