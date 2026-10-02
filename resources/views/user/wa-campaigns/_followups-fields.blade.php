{{-- Campaign Follow-ups rule-builder fields. Shared markup driven by the
     campaign-followups.js module (reads #fu-row-tpl / #fu-options, writes the
     hidden #followups_json). Requires $flows, $templates, $drips, $tags and an
     optional $campaign in scope. --}}
<input type="hidden" name="followups_json" id="followups_json" value="">

{{-- Alternative entry point: a full multi-step follow-up can be built as a Flow
     with the "Campaign engagement" trigger in the flow builder. --}}
<p class="text-[11.5px] text-ink-500 mb-3">
    {{ __('Need a multi-step sequence? Build a') }}
    <a href="{{ url('/flows/builder') }}" class="text-wa-deep font-semibold hover:underline" target="_blank" rel="noopener">{{ __('flow with a “Campaign engagement” trigger') }}</a>
    {{ __('— it starts for recipients by status (read, not read, replied…), including campaigns that already ran.') }}
</p>

<template id="fu-row-tpl">
    <div class="fu-row border border-paper-200 rounded-xl p-3 mb-3 bg-paper-50/40" data-fu-row>
        <div class="flex flex-wrap items-end gap-2.5">
            <label class="flex flex-col gap-1">
                <span class="text-[10px] uppercase tracking-wide text-ink-500 font-mono">{{ __('When') }}</span>
                <select data-fu-event class="text-[12.5px] border border-paper-200 rounded-lg px-2 py-1.5 bg-paper-0">
                    <option value="replied">{{ __('Recipient replies') }}</option>
                    <option value="clicked_button">{{ __('Taps a quick-reply button') }}</option>
                    <option value="clicked_link">{{ __('Clicks a link') }}</option>
                    <option value="read">{{ __('Reads the message') }}</option>
                    <option value="read_no_reply">{{ __('Reads but no reply in…') }}</option>
                    <option value="delivered_no_read">{{ __('Delivered but not read in…') }}</option>
                    <option value="sent_no_reply">{{ __('No reply in…') }}</option>
                    <option value="not_delivered">{{ __('Not delivered in…') }}</option>
                    <option value="failed">{{ __('Send failed') }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1" data-fu-delay-wrap hidden>
                <span class="text-[10px] uppercase tracking-wide text-ink-500 font-mono">{{ __('After') }}</span>
                <span class="inline-flex items-center gap-1">
                    <input type="number" min="0" value="6" data-fu-delay-value class="w-16 text-[12.5px] border border-paper-200 rounded-lg px-2 py-1.5 bg-paper-0">
                    <select data-fu-delay-unit class="text-[12.5px] border border-paper-200 rounded-lg px-2 py-1.5 bg-paper-0">
                        <option value="minute">{{ __('minutes') }}</option>
                        <option value="hour" selected>{{ __('hours') }}</option>
                        <option value="day">{{ __('days') }}</option>
                    </select>
                </span>
            </label>
            <span class="text-[13px] text-ink-400 pb-2">→</span>
            <label class="flex flex-col gap-1">
                <span class="text-[10px] uppercase tracking-wide text-ink-500 font-mono">{{ __('Then') }}</span>
                <select data-fu-action class="text-[12.5px] border border-paper-200 rounded-lg px-2 py-1.5 bg-paper-0">
                    <option value="send_template">{{ __('Send a template') }}</option>
                    <option value="start_flow">{{ __('Start a flow') }}</option>
                    <option value="enroll_drip">{{ __('Enroll in a drip') }}</option>
                    <option value="add_tag">{{ __('Add a tag') }}</option>
                    <option value="remove_tag">{{ __('Remove a tag') }}</option>
                    <option value="assign_agent">{{ __('Assign to an agent') }}</option>
                    <option value="opt_out">{{ __('Opt the contact out') }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 flex-1 min-w-[160px]" data-fu-ref-wrap>
                <span class="text-[10px] uppercase tracking-wide text-ink-500 font-mono" data-fu-ref-label>{{ __('Template') }}</span>
                <select data-fu-ref class="text-[12.5px] border border-paper-200 rounded-lg px-2 py-1.5 bg-paper-0"></select>
            </label>
            <button type="button" data-fu-remove class="text-accent-coral/80 hover:text-accent-coral text-[11px] font-semibold pb-2">{{ __('Remove') }}</button>
        </div>
        <p class="text-[10.5px] text-ink-400 mt-2 hidden" data-fu-window-note>
            {{ __('No reply means the 24-hour window is closed — only a template (or a flow/drip that starts with a template) can send.') }}
        </p>
    </div>
</template>

@php
    $fuOptions = \App\Models\CampaignFollowup::buildPickerOptions(
        $flows ?? collect(), $templates ?? collect(), $drips ?? collect(), $tags ?? collect(),
        (isset($campaign) && $campaign) ? $campaign : null, $agents ?? collect(),
    );
@endphp
<script type="application/json" id="fu-options">@json($fuOptions)</script>

<div id="fu-rows"></div>
<button type="button" id="fu-add"
    class="mt-1 px-3 py-2 rounded-full border border-wa-deep/30 bg-wa-deep/5 text-wa-deep text-[12px] font-semibold hover:bg-wa-deep/10 inline-flex items-center gap-1.5">
    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10"/></svg>
    {{ __('Add a follow-up rule') }}
</button>
