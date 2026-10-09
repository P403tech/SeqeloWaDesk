<x-layouts.admin :title="__('Template library')" admin-key="template-samples" page="admin-template-samples-index">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Template library') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-7 py-7 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin · Messaging · Samples') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[40px] leading-[1.0]">
                    {{ __('WhatsApp') }} <span class="italic text-wa-deep">{{ __('samples') }}</span>.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Edit, save, then Push to customers. That puts a real template on every customer’s Templates → Your templates list. Unofficial can send it. Cloud API customers still submit it to Meta on their own number. These are not Meta-approved for you.') }}
                </p>
            </div>
            <a href="{{ route('admin.template-samples.create') }}"
                class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal flex items-center gap-2 shrink-0">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10" /></svg>
                {{ __('New sample') }}
            </a>
        </div>

        <x-admin.flash />

        <section class="grid grid-cols-2 sm:grid-cols-2 gap-3">
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="text-[11px] text-ink-600 font-medium">{{ __('Samples') }}</div>
                <div class="font-serif text-[34px] leading-none mt-1">{{ number_format($stats['total'] ?? 0) }}</div>
                <div class="text-[11px] text-ink-500 mt-2">{{ __('total') }}</div>
            </div>
            <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-4 shadow-card">
                <div class="text-[11px] text-ink-600 font-medium">{{ __('Visible to tenants') }}</div>
                <div class="font-serif text-[34px] leading-none mt-1">{{ number_format($stats['active'] ?? 0) }}</div>
                <div class="text-[11px] text-wa-deep mt-2">{{ __('on /templates') }}</div>
            </div>
        </section>

        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
            <div class="overflow-x-auto">
                <div class="min-w-[780px]">
                    <div class="px-4 py-2.5 grid grid-cols-[1.6fr_120px_110px_70px_160px] items-center gap-3 border-b border-paper-200 bg-paper-50 font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">
                        <div>{{ __('Sample') }}</div>
                        <div>{{ __('Category') }}</div>
                        <div>{{ __('Meta type') }}</div>
                        <div>{{ __('Order') }}</div>
                        <div class="text-right pr-2">{{ __('Actions') }}</div>
                    </div>

                    @forelse ($samples as $s)
                        <div class="px-4 py-3 grid grid-cols-[1.6fr_120px_110px_70px_160px] items-center gap-3 border-b border-paper-200 hover:bg-paper-50 transition">
                            <div class="min-w-0 flex items-center gap-3">
                                <span class="w-9 h-9 rounded-lg shrink-0 grid place-items-center text-[16px] text-white"
                                    style="background: linear-gradient(135deg, {{ $s->color_from }}, {{ $s->color_to }})">{{ $s->emoji }}</span>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-[13px] text-ink-900 truncate">{{ $s->title }}</span>
                                        @unless ($s->is_active)
                                            <span class="text-[9.5px] font-mono uppercase tracking-wider px-1.5 py-0.5 rounded bg-paper-100 text-ink-500">{{ __('hidden') }}</span>
                                        @endunless
                                    </div>
                                    <div class="font-mono text-[11px] text-ink-500 truncate">{{ $s->slug }}</div>
                                    @if ($s->last_pushed_at)
                                        <div class="text-[10px] text-ink-500">{{ __('Pushed') }} {{ $s->last_pushed_at->diffForHumans() }}</div>
                                    @endif
                                </div>
                            </div>
                            <div class="text-[12px] text-ink-700">{{ \App\Support\WaTemplateSampleLibrary::CATEGORIES[$s->category] ?? $s->category }}</div>
                            <div class="text-[12px] text-ink-600">{{ \App\Models\WaTemplateSample::META_CATEGORIES[$s->meta_category] ?? $s->meta_category }}</div>
                            <div class="font-mono text-[12px] text-ink-700">{{ $s->sort_order }}</div>
                            <div class="flex items-center gap-1 justify-end">
                                <form method="POST" action="{{ route('admin.template-samples.toggle', $s->id) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                        class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 {{ $s->is_active ? 'text-wa-deep' : 'text-ink-400' }} transition"
                                        title="{{ $s->is_active ? __('Hide from tenants') : __('Show to tenants') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                            @if ($s->is_active)
                                                <path d="M1.5 8s2.5-4.5 6.5-4.5S14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z" /><circle cx="8" cy="8" r="1.8" />
                                            @else
                                                <path d="M2 2l12 12M6.5 6.6a2 2 0 0 0 2.8 2.8M4 4.6C2.4 5.7 1.5 8 1.5 8s2.5 4.5 6.5 4.5c1 0 1.9-.2 2.7-.6M9.5 4.1A6.6 6.6 0 0 1 14.5 8s-.5.9-1.5 1.9" />
                                            @endif
                                        </svg>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.template-samples.push', $s->id) }}" class="inline"
                                    data-confirm-form
                                    data-confirm-title="{{ __('Push to customers?') }}"
                                    data-confirm-message="{{ __('Puts this copy in every active customer workspace. Unofficial can send it now. Cloud API customers still submit it to Meta. Copies already on Meta are not overwritten.') }}"
                                    data-confirm-accept="{{ __('Push') }}"
                                    data-confirm-cancel="{{ __('Cancel') }}"
                                    data-confirm-tone="default">
                                    @csrf
                                    <button type="submit"
                                        class="w-8 h-8 rounded-lg grid place-items-center hover:bg-wa-mint text-wa-deep transition"
                                        title="{{ __('Push to customers') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 11V3M4.5 6.5 8 3l3.5 3.5M3 13h10" /></svg>
                                    </button>
                                </form>
                                <a href="{{ route('admin.template-samples.edit', $s->id) }}"
                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-600 transition" title="{{ __('Edit') }}">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M11 3l2 2-7 7H4v-2z" /></svg>
                                </a>
                                <form method="POST" action="{{ route('admin.template-samples.destroy', $s->id) }}" class="inline"
                                    data-confirm="{{ __('Delete this sample? Tenants who already copied it keep their template.') }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition" title="{{ __('Delete') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5" /></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-12 text-center">
                            <div class="font-serif text-[18px] mb-1">{{ __('No samples yet') }}</div>
                            <p class="text-[12.5px] text-ink-500 max-w-[460px] mx-auto">
                                {{ __('Add festival greetings, order updates, or class reminders. Active samples show on every tenant’s Templates page.') }}
                            </p>
                            <a href="{{ route('admin.template-samples.create') }}" class="inline-block mt-3 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Add your first sample') }}</a>
                        </div>
                    @endforelse
                </div>
            </div>
            @if ($samples->hasPages())
                <div class="px-4 py-3 border-t border-paper-200">{{ $samples->links() }}</div>
            @endif
        </div>
    </main>
</x-layouts.admin>
