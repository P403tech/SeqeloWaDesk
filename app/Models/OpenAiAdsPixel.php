<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Local mirror of an OpenAI Ads web pixel (remote_id = clidsrc_...). */
class OpenAiAdsPixel extends Model
{
    protected $table = 'openai_ads_pixels';

    protected $fillable = [
        'workspace_id', 'remote_id', 'pixel_id', 'name', 'client_type', 'meta_json',
    ];

    protected $casts = ['meta_json' => 'array'];

    public function scopeForWorkspace(Builder $q, ?int $wsId): Builder
    {
        return $wsId ? $q->where('workspace_id', $wsId) : $q->whereRaw('1=0');
    }
}
