<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Local mirror of an OpenAI Ads ad (remote_id = ad_...). */
class OpenAiAdsAd extends Model
{
    use SoftDeletes;

    protected $table = 'openai_ads_ads';

    protected $fillable = [
        'workspace_id', 'ad_group_id', 'remote_id', 'name', 'status',
        'review_status', 'creative_type', 'title', 'body', 'price',
        'target_url', 'file_id', 'preview_url', 'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(OpenAiAdsAdGroup::class, 'ad_group_id');
    }
}
