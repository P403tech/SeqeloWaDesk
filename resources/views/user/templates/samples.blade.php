@php
    $samples = $samples ?? [];
    $category = $category ?? 'all';
    $search = $search ?? '';
    $categoryCounts = $categoryCounts ?? ['all' => 0];
    $catTabs = array_merge(['all' => 'All'], \App\Support\WaTemplateSampleLibrary::CATEGORIES);
@endphp

<x-layouts.user :title="__('Template Library')" nav-key="templates" page="user-templates-samples">

    <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

            <aside class="lg:sticky lg:top-6 self-start space-y-3">
                <x-side-tip>
                    {{ __('These are starting copy, not Meta-approved templates. Use a sample, tweak your brand details, then submit for review.') }}
                </x-side-tip>

                @include('user.templates._campaigns_rail', ['railKey' => 'samples'])

                <div
                    class="hairline border border-paper-200 rounded-2xl bg-wa-bubble/40 p-3 text-[11px] text-ink-700 leading-relaxed">
                    <div class="font-semibold text-ink-900 mb-1 flex items-center gap-1.5">
                        <svg viewBox="0 0 16 16" class="w-3 h-3 text-wa-deep" fill="currentColor">
                            <circle cx="8" cy="8" r="6" />
                        </svg>
                        {{ __('Quick start') }}
                    </div>
                    {{ __('Pick a card, click Use sample, then submit to Meta. Marketing samples need an opt-out footer — we prefill Reply STOP to unsubscribe.') }}
                </div>
            </aside>

            <main>
                <div class="mb-4 flex items-end justify-between gap-4 flex-wrap">
                    <div class="min-w-0">
                        <div class="mono font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                            {{ __('Campaigns / Templates') }}</div>
                        <h1
                            class="serif font-serif font-normal tracking-[-0.01em] text-[30px] sm:text-[36px] lg:text-[44px] leading-[1.0] tracking-tight">
                            {{ __('Template') }} <span class="italic text-wa-deep">{{ __('library') }}</span>.</h1>
                        <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                            {{ __('Select or create your template and submit it for WhatsApp approval.') }}
                            <a href="https://developers.facebook.com/docs/whatsapp/message-templates/guidelines/"
                                target="_blank" rel="noopener"
                                class="text-wa-deep font-medium underline decoration-wa-deep/40">{{ __('Guidelines') }}</a>
                        </p>
                    </div>
                    <a href="{{ route('user.templates.create') }}"
                        class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold flex items-center gap-2 whitespace-nowrap">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 3v10M3 8h10" />
                        </svg>
                        {{ __('New Template Message') }}
                    </a>
                </div>

                <div class="mt-2 flex items-center gap-2 flex-wrap">
                    @foreach ($catTabs as $key => $label)
                        @php $active = $category === $key; @endphp
                        <a href="{{ route('user.templates.samples', array_filter(['category' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full border text-[12.5px] font-medium transition {{ $active ? 'border-wa-deep text-wa-deep bg-wa-mint font-semibold' : 'border-paper-200 text-ink-600 bg-paper-0 hover:border-ink-300' }}">
                            {{ __($label) }}
                            <span class="font-mono text-[10px] opacity-80">{{ number_format($categoryCounts[$key] ?? 0) }}</span>
                        </a>
                    @endforeach
                    <div class="flex-1"></div>
                    <form method="GET" action="{{ route('user.templates.samples') }}" class="relative">
                        @if ($category !== 'all')
                            <input type="hidden" name="category" value="{{ $category }}">
                        @endif
                        <svg viewBox="0 0 16 16"
                            class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <circle cx="7" cy="7" r="5" />
                            <path d="m11 11 3 3" />
                        </svg>
                        <input type="search" name="q" value="{{ $search }}"
                            placeholder="{{ __('Search samples…') }}"
                            class="hairline border border-paper-200 rounded-full pl-9 pr-3 py-1.5 text-[12px] bg-paper-0 w-56 focus:outline-none focus:border-wa-deep">
                    </form>
                </div>

                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    @forelse ($samples as $s)
                        <article
                            class="bg-paper-0 border border-paper-200 rounded-[16px] overflow-hidden shadow-card flex flex-col hover:border-wa-deep hover:shadow-soft transition">
                            <div class="h-[118px] bg-gradient-to-br {{ $s['gradient'] }} relative text-white px-4 py-3 flex flex-col justify-between">
                                <span class="text-[22px] leading-none">{{ $s['emoji'] }}</span>
                                <div class="font-semibold text-[13.5px] leading-tight drop-shadow-sm">{{ $s['header'] }}</div>
                            </div>
                            <div class="p-3.5 flex flex-col flex-1">
                                <div class="flex items-center justify-between gap-2 mb-1.5">
                                    <span class="font-mono text-[11px] text-ink-700 truncate">{{ $s['slug'] }}</span>
                                    <span
                                        class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-wa-mint text-wa-deep">{{ $s['category_label'] }}</span>
                                </div>
                                <p class="text-[12px] text-ink-600 leading-snug flex-1">{{ $s['preview'] }}</p>
                                <a href="{{ route('user.templates.create', ['sample' => $s['slug']]) }}"
                                    class="mt-3 w-full text-center px-3 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold">
                                    {{ __('Use sample') }}
                                </a>
                            </div>
                        </article>
                    @empty
                        <div class="sm:col-span-2 xl:col-span-4 rounded-2xl border border-dashed border-paper-200 px-6 py-12 text-center">
                            <div class="font-serif text-[20px] text-ink-900">{{ __('No samples match') }}</div>
                            <p class="text-[12.5px] text-ink-500 mt-1">{{ __('Try another category or clear the search.') }}</p>
                            <a href="{{ route('user.templates.samples') }}"
                                class="inline-block mt-3 text-[12.5px] font-semibold text-wa-deep hover:underline">{{ __('Show all samples') }}</a>
                        </div>
                    @endforelse
                </div>
            </main>
        </div>
    </div>

    <script>
        (function () {
            const toggle = document.getElementById('tpl-msg-toggle');
            const sub = document.getElementById('tpl-msg-sub');
            const chev = document.getElementById('tpl-msg-chev');
            if (!toggle || !sub) return;
            toggle.addEventListener('click', function () {
                const open = toggle.getAttribute('aria-expanded') === 'true';
                const next = !open;
                toggle.setAttribute('aria-expanded', String(next));
                toggle.classList.toggle('bg-wa-deep', next);
                toggle.classList.toggle('text-paper-0', next);
                toggle.classList.toggle('text-ink-700', !next);
                sub.classList.toggle('max-h-0', !next);
                sub.classList.toggle('opacity-0', !next);
                sub.classList.toggle('max-h-[200px]', next);
                sub.classList.toggle('opacity-100', next);
                if (chev) chev.classList.toggle('rotate-180', next);
            });
        })();
    </script>
</x-layouts.user>
