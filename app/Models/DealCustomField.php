<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace-defined custom field for deals. Same shape as
 * ContactCustomField on purpose — the definition manager, the type
 * coercion and the validation are shared rather than duplicated.
 *
 * Definitions live here; VALUES live in deals.meta->custom keyed by `key`.
 */
class DealCustomField extends Model
{
    protected $fillable = [
        'workspace_id', 'key', 'label',
        'type', 'options', 'required', 'show_in_panel', 'sort',
    ];

    protected $casts = [
        'options'       => 'array',
        'required'      => 'boolean',
        'show_in_panel' => 'boolean',
        'sort'          => 'integer',
    ];

    public const TYPES = ['text', 'number', 'date', 'select', 'bool', 'url', 'email'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
     * Coerce a submitted string to this field's declared type.
     * Returns the value to store, or FALSE when it does not fit — the caller
     * then leaves the existing value alone rather than wiping good data with
     * a bad answer.
     *
     * Mirrors FlowNodeActionsController::coerceCustomFieldValue; kept here so
     * the deal side has one obvious home for it.
     */
    public function coerce(string $val)
    {
        if ($val === '') return '';   // clearing a field is legitimate

        switch ((string) $this->type) {
            case 'number':
                return is_numeric($val) ? $val + 0 : false;

            case 'bool':
                $t = strtolower($val);
                if (in_array($t, ['1', 'true', 'yes', 'y', 'on'], true))  return true;
                if (in_array($t, ['0', 'false', 'no', 'n', 'off'], true)) return false;
                return false;   // unparseable → skip, never guess

            case 'date':
                try { return \Illuminate\Support\Carbon::parse($val)->toDateString(); }
                catch (\Throwable $e) { return false; }

            case 'email':
                return filter_var($val, FILTER_VALIDATE_EMAIL) ? $val : false;

            case 'url':
                return filter_var($val, FILTER_VALIDATE_URL) ? $val : false;

            case 'select':
                // Must match a declared option. Compared case-insensitively but
                // stored in the option's own casing so reports group cleanly.
                foreach ((array) ($this->options ?? []) as $o) {
                    if (mb_strtolower(trim((string) $o)) === mb_strtolower($val)) return (string) $o;
                }
                return false;

            default:
                return $val;   // text and anything unrecognised
        }
    }
}
