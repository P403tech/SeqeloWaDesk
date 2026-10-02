<x-layouts.admin :title="__('Support Bot')" admin-key="support-bot" page="admin-support-bot-index">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Support Bot') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-6 lg:px-7 py-7 space-y-5">

        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin · Help desk') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[34px] lg:text-[40px] leading-[1.0]">{{ __('Client support') }}
                    <span class="italic text-wa-deep">{{ __('bot') }}</span>.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('One help-desk widget for every logged-in user. Answers from your docs first; escalates to AI only when docs are weak, then to the web only when the answer is not in the docs. Everything here is admin-set — users just ask.') }}
                </p>
            </div>
            <div class="flex items-center flex-wrap gap-2 shrink-0 pb-1">
                <a href="{{ url('/admin/settings') }}" class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All settings') }}</a>
                <x-admin.flash inline />
            </div>
        </div>

        @if (session('error'))
            <div class="rounded-xl border border-accent-coral/40 bg-accent-coral/10 text-accent-coral px-4 py-2.5 text-[12.5px]">{{ session('error') }}</div>
        @endif

        {{-- ============ Settings form ============ --}}
        <form method="POST" action="{{ route('admin.settings.support-bot.save') }}" class="space-y-5" data-sb-models='@json($models)'>
            @csrf

            <section class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

                {{-- Master + appearance --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
                        <h2 class="font-serif text-[22px] leading-tight">{{ __('Widget') }}</h2>
                        <span class="rounded-full {{ $cfg['enabled'] ? 'bg-wa-mint text-wa-deep border-wa-green/40' : 'bg-paper-100 text-ink-500 border-paper-200' }} border px-2.5 py-1 text-[11px] font-mono">{{ $cfg['enabled'] ? __('on') : __('off') }}</span>
                    </div>
                    <div class="p-5 space-y-4">
                        <label class="rounded-2xl border border-paper-200 p-4 flex items-center justify-between gap-3">
                            <span>
                                <span class="block text-[12.5px] font-semibold">{{ __('Enable support bot') }}</span>
                                <span class="block text-[10.5px] text-ink-500 mt-0.5">{{ __('Shows the floating help launcher for every logged-in user.') }}</span>
                            </span>
                            <span class="toggle"><input type="hidden" name="support_bot_enabled" value="0"><input type="checkbox" name="support_bot_enabled" value="1" @checked(old('support_bot_enabled', $cfg['enabled']))><span class="track"></span><span class="thumb"></span></span>
                        </label>

                        <div class="grid grid-cols-2 gap-3">
                            <label class="space-y-1.5 col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __('Title') }}</span>
                                <input name="support_bot_title" value="{{ old('support_bot_title', $cfg['title']) }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __('Greeting') }}</span>
                                <input name="support_bot_greeting" value="{{ old('support_bot_greeting', $cfg['greeting']) }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __('No-answer message') }}</span>
                                <input name="support_bot_no_answer" value="{{ old('support_bot_no_answer', $cfg['no_answer']) }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5">
                                <span class="text-[11.5px] font-semibold">{{ __('Accent colour') }}</span>
                                <input name="support_bot_theme_color" type="text" value="{{ old('support_bot_theme_color', $cfg['theme_color']) }}" placeholder="#128C7E" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5">
                                <span class="text-[11.5px] font-semibold">{{ __('Position') }}</span>
                                <select name="support_bot_position" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                    <option value="right" @selected($cfg['position'] === 'right')>{{ __('Bottom right') }}</option>
                                    <option value="left" @selected($cfg['position'] === 'left')>{{ __('Bottom left') }}</option>
                                </select>
                            </label>
                            <label class="space-y-1.5">
                                <span class="text-[11.5px] font-semibold">{{ __('Support URL') }}</span>
                                <input name="support_bot_support_url" value="{{ old('support_bot_support_url', $cfg['support_url']) }}" placeholder="https://…" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5">
                                <span class="text-[11.5px] font-semibold">{{ __('Support email') }}</span>
                                <input name="support_bot_support_email" value="{{ old('support_bot_support_email', $cfg['support_email']) }}" placeholder="help@…" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Escalation tiers --}}
                <div class="space-y-5">
                    {{-- AI tier --}}
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <h2 class="font-serif text-[22px] leading-tight">{{ __('AI tier') }} <span class="text-[11px] font-mono text-ink-500">({{ __('rare — weak doc matches') }})</span></h2>
                            <p class="text-[12px] text-ink-600 mt-1">{{ __('Only used when docs alone are shaky. Answers are grounded in your docs.') }}</p>
                        </div>
                        <div class="p-5 space-y-4">
                            <label class="rounded-2xl border border-paper-200 p-4 flex items-center justify-between gap-3">
                                <span class="block text-[12.5px] font-semibold">{{ __('Enable AI answers') }}</span>
                                <span class="toggle"><input type="hidden" name="support_bot_ai_enabled" value="0"><input type="checkbox" name="support_bot_ai_enabled" value="1" @checked(old('support_bot_ai_enabled', $cfg['ai_enabled']))><span class="track"></span><span class="thumb"></span></span>
                            </label>

                            @if (!$anyKey)
                                <div class="rounded-xl border border-accent-amber/40 bg-accent-amber/10 text-[12px] text-[#7B5A14] px-4 py-2.5">
                                    {{ __('No AI keys are set yet.') }}
                                    <a href="{{ url('/admin/api-keys') }}" class="font-semibold underline">{{ __('Add one in Admin → AI Keys') }}</a>
                                    {{ __('and it will appear here automatically.') }}
                                </div>
                            @else
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="space-y-1.5">
                                        <span class="text-[11.5px] font-semibold">{{ __('Provider') }}</span>
                                        <select name="support_bot_provider" data-sb-provider="ai" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                            @foreach ($providers as $val => $lbl)
                                                <option value="{{ $val }}" @selected($cfg['ai_provider'] === $val)>{{ $lbl }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="space-y-1.5">
                                        <span class="text-[11.5px] font-semibold">{{ __('Model') }}</span>
                                        @php $curAi = old('support_bot_model', $cfg['ai_model']); $aiList = $models[$cfg['ai_provider']] ?? []; @endphp
                                        <select name="support_bot_model" data-sb-model="ai" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                                            @if ($curAi !== '' && !in_array($curAi, $aiList, true))
                                                <option value="{{ $curAi }}" selected>{{ $curAi }}</option>
                                            @endif
                                            @foreach ($aiList as $m)
                                                <option value="{{ $m }}" @selected($curAi === $m)>{{ $m }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                                <p class="text-[10.5px] text-ink-500">{{ __('Uses the key you set in Admin → AI Keys for this provider. No key needed here.') }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Beyond-docs fallback — reuses the SAME AI provider/key above. --}}
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <h2 class="font-serif text-[22px] leading-tight">{{ __('Answer beyond the docs') }} <span class="text-[11px] font-mono text-ink-500">({{ __('last resort') }})</span></h2>
                            <p class="text-[12px] text-ink-600 mt-1">{{ __('When the answer is not in your docs, let the AI answer from general/web knowledge (labelled as a general answer). Uses the SAME AI provider + key above — choose a web-grounded model like Perplexity if you want live web results.') }}</p>
                        </div>
                        <div class="p-5 space-y-4">
                            <label class="rounded-2xl border border-paper-200 p-4 flex items-center justify-between gap-3">
                                <span>
                                    <span class="block text-[12.5px] font-semibold">{{ __('Answer beyond the docs') }}</span>
                                    <span class="block text-[10.5px] text-ink-500 mt-0.5">{{ __('Off = the bot only answers from your docs, and shows the contact card otherwise.') }}</span>
                                </span>
                                <span class="toggle"><input type="hidden" name="support_bot_web_enabled" value="0"><input type="checkbox" name="support_bot_web_enabled" value="1" @checked(old('support_bot_web_enabled', $cfg['web_enabled']))><span class="track"></span><span class="thumb"></span></span>
                            </label>
                            <label class="space-y-1.5 block max-w-[220px]">
                                <span class="text-[11.5px] font-semibold">{{ __('Docs relevance threshold') }}</span>
                                <input name="support_bot_docs_threshold" type="number" step="0.01" min="0" max="1" value="{{ old('support_bot_docs_threshold', $cfg['docs_threshold']) }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                                <span class="block text-[10.5px] text-ink-500">{{ __('Below this a question counts as "not in the docs".') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <div class="admin-save-bar flex items-center justify-between gap-3 mt-2 px-4 py-2.5 bg-paper-0 border border-paper-200 rounded-full shadow-card">
                <span class="text-[11.5px] text-ink-500">{{ __('Changes apply only after you save.') }}</span>
                <button type="submit" class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save changes') }}</button>
            </div>
        </form>

        {{-- ============ Knowledge sources ============ --}}
        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
                <div>
                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Knowledge sources') }}</h2>
                    <p class="text-[12px] text-ink-600 mt-1">{{ __('Upload help docs (md, html, pdf, docx, txt, csv), add a page URL, or paste text.') }} · {{ trans_choice('{0}no chunks|{1}:count chunk|[2,*]:count chunks', $chunkCount, ['count' => $chunkCount]) }}</p>
                </div>
                <form method="POST" action="{{ route('admin.settings.support-bot.reindex') }}">@csrf
                    <button type="submit" class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Reindex all') }}</button>
                </form>
            </div>

            <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <form method="POST" action="{{ route('admin.settings.support-bot.source.file') }}" enctype="multipart/form-data" class="space-y-2 rounded-xl border border-paper-200 p-4">
                    @csrf
                    <div class="text-[12px] font-semibold">{{ __('Upload file or ZIP') }}</div>
                    <input type="file" name="file" required accept=".md,.markdown,.txt,.text,.csv,.log,.html,.htm,.pdf,.docx,.zip"
                        class="block w-full text-[12px] file:mr-3 file:px-3 file:py-1.5 file:rounded-full file:border-0 file:bg-wa-deep file:text-paper-0 file:text-[11.5px] file:font-medium file:cursor-pointer">
                    <div class="text-[10.5px] text-ink-500">{{ __('md, html, pdf, docx, txt, csv — or a .zip of docs (each page imported as an article).') }}</div>
                    <input type="text" name="label" placeholder="{{ __('Label (optional, ignored for ZIP)') }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] focus:outline-none focus:border-wa-deep">
                    <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[11.5px] font-semibold hover:bg-wa-teal">{{ __('Upload') }}</button>
                </form>

                <form method="POST" action="{{ route('admin.settings.support-bot.source.add') }}" class="space-y-2 rounded-xl border border-paper-200 p-4">
                    @csrf
                    <input type="hidden" name="kind" value="url">
                    <div class="text-[12px] font-semibold">{{ __('Add URL') }}</div>
                    <input type="text" name="url" placeholder="https://help.example.com/page" required class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] font-mono focus:outline-none focus:border-wa-deep">
                    <input type="text" name="label" placeholder="{{ __('Label (optional)') }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] focus:outline-none focus:border-wa-deep">
                    <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[11.5px] font-semibold hover:bg-wa-teal">{{ __('Fetch & add') }}</button>
                </form>

                <form method="POST" action="{{ route('admin.settings.support-bot.source.add') }}" class="space-y-2 rounded-xl border border-paper-200 p-4">
                    @csrf
                    <input type="hidden" name="kind" value="text">
                    <div class="text-[12px] font-semibold">{{ __('Paste text') }}</div>
                    <input type="text" name="label" placeholder="{{ __('Label') }}" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] focus:outline-none focus:border-wa-deep">
                    <textarea name="text" rows="2" placeholder="{{ __('Markdown or plain text…') }}" required class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] focus:outline-none focus:border-wa-deep"></textarea>
                    <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[11.5px] font-semibold hover:bg-wa-teal">{{ __('Add') }}</button>
                </form>
            </div>

            <div class="px-5 pb-5">
                <div class="overflow-x-auto rounded-xl border border-paper-200">
                    <table class="w-full text-[12px]">
                        <thead class="bg-paper-50 text-ink-500 text-left">
                            <tr><th class="px-3 py-2 font-medium">{{ __('Source') }}</th><th class="px-3 py-2 font-medium">{{ __('Type') }}</th><th class="px-3 py-2 font-medium">{{ __('Status') }}</th><th class="px-3 py-2 font-medium">{{ __('Chunks') }}</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($sources as $s)
                                <tr class="border-t border-paper-200">
                                    <td class="px-3 py-2">{{ $s->label }}</td>
                                    <td class="px-3 py-2 font-mono text-ink-500">{{ $s->kind }}</td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-[10.5px] font-mono border {{ $s->status === 'ready' ? 'bg-wa-mint text-wa-deep border-wa-green/40' : ($s->status === 'error' ? 'bg-accent-coral/10 text-accent-coral border-accent-coral/30' : 'bg-paper-100 text-ink-500 border-paper-200') }}">{{ $s->status }}</span>
                                        @if ($s->status === 'error')<span class="block text-[10px] text-accent-coral mt-0.5">{{ \Illuminate\Support\Str::limit($s->error, 60) }}</span>@endif
                                    </td>
                                    <td class="px-3 py-2 font-mono">{{ $s->chunks()->count() }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" action="{{ route('admin.settings.support-bot.source.delete', $s->id) }}" onsubmit="return confirm('{{ __('Remove this source?') }}')">@csrf @method('DELETE')
                                            <button type="submit" class="text-accent-coral hover:underline text-[11.5px]">{{ __('Remove') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-ink-500">{{ __('No sources yet. Add help docs above.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ============ Ask log ============ --}}
        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            <div class="px-5 py-4 border-b border-paper-200">
                <h2 class="font-serif text-[22px] leading-tight">{{ __('Recent questions') }}</h2>
                <p class="text-[12px] text-ink-600 mt-1">{{ __('What users are asking and which tier answered.') }} · <span class="text-accent-coral">{{ $unmatched }} {{ __('unanswered') }}</span></p>
            </div>
            <div class="p-5 overflow-x-auto">
                <table class="w-full text-[12px]">
                    <thead class="bg-paper-50 text-ink-500 text-left">
                        <tr><th class="px-3 py-2 font-medium">{{ __('Question') }}</th><th class="px-3 py-2 font-medium">{{ __('Tier') }}</th><th class="px-3 py-2 font-medium">{{ __('Score') }}</th><th class="px-3 py-2 font-medium">{{ __('When') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $l)
                            <tr class="border-t border-paper-200 {{ $l->matched ? '' : 'bg-accent-coral/5' }}">
                                <td class="px-3 py-2">{{ \Illuminate\Support\Str::limit($l->question, 80) }}</td>
                                <td class="px-3 py-2 font-mono">{{ $l->engine }}</td>
                                <td class="px-3 py-2 font-mono text-ink-500">{{ $l->score }}</td>
                                <td class="px-3 py-2 text-ink-500">{{ $l->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-ink-500">{{ __('No questions yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

    </main>

</x-layouts.admin>
