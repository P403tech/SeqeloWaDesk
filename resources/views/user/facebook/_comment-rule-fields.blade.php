@php
    /** @var \App\Models\FacebookCommentRule|null $rule */
    // On edit, prefill from the rule; on create, from old() after a failed submit.
    $v = fn ($k, $d = '') => $rule ? ($rule->{$k} ?? $d) : old($k, $d);
    $mode   = $v('keyword_mode', 'contains');
    $pageId = (int) $v('fb_page_id', 0);
    $flowId = (int) $v('dm_flow_id', 0);
    $active = $rule ? (bool) $rule->is_active : (bool) old('is_active', true);
    $lbl = 'block text-[11px] font-mono uppercase tracking-wide text-ink-500 mb-1';
    $inp = 'w-full border border-paper-200 rounded-lg px-3 py-2 text-[13px] bg-paper-0 focus:outline-none focus:border-wa-deep';
@endphp

<div>
    <label class="{{ $lbl }}">{{ __('Facebook page') }}</label>
    <select name="fb_page_id" class="{{ $inp }}" required>
        @foreach ($pages as $p)
            <option value="{{ $p->id }}" @selected($pageId === (int) $p->id)>{{ $p->name ?: ('#' . $p->id) }}</option>
        @endforeach
    </select>
    @error('fb_page_id')<p class="text-[11px] text-accent-coral mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label class="{{ $lbl }}">{{ __('Rule name (optional)') }}</label>
    <input type="text" name="name" value="{{ $v('name') }}" maxlength="191" class="{{ $inp }}" placeholder="{{ __('e.g. Pricing questions') }}">
</div>

<div>
    <label class="{{ $lbl }}">{{ __('Keyword(s)') }}</label>
    <input type="text" name="keyword" value="{{ $v('keyword') }}" maxlength="191" class="{{ $inp }}" placeholder="{{ __('price, cost, how much') }}">
    <p class="text-[10.5px] text-ink-400 mt-1">{{ __('Comma-separated. Matches if the comment contains any of them. Leave blank only for the "any comment" mode.') }}</p>
    @error('keyword')<p class="text-[11px] text-accent-coral mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label class="{{ $lbl }}">{{ __('Match mode') }}</label>
    <select name="keyword_mode" class="{{ $inp }}">
        <option value="contains" @selected($mode === 'contains')>{{ __('Contains the keyword') }}</option>
        <option value="exact"    @selected($mode === 'exact')>{{ __('Exactly equals the keyword') }}</option>
        <option value="any"      @selected($mode === 'any')>{{ __('Any comment (no keyword)') }}</option>
    </select>
</div>

<div>
    <label class="{{ $lbl }}">{{ __('Public reply (optional)') }}</label>
    <textarea name="public_reply" rows="2" maxlength="8000" class="{{ $inp }}" placeholder="{{ __('Thanks! We just sent you a DM.') }}">{{ $v('public_reply') }}</textarea>
    <p class="text-[10.5px] text-ink-400 mt-1">{{ __('Posted publicly under the comment.') }}</p>
    @error('public_reply')<p class="text-[11px] text-accent-coral mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label class="{{ $lbl }}">{{ __('Direct message (optional)') }}</label>
    <textarea name="dm_text" rows="2" maxlength="2000" class="{{ $inp }}" placeholder="{{ __('Here are the details you asked for…') }}">{{ $v('dm_text') }}</textarea>
    <p class="text-[10.5px] text-ink-400 mt-1">{{ __('Sent privately to the commenter (Messenger).') }}</p>
</div>

<div>
    <label class="{{ $lbl }}">{{ __('Start a flow (optional)') }}</label>
    <select name="dm_flow_id" class="{{ $inp }}">
        <option value="">{{ __('— none —') }}</option>
        @foreach ($flows as $f)
            <option value="{{ $f->id }}" @selected($flowId === (int) $f->id)>{{ $f->flow_name ?: ('#' . $f->id) }}</option>
        @endforeach
    </select>
</div>

<div class="sm:col-span-2">
    <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-700">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked($active) class="rounded border-paper-300 text-wa-deep focus:ring-wa-deep">
        {{ __('Active') }}
    </label>
</div>
