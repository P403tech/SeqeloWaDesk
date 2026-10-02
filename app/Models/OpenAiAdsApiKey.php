<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Record of a Conversions API key created via OpenAI Ads. The secret itself is
 * NEVER stored — it is shown once on creation; only a masked hint is kept so the
 * operator can tell which keys exist.
 */
class OpenAiAdsApiKey extends Model
{
    protected $table = 'openai_ads_api_keys';

    protected $fillable = ['workspace_id', 'name', 'masked'];

    public function scopeForWorkspace(Builder $q, ?int $wsId): Builder
    {
        return $wsId ? $q->where('workspace_id', $wsId) : $q->whereRaw('1=0');
    }
}
