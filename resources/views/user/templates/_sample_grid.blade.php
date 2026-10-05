@php
    $samples = $samples ?? [];
@endphp
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
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
