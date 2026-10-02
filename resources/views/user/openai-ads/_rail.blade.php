@php
    $acc = $account ?? [];
    $isConnected = ! empty($acc['id']);
    $navItems = [
        'campaigns'   => [__('Campaigns'), route('user.openai-ads.index')],
        'create'      => [__('New campaign'), route('user.openai-ads.create')],
        'conversions' => [__('Conversions'), route('user.openai-ads.conversions')],
        'analytics'   => [__('Analytics'), route('user.openai-ads.analytics')],
    ];
    $current = $current ?? 'campaigns';
@endphp
<aside data-keep-rail class="space-y-3 lg:sticky lg:top-6 self-start">
    {{-- Identity card --}}
    <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
        <div class="flex items-start justify-between gap-2">
            <span class="w-11 h-11 rounded-xl bg-wa-mint text-wa-deep grid place-items-center">
                <svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="8" cy="8" r="5.5" /><path d="M8 2.5v11M2.5 8h11" />
                </svg>
            </span>
            @if ($isConnected)
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-mono bg-wa-mint text-wa-deep border border-wa-green/40"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
            @else
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-mono bg-paper-50 text-ink-500 border border-paper-200"><span class="w-1.5 h-1.5 rounded-full bg-paper-200"></span>{{ __('Setup') }}</span>
            @endif
        </div>
        <div class="font-serif text-[18px] leading-tight mt-3">{{ $acc['name'] ?? __('OpenAI Ads') }}</div>
        <div class="font-mono text-[10.5px] text-ink-500 mt-0.5 truncate">
            {{ $acc['id'] ?? __('Not connected') }}@if(!empty($acc['currency_code'])) · {{ $acc['currency_code'] }}@endif
        </div>
    </div>

    {{-- Nav --}}
    <nav class="border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card space-y-0.5">
        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">{{ __('Manage') }}</div>
        @foreach ($navItems as $key => [$label, $href])
            @php $active = $current === $key; @endphp
            <a href="{{ $href }}" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-[12.5px] {{ $active ? 'bg-wa-deep/8 text-wa-deep font-semibold' : 'hover:bg-paper-50 text-ink-700' }}">
                {{ $label }}
            </a>
        @endforeach
        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-3 pb-1.5">{{ __('Account') }}</div>
        <a href="{{ route('user.openai-ads.connect') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-[12.5px] {{ ($current ?? '') === 'connect' ? 'bg-wa-deep/8 text-wa-deep font-semibold' : 'hover:bg-paper-50 text-ink-700' }}">
            {{ __('Account & key') }}
        </a>
    </nav>

    {{-- Good to know --}}
    <div class="border border-wa-green/30 bg-wa-bubble/50 rounded-2xl p-4 text-[12px] text-ink-600 leading-relaxed">
        <div class="font-semibold text-ink-800 mb-1">{{ __('Good to know') }}</div>
        {{ __('You use your own OpenAI Ads API key, so campaigns run on and bill to your own ad account. Budgets are total spend caps.') }}
    </div>
</aside>
