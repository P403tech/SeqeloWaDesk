@php
    $railKey = $railKey ?? 'yours';
    $tplOpen = in_array($railKey, ['samples', 'yours'], true);
@endphp
<div class="hairline border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card" id="side-rail">
    <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">
        {{ __('Campaigns') }}</div>
    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] {{ $railKey === 'overview' ? 'bg-wa-deep text-paper-0 font-semibold' : 'text-ink-700 hover:bg-paper-50' }}"
        href="{{ url('/wa-campaigns') }}">
        <span class="flex items-center gap-2">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="8" cy="8" r="6" />
                <path d="M8 5v3l2 2" />
            </svg>
            {{ __('Campaign Overview') }}
        </span>
    </a>
    <button type="button" id="tpl-msg-toggle" aria-expanded="{{ $tplOpen ? 'true' : 'false' }}"
        class="rail-link w-full flex items-center justify-between px-3 py-2 rounded-xl text-[13px] font-medium {{ $tplOpen ? 'bg-wa-deep text-paper-0' : 'text-ink-700 hover:bg-paper-50' }}">
        <span class="flex items-center gap-2">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="2.5" y="2.5" width="11" height="11" rx="1.5" />
                <path d="M2.5 6h11M6 13.5V6" />
            </svg>
            {{ __('Template Messages') }}
        </span>
        <svg id="tpl-msg-chev" viewBox="0 0 12 12" class="w-3 h-3 transition-transform {{ $tplOpen ? 'rotate-180' : '' }}" fill="none"
            stroke="currentColor" stroke-width="1.6">
            <path d="M3 4l3 3 3-3" />
        </svg>
    </button>
    <div id="tpl-msg-sub"
        class="overflow-hidden transition-[max-height,opacity] duration-200 {{ $tplOpen ? 'max-h-[200px] opacity-100' : 'max-h-0 opacity-0' }}">
        <a class="rail-sub flex items-center justify-between pl-9 pr-3 py-2 rounded-xl text-[12.5px] {{ $railKey === 'samples' ? 'bg-paper-50 text-ink-900 font-medium' : 'text-ink-700 hover:bg-paper-50' }}"
            href="{{ route('user.templates.samples') }}">
            <span>{{ __('Template Library') }}</span>
        </a>
        <a class="rail-sub flex items-center justify-between pl-9 pr-3 py-2 rounded-xl text-[12.5px] {{ $railKey === 'yours' ? 'bg-paper-50 text-ink-900 font-medium' : 'text-ink-700 hover:bg-paper-50' }}"
            href="{{ route('user.templates.index', ['view' => 'yours']) }}">
            <span>{{ __('Your Templates') }}</span>
        </a>
    </div>
    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] {{ $railKey === 'scheduled' ? 'bg-wa-deep text-paper-0 font-semibold' : 'text-ink-700 hover:bg-paper-50' }}"
        href="{{ url('/scheduled') }}">
        <span class="flex items-center gap-2">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="2" y="3" width="12" height="11" rx="1.5" />
                <path d="M2 6h12M5 1v3M11 1v3" />
            </svg>
            {{ __('Scheduled Campaigns') }}
        </span>
    </a>
    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] text-ink-700 hover:bg-paper-50"
        href="{{ url('/analytics') }}">
        <span class="flex items-center gap-2">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M2 11l3-5 3 3 3-6 3 4" />
            </svg>
            {{ __('Performance') }}
        </span>
    </a>
    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] text-ink-700 hover:bg-paper-50"
        href="{{ url('/wa-campaigns') }}">
        <span class="flex items-center gap-2">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M3 5h10v8H3zM3 5l5 4 5-4" />
            </svg>
            {{ __('Drafts') }}
        </span>
    </a>
</div>
