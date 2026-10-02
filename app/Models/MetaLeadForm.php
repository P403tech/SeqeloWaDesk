<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Meta Instant Form, plus how this workspace wants its leads routed.
 *
 * A form row can exist WITHOUT being configured: the ingest service creates a
 * disabled placeholder the first time a lead arrives from a form nobody set up,
 * so the lead is captured rather than dropped while the operator catches up.
 */
class MetaLeadForm extends Model
{
    protected $fillable = [
        'workspace_id', 'facebook_page_id', 'form_id', 'name', 'status', 'locale',
        'questions', 'field_map', 'enabled', 'create_deal',
        'pipeline_id', 'stage_id', 'owner_user_id', 'owner_team_id',
        'assign_strategy', 'flow_id', 'tag_ids',
        'last_synced_at', 'sync_cursor',
    ];

    protected $casts = [
        'questions'      => 'array',
        'field_map'      => 'array',
        'tag_ids'        => 'array',
        'enabled'        => 'boolean',
        'create_deal'    => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /** Where a mapped answer can be written. */
    public const TARGETS = ['contact', 'contact_custom', 'deal_custom'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(MetaLead::class, 'meta_lead_form_id');
    }

    public function scopeForWorkspace(Builder $q, int $workspaceId): Builder
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        $user = auth()->user();
        if (! $user) return $q->whereRaw('1=0');

        return $q->where('workspace_id', (int) ($user->current_workspace_id ?? 0));
    }

    /**
     * Best-effort auto-mapping for a form we have just discovered, so the
     * operator opens the mapping screen with the obvious rows already filled in
     * rather than a blank grid. Meta's own question keys are predictable
     * (`full_name`, `email`, `phone_number`), and its `type` is a stronger
     * signal than the key when a form was authored in another language.
     */
    public static function guessFieldMap(array $questions): array
    {
        $map = [];
        foreach ($questions as $q) {
            $key  = (string) ($q['key'] ?? $q['name'] ?? '');
            $type = strtoupper((string) ($q['type'] ?? ''));
            if ($key === '') continue;

            $target = match (true) {
                $type === 'EMAIL'      || str_contains($key, 'email')       => 'email',
                $type === 'PHONE'      || str_contains($key, 'phone')       => 'mobile',
                $type === 'FULL_NAME'  || $key === 'full_name'              => 'name',
                $type === 'FIRST_NAME' || str_contains($key, 'first_name')  => 'first_name',
                $type === 'LAST_NAME'  || str_contains($key, 'last_name')   => 'last_name',
                $type === 'COMPANY_NAME'                                    => null,
                default                                                     => null,
            };

            if ($target) {
                $map[$key] = ['target' => 'contact', 'key' => $target];
            }
        }

        return $map;
    }
}
