@php
    $sample = $sample ?? null;
    $editing = (bool) $sample;
    $action = $editing ? route('admin.template-samples.update', $sample?->id) : route('admin.template-samples.store');
    $buttons = old('buttons', $sample?->buttons ?? []);
    if (! is_array($buttons)) {
        $buttons = [];
    }
    while (count($buttons) < 1) {
        $buttons[] = ['type' => 'quick_reply', 'text' => '', 'value' => ''];
    }
    $headerType = old('header_type', $sample?->header_type ?? 'text');
    $colorFrom = strtolower((string) old('color_from', $sample?->color_from ?? '#1B4B3D'));
    $colorTo = strtolower((string) old('color_to', $sample?->color_to ?? '#037D66'));
    $brand = brand_name();
@endphp

<x-layouts.admin :title="$editing ? __('Edit sample') : __('New sample')" admin-key="template-samples" page="admin-template-samples-form">

    <div class="hairline-b border-b border-paper-200 bg-paper-0 sticky top-0 z-30">
        <div class="px-4 sm:px-7 py-3 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('admin.template-samples.index') }}"
                    class="w-8 h-8 rounded-full hairline border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center"
                    title="{{ __('Back') }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 4l-4 4 4 4" /></svg>
                </a>
                <div class="min-w-0">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Templates / ') }}{{ $editing ? __('Edit') : __('New') }}</div>
                    <div class="font-serif tracking-[-0.01em] text-[20px] leading-tight truncate">
                        {{ __('Create message') }} <span class="italic text-wa-deep">{{ __('template') }}</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-wa-mint text-wa-deep border border-wa-green/40 font-mono">{{ __('Standard') }}</span>
                <a href="{{ route('admin.template-samples.index') }}"
                    class="px-3.5 py-1.5 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Cancel') }}</a>
                <button type="submit" form="sampleForm"
                    class="px-3.5 py-1.5 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">
                    {{ __('Save to library') }}
                </button>
                <button type="submit" form="sampleForm" name="push_to_customers" value="1"
                    class="px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 11V3M4.5 6.5 8 3l3.5 3.5M3 13h10" /></svg>
                    {{ __('Save & push to customers') }}
                </button>
            </div>
        </div>
    </div>

    <section class="px-4 sm:px-7 py-6">
        @if ($errors->any())
            <div class="mb-4 rounded-2xl border border-accent-coral/40 bg-accent-coral/10 px-4 py-3 text-[12px] text-[#A1431F]">
                <div class="font-semibold mb-1">{{ __('Could not save the template:') }}</div>
                <ul class="list-disc pl-4 space-y-0.5">
                    @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif
        <x-admin.flash />

        <form id="sampleForm" method="POST" action="{{ $action }}" enctype="multipart/form-data"
            class="grid grid-cols-1 xl:grid-cols-[1fr_342px] gap-5">
            @csrf
            @if ($editing) @method('PUT') @endif
            <input type="hidden" name="color_from" id="sample-from" value="{{ $colorFrom }}">
            <input type="hidden" name="color_to" id="sample-to" value="{{ $colorTo }}">
            <input type="hidden" name="emoji" id="sample-emoji" value="{{ old('emoji', $sample?->emoji ?? '✦') }}">
            <input type="hidden" name="is_active" value="0">
            <input type="hidden" name="sort_order" value="{{ old('sort_order', $sample?->sort_order ?? 0) }}">

            <div class="bg-white border border-paper-200 rounded-[14px] shadow-card overflow-hidden min-w-0">
                <div class="sec px-[18px] py-4 hairline-b border-b border-paper-200">
                    <div class="sec-head flex items-center gap-2.5 mb-3">
                        <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">01</span>
                        <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Template name') }}</span>
                        <span class="font-mono text-[10px] text-ink-500">{{ __('required') }}</span>
                    </div>
                    <label class="block mb-3">
                        <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('Title') }}</span>
                        <input name="title" id="sample-title" value="{{ old('title', $sample?->title ?? '') }}" required maxlength="160"
                            placeholder="{{ __('e.g. Ramadan greeting') }}"
                            class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
                    </label>
                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('Name') }} <span class="font-mono text-[10px] text-ink-500">{{ __('letters, numbers, underscore') }}</span></span>
                        <input name="slug" id="sample-slug" value="{{ old('slug', $sample?->slug ?? '') }}" required maxlength="80"
                            placeholder="ramadan_greeting"
                            class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('Category') }}</span>
                            <select name="category" class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep">
                                @foreach (\App\Support\WaTemplateSampleLibrary::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('category', $sample?->category ?? 'festival') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('WhatsApp type') }}</span>
                            <select name="meta_category" class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep">
                                @foreach (\App\Models\WaTemplateSample::META_CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('meta_category', $sample?->meta_category ?? 'marketing') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('Language') }}</span>
                            <select name="language" id="sample-lang" class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep">
                                @foreach (['en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'ar' => 'Arabic', 'hi' => 'Hindi', 'id' => 'Indonesian', 'pt_BR' => 'Portuguese (BR)', 'es' => 'Spanish', 'fr' => 'French'] as $code => $label)
                                    <option value="{{ $code }}" @selected(old('language', $sample?->language ?? 'en_US') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="flex items-center gap-2 mt-3 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $sample?->is_active ?? true)) class="accent-wa-deep">
                        <span class="text-[12.5px] text-ink-700">{{ __('Visible to customers on Templates') }}</span>
                    </label>
                </div>

                <div class="sec px-[18px] py-4 hairline-b border-b border-paper-200">
                    <div class="sec-head flex items-center gap-2.5 mb-3">
                        <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">02</span>
                        <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Header') }}</span>
                        <span class="font-mono text-[10px] text-ink-500">{{ __('text or image') }}</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-[160px_1fr] gap-3">
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('Type') }}</span>
                            <select name="header_type" id="header-type" class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep">
                                <option value="text" @selected($headerType !== 'image')>{{ __('Text') }}</option>
                                <option value="image" @selected($headerType === 'image')>{{ __('Image') }}</option>
                            </select>
                        </label>
                        <label class="block" data-header-text>
                            <span class="text-[11.5px] font-semibold text-ink-700 mb-[5px] block">{{ __('Header text') }}</span>
                            <input id="tpl-header" name="header" value="{{ old('header', $sample?->header ?? '') }}" maxlength="60"
                                class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep"
                                placeholder="Hi @{{name}}, welcome aboard!">
                        </label>
                    </div>
                    <div class="mt-3 space-y-2" data-header-image>
                        <span class="text-[11.5px] font-semibold text-ink-700 block">{{ __('Header image') }}</span>
                        @if ($sample?->image_path)
                            <img src="{{ $sample->imageUrl() }}" alt="" class="w-full max-h-40 object-cover rounded-lg border border-paper-200" id="existing-header-img">
                            <label class="flex items-center gap-2 text-[12px] text-ink-600 cursor-pointer">
                                <input type="checkbox" name="remove_image" value="1" class="accent-wa-deep">
                                {{ __('Remove current image') }}
                            </label>
                        @endif
                        <input type="file" name="image" id="sample-image" accept="image/jpeg,image/png,image/webp"
                            class="w-full px-[11px] py-2 border border-dashed border-wa-deep rounded-lg bg-paper-0 text-[12.5px]">
                        <div class="text-[10.5px] text-ink-500">{{ __('JPEG, PNG, or WebP · max 5MB. Becomes the WhatsApp IMAGE header. Body text still sends below it.') }}</div>
                    </div>
                </div>

                <div class="sec px-[18px] py-4 hairline-b border-b border-paper-200">
                    <div class="sec-head flex items-center gap-2.5 mb-3">
                        <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">03</span>
                        <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Body') }}</span>
                        <span class="font-mono text-[10px] text-ink-500"><span class="text-accent-coral">{{ __('required') }}</span> / <span id="char-count">0</span>/1024</span>
                    </div>
                    <span class="sr-only">{{ __('Body text') }}</span>
                    <textarea id="tpl-body" name="body" rows="6" required maxlength="1024"
                        class="w-full px-[11px] py-[8px] border border-paper-200 rounded-lg bg-white text-[12.5px] leading-[1.45] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10"
                        placeholder="Hello @{{name}}, …">{{ old('body', $sample?->body ?? '') }}</textarea>
                    <div class="text-[10.5px] text-ink-500 mt-1">{{ __('Use named tokens like') }} @{{name}}. {{ __('Never start or end the body with a token.') }}</div>
                </div>

                <div class="sec px-[18px] py-4 hairline-b border-b border-paper-200">
                    <div class="sec-head flex items-center gap-2.5 mb-3">
                        <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">04</span>
                        <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Footer') }}</span>
                        <span class="font-mono text-[10px] text-ink-500">{{ __('optional / max 60') }}</span>
                    </div>
                    <input id="tpl-footer" name="footer" type="text" value="{{ old('footer', $sample?->footer ?? '') }}" maxlength="60"
                        placeholder="{{ __('Reply STOP to unsubscribe') }}"
                        class="w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] focus:outline-none focus:border-wa-deep">
                    <div class="text-[10.5px] text-ink-500 mt-1">{{ __('Plain text under the body. Marketing samples get Reply STOP if you leave this blank.') }}</div>
                </div>

                <div class="sec px-[18px] py-4">
                    <div class="sec-head flex items-center gap-2.5 mb-3">
                        <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">05</span>
                        <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Buttons') }}</span>
                        <span class="font-mono text-[10px] text-ink-500">{{ __('optional / up to 3') }}</span>
                    </div>
                    <div class="space-y-2" id="admin-btn-list">
                        @foreach ($buttons as $i => $btn)
                            <div class="grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr] gap-1.5 items-end">
                                <label class="block">
                                    <span class="text-[11px] font-semibold">{{ __('Type') }}</span>
                                    <select name="buttons[{{ $i }}][type]" class="js-btn-type w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px]">
                                        <option value="quick_reply" @selected(($btn['type'] ?? '') === 'quick_reply')>{{ __('Quick reply') }}</option>
                                        <option value="visit_website" @selected(($btn['type'] ?? '') === 'visit_website')>{{ __('Visit website') }}</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold">{{ __('Button text') }}</span>
                                    <input name="buttons[{{ $i }}][text]" value="{{ $btn['text'] ?? '' }}" maxlength="25"
                                        class="js-btn-text w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px]">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold">{{ __('URL (website only)') }}</span>
                                    <input name="buttons[{{ $i }}][value]" value="{{ $btn['value'] ?? '' }}" maxlength="2000"
                                        placeholder="https://"
                                        class="js-btn-value w-full px-[11px] py-[7px] border border-paper-200 rounded-lg bg-white text-[12.5px] font-mono">
                                </label>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-ink-500 mt-2">{{ __('Leave a row blank to skip it.') }}</p>
                </div>
            </div>

            <aside class="sticky top-[78px] self-start space-y-3">
                <div class="card bg-white border border-paper-200 rounded-[14px] shadow-card p-3">
                    <div class="flex items-center justify-between mb-2 px-1">
                        <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-wa-green animate-pulse"></span>
                            {{ __('Live preview') }}
                        </div>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-paper-50 text-ink-700 font-mono" id="lang-pill">{{ old('language', $sample?->language ?? 'en_US') }}</span>
                    </div>
                    <div class="bg-ink-900 rounded-[24px] p-[7px] shadow-[0_12px_36px_-16px_rgba(11,31,28,0.4)] max-w-[300px] mx-auto">
                        <div class="bg-wa-chat rounded-[18px] min-h-[420px] flex flex-col overflow-hidden">
                            <div class="bg-wa-deep text-paper-0 px-3 py-2 flex items-center gap-[7px] text-[11.5px]">
                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="currentColor"><path d="M9 3l-4 5 4 5V9h5V7H9z" /></svg>
                                <div class="w-6 h-6 rounded-full bg-wa-mint text-wa-deep flex items-center justify-center text-[9px] font-semibold">{{ mb_strtoupper(mb_substr($brand, 0, 1)) }}</div>
                                <div class="leading-tight">
                                    <div class="text-[11.5px] font-semibold">{{ $brand }}</div>
                                    <div class="text-[9px] opacity-70">{{ __('online') }}</div>
                                </div>
                            </div>
                            <div class="flex-1 p-3 bg-wa-chat [background-image:radial-gradient(rgba(7,94,84,0.06)_1px,transparent_1px)] bg-[length:14px_14px]">
                                <div class="bg-paper-0 rounded-[7px] rounded-tl-[2px] shadow-[0_1px_1px_rgba(0,0,0,0.06)] px-[9px] py-2 max-w-[88%] mb-[5px] text-[12px] leading-[1.4] break-words" id="pp-card">
                                    <div class="rounded-[5px] h-20 mb-[5px] overflow-hidden bg-[#DFF1ED] hidden" id="pp-attach">
                                        <img alt="" class="w-full h-full object-cover" id="pp-attach-img">
                                    </div>
                                    <div class="font-semibold text-[12px] mb-[3px] hidden" id="pp-header"></div>
                                    <div id="pp-body">{{ __('your message will appear here...') }}</div>
                                    <div class="text-[10.5px] text-ink-500 mt-[5px] hidden" id="pp-footer"></div>
                                    <div class="text-[9px] text-ink-500 text-right mt-1 font-mono">14:08</div>
                                </div>
                                <div class="max-w-[88%] flex flex-col gap-[3px] mt-[3px] hidden" id="pp-btn-list"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-paper-200 rounded-[14px] shadow-card p-3 bg-wa-bubble/40">
                    <div class="text-[11px] text-ink-700 leading-snug">
                        <b>{{ __('Tip:') }}</b>
                        {{ __('This is the same WhatsApp preview customers use. Push installs it on their Templates list. Cloud API still needs Meta on each number.') }}
                    </div>
                </div>
                <button type="submit" form="sampleForm" name="push_to_customers" value="1"
                    class="w-full px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">
                    {{ __('Save & push to customers') }}
                </button>
            </aside>
        </form>
    </section>

    <script>
        (function () {
            const title = document.getElementById('sample-title');
            const slug = document.getElementById('sample-slug');
            const header = document.getElementById('tpl-header');
            const body = document.getElementById('tpl-body');
            const footer = document.getElementById('tpl-footer');
            const headerType = document.getElementById('header-type');
            const file = document.getElementById('sample-image');
            const lang = document.getElementById('sample-lang');
            const ppHeader = document.getElementById('pp-header');
            const ppBody = document.getElementById('pp-body');
            const ppFooter = document.getElementById('pp-footer');
            const ppAttach = document.getElementById('pp-attach');
            const ppAttachImg = document.getElementById('pp-attach-img');
            const ppBtns = document.getElementById('pp-btn-list');
            const charCount = document.getElementById('char-count');
            const langPill = document.getElementById('lang-pill');
            const existingImg = document.getElementById('existing-header-img');
            let slugTouched = {{ $editing ? 'true' : 'false' }};
            let objectUrl = '';

            slug?.addEventListener('input', () => { slugTouched = true; });
            title?.addEventListener('input', () => {
                if (slugTouched || !slug) return;
                slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 80);
            });

            function paint() {
                const isImage = headerType && headerType.value === 'image';
                if (ppHeader) {
                    const h = (header?.value || '').trim();
                    ppHeader.textContent = h;
                    ppHeader.classList.toggle('hidden', isImage || h === '');
                }
                if (ppBody) ppBody.textContent = (body?.value || '').trim() || 'your message will appear here...';
                if (charCount && body) charCount.textContent = String((body.value || '').length);
                if (ppFooter) {
                    const f = (footer?.value || '').trim();
                    ppFooter.textContent = f;
                    ppFooter.classList.toggle('hidden', f === '');
                }
                if (langPill && lang) langPill.textContent = lang.value || 'en_US';
                const hasNew = file && file.files && file.files[0];
                if (ppAttach && ppAttachImg) {
                    if (hasNew) {
                        if (objectUrl) URL.revokeObjectURL(objectUrl);
                        objectUrl = URL.createObjectURL(file.files[0]);
                        ppAttachImg.src = objectUrl;
                        ppAttach.classList.remove('hidden');
                    } else if (isImage && existingImg) {
                        ppAttachImg.src = existingImg.src;
                        ppAttach.classList.remove('hidden');
                    } else {
                        ppAttach.classList.add('hidden');
                    }
                }
                if (ppBtns) {
                    ppBtns.innerHTML = '';
                    let n = 0;
                    document.querySelectorAll('#admin-btn-list .js-btn-text').forEach((input) => {
                        const t = (input.value || '').trim();
                        if (!t) return;
                        n++;
                        const el = document.createElement('div');
                        el.className = 'bg-paper-0 rounded-[7px] px-2 py-1.5 text-center text-[11.5px] font-semibold text-wa-deep shadow-[0_1px_1px_rgba(0,0,0,0.06)]';
                        el.textContent = t;
                        ppBtns.appendChild(el);
                    });
                    ppBtns.classList.toggle('hidden', n === 0);
                }
            }

            ['input', 'change'].forEach((ev) => {
                header?.addEventListener(ev, paint);
                body?.addEventListener(ev, paint);
                footer?.addEventListener(ev, paint);
                headerType?.addEventListener(ev, paint);
                lang?.addEventListener(ev, paint);
                file?.addEventListener(ev, paint);
                document.querySelectorAll('#admin-btn-list input, #admin-btn-list select').forEach((el) => el.addEventListener(ev, paint));
            });
            paint();
        })();
    </script>
</x-layouts.admin>
