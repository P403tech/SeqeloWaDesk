@php
    $sample = $sample ?? null;
    $editing = (bool) $sample;
    $action = $editing ? route('admin.template-samples.update', $sample->id) : route('admin.template-samples.store');
    $buttons = old('buttons', $sample?->buttons ?? []);
    if (! is_array($buttons)) {
        $buttons = [];
    }
    while (count($buttons) < 3) {
        $buttons[] = ['type' => 'quick_reply', 'text' => '', 'value' => ''];
    }
    $colorFrom = strtolower((string) old('color_from', $sample->color_from ?? '#1B4B3D'));
    $colorTo = strtolower((string) old('color_to', $sample->color_to ?? '#037D66'));
@endphp

<x-layouts.admin :title="$editing ? __('Edit sample') : __('New sample')" admin-key="template-samples" page="admin-template-samples-form">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ route('admin.template-samples.index') }}" class="hover:text-ink-900">{{ __('Template library') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ $editing ? __('Edit') : __('New') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('admin.template-samples.index') }}"
                class="px-3.5 py-1.5 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Cancel') }}</a>
            <button type="submit" form="sampleForm"
                class="px-4 py-1.5 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">
                {{ $editing ? __('Save changes') : __('Create sample') }}
            </button>
        </div>
    </header>

    <div class="px-4 sm:px-7 pt-7 pb-2">
        <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin · Messaging · ') }}{{ $editing ? __('Edit') : __('New') }}</div>
        <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[36px] leading-[1.0]">
            {{ $editing ? __('Edit') : __('New') }} <span class="italic text-wa-deep">{{ __('sample') }}</span></h1>
        <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
            {{ __('Tenants copy this into their own template and submit it to Meta. Use named tokens like {{name}} — never start or end the body with a token.') }}
        </p>
    </div>

    <main class="px-4 sm:px-7 pb-7">
        @if ($errors->any())
            <div class="mb-4 rounded-2xl border border-accent-coral/40 bg-accent-coral/10 text-accent-coral px-4 py-3 text-[12.5px]">
                <div class="font-semibold mb-1">{{ __('Please fix the following:') }}</div>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif
        <x-admin.flash />

        <form id="sampleForm" method="POST" action="{{ $action }}">
            @csrf
            @if ($editing) @method('PUT') @endif

            <section class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-5 items-start">
                <div class="space-y-5 min-w-0">
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('details') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Sample details') }}</h2>
                        </div>
                        <div class="p-5 space-y-4">
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Title') }} <span class="text-accent-coral">*</span></span>
                                <input name="title" id="sample-title" value="{{ old('title', $sample->title ?? '') }}" required maxlength="160"
                                    placeholder="{{ __('e.g. Ramadan greeting') }}"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[14px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Slug') }} <span class="text-accent-coral">*</span></span>
                                <input name="slug" id="sample-slug" value="{{ old('slug', $sample->slug ?? '') }}" required maxlength="80"
                                    placeholder="ramadan_greeting"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                                <span class="text-[11px] text-ink-500">{{ __('Shown on the tenant card. letters, numbers, underscores.') }}</span>
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="space-y-1.5 block">
                                    <span class="text-[11.5px] font-semibold">{{ __('Library category') }}</span>
                                    <select name="category" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                        @foreach (\App\Support\WaTemplateSampleLibrary::CATEGORIES as $key => $label)
                                            <option value="{{ $key }}" @selected(old('category', $sample->category ?? 'festival') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="space-y-1.5 block">
                                    <span class="text-[11.5px] font-semibold">{{ __('WhatsApp type') }}</span>
                                    <select name="meta_category" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                        @foreach (\App\Models\WaTemplateSample::META_CATEGORIES as $key => $label)
                                            <option value="{{ $key }}" @selected(old('meta_category', $sample->meta_category ?? 'marketing') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('copy') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Message') }}</h2>
                        </div>
                        <div class="p-5 space-y-4">
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Header') }} <span class="text-accent-coral">*</span></span>
                                <input name="header" value="{{ old('header', $sample->header ?? '') }}" required maxlength="60"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[14px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Body') }} <span class="text-accent-coral">*</span></span>
                                <textarea name="body" rows="5" required maxlength="1024"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep"
                                    placeholder="{{ __('Hello {{name}}, …') }}">{{ old('body', $sample->body ?? '') }}</textarea>
                            </label>
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Footer') }}</span>
                                <input name="footer" value="{{ old('footer', $sample->footer ?? '') }}" maxlength="60"
                                    placeholder="{{ __('Leave blank on marketing — we add Reply STOP to unsubscribe.') }}"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                        </div>
                    </div>

                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('buttons') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Up to 3 buttons') }}</h2>
                        </div>
                        <div class="p-5 space-y-3">
                            @foreach ($buttons as $i => $btn)
                                <div class="grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr] gap-2 items-end">
                                    <label class="space-y-1 block">
                                        <span class="text-[11px] font-semibold">{{ __('Type') }}</span>
                                        <select name="buttons[{{ $i }}][type]" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px]">
                                            <option value="quick_reply" @selected(($btn['type'] ?? '') === 'quick_reply')>{{ __('Quick reply') }}</option>
                                            <option value="visit_website" @selected(($btn['type'] ?? '') === 'visit_website')>{{ __('Visit website') }}</option>
                                        </select>
                                    </label>
                                    <label class="space-y-1 block">
                                        <span class="text-[11px] font-semibold">{{ __('Label') }}</span>
                                        <input name="buttons[{{ $i }}][text]" value="{{ $btn['text'] ?? '' }}" maxlength="25"
                                            class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px]">
                                    </label>
                                    <label class="space-y-1 block">
                                        <span class="text-[11px] font-semibold">{{ __('URL (website only)') }}</span>
                                        <input name="buttons[{{ $i }}][value]" value="{{ $btn['value'] ?? '' }}" maxlength="2000"
                                            placeholder="https://"
                                            class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono">
                                    </label>
                                </div>
                            @endforeach
                            <p class="text-[11px] text-ink-500">{{ __('Leave a row blank to skip it.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-5 lg:sticky lg:top-[84px] self-start">
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('card') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Look') }}</h2>
                        </div>
                        <div class="p-5 space-y-4">
                            <div class="h-[88px] rounded-xl text-white px-3 py-2.5 flex flex-col justify-between" id="sample-preview"
                                style="background: linear-gradient(135deg, {{ $colorFrom }}, {{ $colorTo }})">
                                <span class="text-[20px] leading-none" id="sample-preview-emoji">{{ old('emoji', $sample->emoji ?? '✦') }}</span>
                                <span class="text-[12.5px] font-semibold truncate" id="sample-preview-header">{{ old('header', $sample->header ?? __('Header')) }}</span>
                            </div>
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Emoji') }}</span>
                                <input name="emoji" id="sample-emoji" value="{{ old('emoji', $sample->emoji ?? '✦') }}" maxlength="16"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[16px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="space-y-1.5 block">
                                    <span class="text-[11.5px] font-semibold">{{ __('From') }}</span>
                                    <input name="color_from" id="sample-from" type="color" value="{{ $colorFrom }}"
                                        class="w-full h-10 rounded-xl border border-paper-200 bg-paper-0 p-1">
                                </label>
                                <label class="space-y-1.5 block">
                                    <span class="text-[11.5px] font-semibold">{{ __('To') }}</span>
                                    <input name="color_to" id="sample-to" type="color" value="{{ $colorTo }}"
                                        class="w-full h-10 rounded-xl border border-paper-200 bg-paper-0 p-1">
                                </label>
                            </div>
                            <label class="space-y-1.5 block">
                                <span class="text-[11.5px] font-semibold">{{ __('Sort order') }}</span>
                                <input name="sort_order" type="number" min="0" max="9999" value="{{ old('sort_order', $sample->sort_order ?? 0) }}"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="flex items-start gap-2.5 pt-1 cursor-pointer">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $sample->is_active ?? true)) class="w-4 h-4 mt-0.5 accent-wa-deep">
                                <span class="text-[12.5px] text-ink-700">{{ __('Visible to tenants on Templates.') }}</span>
                            </label>
                        </div>
                        <div class="px-5 py-4 border-t border-paper-200">
                            <button type="submit" class="w-full px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">
                                {{ $editing ? __('Save changes') : __('Create sample') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </form>
    </main>

    <script>
        (function () {
            const title = document.getElementById('sample-title');
            const slug = document.getElementById('sample-slug');
            const from = document.getElementById('sample-from');
            const to = document.getElementById('sample-to');
            const preview = document.getElementById('sample-preview');
            const emoji = document.getElementById('sample-emoji');
            const emojiOut = document.getElementById('sample-preview-emoji');
            const header = document.querySelector('[name="header"]');
            const headerOut = document.getElementById('sample-preview-header');
            let slugTouched = {{ $editing ? 'true' : 'false' }};
            slug?.addEventListener('input', () => { slugTouched = true; });
            title?.addEventListener('input', () => {
                if (slugTouched || !slug) return;
                slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 80);
            });
            function paint() {
                if (preview && from && to) preview.style.background = 'linear-gradient(135deg, ' + from.value + ', ' + to.value + ')';
                if (emojiOut && emoji) emojiOut.textContent = emoji.value || '✦';
                if (headerOut && header) headerOut.textContent = header.value || 'Header';
            }
            from?.addEventListener('input', paint);
            to?.addEventListener('input', paint);
            emoji?.addEventListener('input', paint);
            header?.addEventListener('input', paint);
        })();
    </script>
</x-layouts.admin>
