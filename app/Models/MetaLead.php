<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Instant-Form submission, with the ad that produced it.
 *
 * The row is written the moment the lead is fetched, BEFORE any mapping or
 * routing runs. That is deliberate: if the contact merge or deal creation
 * fails, the lead is still here with `ingest_status = failed` and can be
 * retried, instead of being lost to a Meta 90-day deletion window.
 */
class MetaLead extends Model
{
    protected $fillable = [
        'workspace_id', 'meta_lead_form_id', 'leadgen_id',
        'form_id', 'ad_id', 'ad_name', 'adset_id', 'adset_name',
        'campaign_id', 'campaign_name', 'platform', 'is_organic',
        'field_data', 'contact_id', 'deal_id',
        'ingest_status', 'ingest_error', 'submitted_at',
    ];

    protected $casts = [
        // The customer's own answers — name, phone, email, whatever the form
        // asked. PII, so encrypted at rest like every other answer store here.
        'field_data'   => 'encrypted:array',
        'is_organic'   => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';

    public function form(): BelongsTo
    {
        return $this->belongsTo(MetaLeadForm::class, 'meta_lead_form_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withDefault();
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withDefault();
    }

    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        $user = auth()->user();
        if (! $user) return $q->whereRaw('1=0');

        return $q->where('workspace_id', (int) ($user->current_workspace_id ?? 0));
    }

    /** Pull one answer out of Meta's field_data[] shape by question key. */
    public function answer(string $key): ?string
    {
        foreach ((array) ($this->field_data ?? []) as $f) {
            if ((string) ($f['name'] ?? '') !== $key) continue;
            $values = (array) ($f['values'] ?? []);

            return $values ? (string) $values[0] : null;
        }

        return null;
    }

    /** Every answer flattened to {question_key: first_value} for display. */
    public function answers(): array
    {
        $out = [];
        foreach ((array) ($this->field_data ?? []) as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name === '') continue;
            $values     = (array) ($f['values'] ?? []);
            $out[$name] = $values ? (string) $values[0] : '';
        }

        return $out;
    }
}
