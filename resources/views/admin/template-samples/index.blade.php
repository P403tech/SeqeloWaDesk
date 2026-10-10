@php
    $category = $category ?? 'all';
    $search = $search ?? '';
    $categoryCounts = $categoryCounts ?? ['all' => 0];
    $catTabs = array_merge(['all' => 'All'], \App\Support\WaTemplateSampleLibrary::CATEGORIES);
@endphp

<x-layouts.admin :title="__('Template library')" admin-key="template-samples" page="admin-template-samples-index">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Template library') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-7 py-7">
        <div class="mb-4 flex items-end justify-between gap-4 flex-wrap">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin · Templates') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[30px] sm:text-[36px] lg:text-[44px] leading-[1.0]">
                    {{ __('Template') }} <span class="italic text-wa-deep">{{ __('library') }}</span>.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Same cards customers see. Click a card to edit image and text, then push it into their Templates list. Each Cloud API number still needs its own Meta approval.') }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.template-samples.push-status') }}"
                    class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-semibold">{{ __('Push status') }}</a>
                <a href="{{ route('admin.template-samples.create') }}"
                    class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold flex items-center gap-2 whitespace-nowrap">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10" /></svg>
                    {{ __('New Template Message') }}
                </a>
            </div>
        </div>

        <x-admin.flash />

        <div class="mt-3 flex items-center gap-2 flex-wrap">
            @foreach ($catTabs as $key => $label)
                @php $active = $category === $key; @endphp
                <a href="{{ route('admin.template-samples.index', array_filter(['category' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full border text-[12.5px] font-medium transition {{ $active ? 'border-wa-deep text-wa-deep bg-wa-mint font-semibold' : 'border-paper-200 text-ink-600 bg-paper-0 hover:border-ink-300' }}">
                    {{ __($label) }}
                    <span class="font-mono text-[10px] opacity-80">{{ number_format($categoryCounts[$key] ?? 0) }}</span>
                </a>
            @endforeach
            <div class="flex-1"></div>
            <form method="GET" action="{{ route('admin.template-samples.index') }}" class="relative">
                @if ($category !== 'all')
                    <input type="hidden" name="category" value="{{ $category }}">
                @endif
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="7" cy="7" r="5" /><path d="m11 11 3 3" />
                </svg>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search samples…') }}"
                    class="hairline border border-paper-200 rounded-full pl-9 pr-3 py-1.5 text-[12px] bg-paper-0 w-56 focus:outline-none focus:border-wa-deep">
            </form>
        </div>

        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            @forelse ($samples as $s)
                @php
                    $from = $s->color_from ?: '#1B4B3D';
                    $to = $s->color_to ?: '#037D66';
                    $preview = preg_replace('/\s+/', ' ', (string) $s->body) ?? '';
                    if (mb_strlen($preview) > 140) {
                        $preview = mb_substr($preview, 0, 137).'…';
                    }
                @endphp
                <article class="bg-paper-0 border border-paper-200 rounded-[16px] overflow-hidden shadow-card flex flex-col hover:border-wa-deep hover:shadow-soft transition">
                    <a href="{{ route('admin.template-samples.edit', $s->id) }}" class="h-[118px] relative text-white px-4 py-3 flex flex-col justify-between bg-cover bg-center"
                        style="@if ($s->image_path) background-image: linear-gradient(180deg, rgba(0,0,0,.15), rgba(0,0,0,.45)), url('{{ $s->imageUrl() }}'); @else background: linear-gradient(135deg, {{ $from }}, {{ $to }}); @endif">
                        <span class="text-[22px] leading-none">{{ $s->emoji }}</span>
                        <div class="font-semibold text-[13.5px] leading-tight drop-shadow-sm">{{ $s->header }}</div>
                    </a>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div class="flex items-center justify-between gap-2 mb-1.5">
                            <span class="font-mono text-[11px] text-ink-700 truncate">{{ $s->slug }}</span>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-wa-mint text-wa-deep">{{ \App\Support\WaTemplateSampleLibrary::CATEGORIES[$s->category] ?? $s->category }}</span>
                        </div>
                        <p class="text-[12px] text-ink-600 leading-snug flex-1">{{ $preview }}</p>
                        @unless ($s->is_active)
                            <div class="mt-2 text-[10px] font-mono uppercase tracking-wider text-ink-500">{{ __('hidden from customers') }}</div>
                        @endunless
                        @if ($s->last_pushed_at)
                            <div class="mt-1 text-[10px] text-ink-500">{{ __('Pushed') }} {{ $s->last_pushed_at->diffForHumans() }}</div>
                        @endif
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <a href="{{ route('admin.template-samples.edit', $s->id) }}"
                                class="text-center px-3 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold">{{ __('Edit') }}</a>
                            <a href="{{ route('admin.template-samples.push.form', $s->id) }}"
                                class="text-center px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[11.5px] font-semibold">{{ __('Push') }}</a>
                        </div>
                        <div class="mt-2 flex items-center justify-between">
                            <form method="POST" action="{{ route('admin.template-samples.toggle', $s->id) }}">
                                @csrf
                                <button type="submit" class="text-[11px] font-medium text-wa-deep hover:underline">{{ $s->is_active ? __('Hide') : __('Show') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.template-samples.destroy', $s->id) }}"
                                data-confirm="{{ __('Delete this sample? Tenants who already copied it keep their template.') }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[11px] font-medium text-accent-coral hover:underline">{{ __('Delete') }}</button>
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                <div class="sm:col-span-2 xl:col-span-4 rounded-2xl border border-dashed border-paper-200 px-6 py-12 text-center">
                    <div class="font-serif text-[20px] text-ink-900">{{ __('No samples yet') }}</div>
                    <p class="text-[12.5px] text-ink-500 mt-1">{{ __('Add a template the same way a customer would — image, text, buttons — then push it.') }}</p>
                    <a href="{{ route('admin.template-samples.create') }}" class="inline-block mt-3 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('New Template Message') }}</a>
                </div>
            @endforelse
        </div>

        @if ($samples->hasPages())
            <div class="mt-5">{{ $samples->links() }}</div>
        @endif
    </main>
</x-layouts.admin>
