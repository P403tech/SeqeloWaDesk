@php
    $chKey = $chKey ?? 'whatsapp';
    $showComments = in_array($chKey, ['facebook', 'instagram', 'tiktok'], true);
    $showStories = $chKey === 'instagram';
    $showOrders = $chKey === 'whatsapp';
@endphp
<div class="px-4 pb-3 hidden" data-control-wrap="{{ $chKey }}">
    <div class="rounded-xl border border-paper-200 bg-paper-50/70 p-3 space-y-2">
        <div class="text-[11px] font-semibold text-ink-800">{{ __('How much should this agent handle?') }}</div>
        <label class="flex items-start gap-2 text-[12.5px] cursor-pointer">
            <input type="radio" name="ctrl-{{ $chKey }}" data-control="{{ $chKey }}.mode" value="full" class="mt-0.5">
            <span>
                <span class="font-semibold">{{ __('Full control') }}</span>
                <span class="block text-[11.5px] text-ink-500">{{ __('Answers every inbound on this connection. Humans only when the customer asks, or on handoff.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-[12.5px] cursor-pointer">
            <input type="radio" name="ctrl-{{ $chKey }}" data-control="{{ $chKey }}.mode" value="specific" class="mt-0.5">
            <span>
                <span class="font-semibold">{{ __('Specific only') }}</span>
                <span class="block text-[11.5px] text-ink-500">{{ __('Leave the rest for your team. Check what this agent may touch.') }}</span>
            </span>
        </label>
        <div class="pl-6 space-y-1.5 hidden" data-control-specific="{{ $chKey }}">
            <label class="flex items-center gap-2 text-[12.5px]"><input type="checkbox" data-control="{{ $chKey }}.dms" class="rounded border-paper-200"> {{ __('Direct messages') }}</label>
            @if ($showComments)
                <label class="flex items-center gap-2 text-[12.5px]"><input type="checkbox" data-control="{{ $chKey }}.comments" class="rounded border-paper-200"> {{ __('Comments & public replies') }}</label>
            @endif
            @if ($showStories)
                <label class="flex items-center gap-2 text-[12.5px]"><input type="checkbox" data-control="{{ $chKey }}.stories" class="rounded border-paper-200"> {{ __('Story replies') }}</label>
            @endif
            @if ($showOrders)
                <label class="flex items-center gap-2 text-[12.5px]"><input type="checkbox" data-control="{{ $chKey }}.orders" class="rounded border-paper-200"> {{ __('Catalog & order questions only') }}</label>
            @endif
            <label class="flex items-center gap-2 text-[12.5px]"><input type="checkbox" data-control="{{ $chKey }}.keyword" class="rounded border-paper-200"> {{ __('Only after a keyword') }}</label>
            <input type="text" data-control="{{ $chKey }}.keyword_text" placeholder="{{ __('e.g. seqelo or price') }}"
                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[12px]">
        </div>
    </div>
</div>
