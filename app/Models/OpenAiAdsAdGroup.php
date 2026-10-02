<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Local mirror of an OpenAI Ads ad group (remote_id = adgrp_...). */
class OpenAiAdsAdGroup extends Model
{
    use SoftDeletes;

    protected $table = 'openai_ads_ad_groups';

    protected $fillable = [
        'workspace_id', 'campaign_id', 'remote_id', 'name', 'status',
        'billing_event_type', 'max_bid_micros', 'meta_json',
    ];

    protected $casts = [
        'max_bid_micros' => 'integer',
        'meta_json'      => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(OpenAiAdsCampaign::class, 'campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(OpenAiAdsAd::class, 'ad_group_id');
    }
}
