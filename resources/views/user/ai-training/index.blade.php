<x-layouts.user :title="__('AI Agents')" nav-key="ai-training" page="user-ai-training-index">

    @php
        $currentStatus = $currentStatus ?? 'all';
        $statusPill = [
            'active' => ['bg' => 'bg-wa-mint', 'text' => 'text-wa-deep', 'dot' => 'bg-wa-green', 'label' => __('Active')],
            'paused' => ['bg' => 'bg-paper-100', 'text' => 'text-ink-600', 'dot' => 'bg-ink-400', 'label' => __('Paused')],
        ];
        $providerPill = [
            'openai' => ['bg' => 'bg-wa-mint', 'text' => 'text-wa-deep', 'dot' => 'bg-wa-green', 'label' => 'OpenAI'],
            'anthropic' => ['bg' => 'bg-[#F3E9FF]', 'text' => 'text-[#5B3D8A]', 'dot' => 'bg-[#7A52B2]', 'label' => 'Anthropic'],
            'gemini' => ['bg' => 'bg-[#D9E5F2]', 'text' => 'text-[#13478A]', 'dot' => 'bg-[#3D6FB5]', 'label' => 'Gemini'],
            'muse' => ['bg' => 'bg-[#E8F1FF]', 'text' => 'text-[#0668E1]', 'dot' => 'bg-[#0668E1]', 'label' => 'Muse'],
            'mistral' => ['bg' => 'bg-[#FFEFE5]', 'text' => 'text-[#9A4A1A]', 'dot' => 'bg-[#E07A3D]', 'label' => 'Mistral'],
        ];
        $accentPalette = [
            ['bg' => 'bg-wa-mint', 'text' => 'text-wa-deep'],
            ['bg' => 'bg-[#D9E5F2]', 'text' => 'text-[#13478A]'],
            ['bg' => 'bg-[#F3E9FF]', 'text' => 'text-[#5B3D8A]'],
            ['bg' => 'bg-paper-100', 'text' => 'text-ink-700'],
        ];
        $pausedCount = max(0, $stats['all'] - $stats['active']);
        $healthKey = $stats['all'] === 0 ? 'empty' : ($stats['active'] === $stats['all'] ? 'healthy' : 'attention');
        $healthLabel = [
            'empty' => __('Ready'),
            'healthy' => __('Healthy'),
            'attention' => __('Needs care'),
        ][$healthKey];
        $avgEntries = $stats['all'] > 0 ? number_format($stats['sources'] / $stats['all'], 1) : '0';
    @endphp

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-5">

        {{-- Hero --}}
        <section class="relative overflow-hidden rounded-[28px] border border-wa-green/25 bg-gradient-to-br from-wa-mint via-paper-0 to-[#EFF9F6] shadow-card">
            <div class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-wa-green/10 blur-3xl"></div>
            <div class="pointer-events-none absolute right-24 -bottom-16 h-40 w-40 rounded-full bg-wa-teal/10 blur-2xl"></div>
            <div class="relative p-5 sm:p-7 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
                <div class="min-w-0 max-w-2xl">
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Automation') }} · {{ __('Workspace') }}</div>
                    <h1 class="font-serif font-normal tracking-tight text-[34px] sm:text-[42px] lg:text-[48px] leading-[0.95]">
                        {{ __('AI') }} <span class="italic text-wa-deep">{{ __('Agents') }}</span>
                    </h1>
                    <p class="text-[14px] text-ink-600 mt-3 leading-relaxed">
                        {{ __('One agent. Identity, persona, brain, safety — then on Knowledge connect the pipes and train what it may say.') }}
                    </p>
                    <div class="flex flex-wrap gap-2 mt-4">
                        @foreach ([__('Identity'), __('Persona'), __('Brain'), __('Safety'), __('Knowledge')] as $i => $step)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-paper-0/80 border border-paper-200 text-[11px] font-medium text-ink-700">
                                <span class="font-mono text-[10px] text-wa-deep">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                {{ $step }}
                            </span>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center flex-wrap gap-2 shrink-0">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[11.5px] font-medium bg-paper-0 border border-wa-green/35 text-wa-deep font-mono">
                        <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>
                        {{ $stats['active'] }} {{ __('active') }}
                    </span>
                    <a href="{{ url('/ai-training/create') }}"
                        class="px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal flex items-center gap-2 shadow-sm">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 3v10M3 8h10" />
                        </svg>
                        {{ __('New smart agent') }}
                    </a>
                </div>
            </div>
        </section>

        {{-- Who answers WhatsApp --}}
        <form method="POST" action="{{ route('user.ai-training.responder-mode') }}"
            class="bg-paper-0 border border-paper-200 rounded-[22px] shadow-card overflow-hidden">
            @csrf
            <div class="px-5 py-4 sm:px-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 border-b border-paper-200">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('WhatsApp Cloud API') }}</div>
                    <h2 class="font-serif text-[22px] leading-tight mt-0.5">{{ __('Who answers the thread?') }}</h2>
                    <p class="text-[12.5px] text-ink-600 mt-1 max-w-xl">
                        {{ __('Pick one voice so customers never get two replies. This only applies to Cloud API WhatsApp — unofficial numbers always use :brand.', ['brand' => brand_name()]) }}
                    </p>
                </div>
                <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-700 shrink-0 bg-paper-50 border border-paper-200 rounded-full px-3 py-1.5">
                    <input type="checkbox" name="meta_agent_enabled" value="1" @checked($_metaOn)
                        class="rounded border-paper-300 text-wa-deep focus:ring-wa-deep">
                    {{ __('I use Meta Business Agent') }}
                </label>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
                @foreach (($modes ?? []) as $key => [$label, $desc])
                    <label class="cursor-pointer group rounded-2xl border p-4 transition {{ $_mode === $key ? 'border-wa-deep bg-wa-mint/40 shadow-[inset_0_0_0_1px_rgba(3,125,102,0.12)]' : 'border-paper-200 hover:border-wa-green/40 hover:bg-paper-50' }}">
                        <div class="flex items-start gap-2.5">
                            <input type="radio" name="ai_responder_mode" value="{{ $key }}" @checked($_mode === $key)
                                class="mt-0.5 text-wa-deep focus:ring-wa-deep">
                            <div>
                                <span class="text-[13.5px] font-semibold text-ink-900">{{ $label }}</span>
                                <p class="text-[12px] text-ink-500 mt-1 leading-relaxed">{{ $desc }}</p>
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>
            <div class="px-5 pb-5 flex flex-wrap items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">
                    {{ __('Save answer mode') }}
                </button>
                <span class="text-[11.5px] text-ink-500">
                    {{ __('Meta’s agent needs a WhatsApp Business (Cloud API) number.') }}
                </span>
            </div>
        </form>

        {{-- KPIs --}}
        <section class="grid grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Agents') }}</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="font-serif text-[34px] leading-none tabular-nums">{{ $stats['all'] }}</span>
                    <span class="text-[11.5px] text-ink-500">{{ $stats['active'] }} {{ __('live') }}</span>
                </div>
            </div>
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Knowledge') }}</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="font-serif text-[34px] leading-none tabular-nums">{{ number_format($stats['sources']) }}</span>
                    <span class="text-[11.5px] text-ink-500">{{ $stats['ready'] }} {{ __('indexed') }}</span>
                </div>
            </div>
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Avg / agent') }}</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="font-serif text-[34px] leading-none tabular-nums">{{ $avgEntries }}</span>
                    <span class="text-[11.5px] text-ink-500">{{ __('entries') }}</span>
                </div>
            </div>
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="flex items-center justify-between">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Health') }}</div>
                    <span class="text-[10.5px] font-mono text-wa-deep">{{ $stats['all'] > 0 ? round(($stats['active'] / max($stats['all'], 1)) * 100) : 0 }}%</span>
                </div>
                <div class="mt-2 font-serif text-[28px] leading-none">{{ $healthLabel }}</div>
            </div>
        </section>

        {{-- List --}}
        <section class="bg-paper-0 border border-paper-200 rounded-[22px] shadow-card overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-paper-200 flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-1 bg-paper-50 rounded-full p-1" role="tablist">
                    <button type="button" data-status-tab="all"
                        class="status-tab px-3.5 py-1.5 rounded-full text-[12.5px] font-semibold bg-wa-deep text-paper-0">
                        {{ __('All') }} <span class="ml-1 font-mono text-[10.5px] opacity-80">{{ $stats['all'] }}</span>
                    </button>
                    <button type="button" data-status-tab="active"
                        class="status-tab px-3.5 py-1.5 rounded-full text-[12.5px] font-semibold text-ink-600 hover:bg-paper-100">
                        {{ __('Active') }} <span class="ml-1 font-mono text-[10.5px] opacity-80">{{ $stats['active'] }}</span>
                    </button>
                    <button type="button" data-status-tab="paused"
                        class="status-tab px-3.5 py-1.5 rounded-full text-[12.5px] font-semibold text-ink-600 hover:bg-paper-100">
                        {{ __('Paused') }} <span class="ml-1 font-mono text-[10.5px] opacity-80">{{ $pausedCount }}</span>
                    </button>
                </div>
                <div class="relative w-full sm:w-72">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="7" cy="7" r="5" />
                        <path d="m11 11 3 3" />
                    </svg>
                    <input id="ait-search" type="search" placeholder="{{ __('Search by name or slug…') }}"
                        class="hairline border border-paper-200 rounded-full pl-9 pr-3 py-2 text-[12.5px] bg-white w-full focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
                </div>
            </div>

            <div id="ait-list" class="p-4 sm:p-5">
                @forelse ($assistants as $a)
                    @php
                        $accent = $accentPalette[$a->id % 4];
                        $status = $statusPill[$a->status] ?? $statusPill['active'];
                        $provider = $providerPill[$a->ai_provider] ?? $providerPill['openai'];
                    @endphp
                    <article
                        class="ait-row group mb-3 last:mb-0 rounded-2xl border border-paper-200 bg-paper-0 hover:border-wa-green/40 hover:bg-wa-mint/15 transition p-4 sm:p-5"
                        data-search-haystack="{{ Str::lower($a->name . ' ' . $a->slug) }}"
                        data-status="{{ $a->status }}">
                        <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                            <a href="{{ url('/ai-training/' . $a->id . '/edit') }}" class="flex items-center gap-3 min-w-0 flex-1">
                                <span class="w-12 h-12 rounded-2xl grid place-items-center shrink-0 {{ $accent['bg'] }} {{ $accent['text'] }}">
                                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path d="M12 3l7 4v5c0 4.2-2.9 7.1-7 8.5C7.9 19.1 5 16.2 5 12V7l7-4Z" />
                                        <path d="M9.5 12.2h.01M14.5 12.2h.01M9.8 15c.7.7 1.5 1 2.2 1s1.5-.3 2.2-1" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-semibold text-[15px] text-ink-900 truncate group-hover:text-wa-deep">{{ $a->name }}</span>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md font-mono text-[9.5px] uppercase tracking-[0.14em] {{ $status['bg'] }} {{ $status['text'] }}">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $status['dot'] }}"></span>{{ $status['label'] }}
                                        </span>
                                    </div>
                                    <div class="text-[12px] text-ink-500 font-mono truncate mt-0.5">
                                        /{{ $a->slug }} · {{ $a->ai_model }}
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                        @if ($a->channel_whatsapp ?? true)
                                            <span class="px-1.5 py-0.5 rounded-md bg-wa-mint text-wa-deep text-[10px] font-medium">{{ __('WhatsApp') }}</span>
                                        @endif
                                        @if ($a->channel_facebook ?? false)
                                            <span class="px-1.5 py-0.5 rounded-md bg-[#E8F1FF] text-[#0668E1] text-[10px] font-medium">{{ __('Facebook') }}</span>
                                        @endif
                                        @if ($a->channel_instagram ?? false)
                                            <span class="px-1.5 py-0.5 rounded-md bg-[#FCE7F3] text-[#BE185D] text-[10px] font-medium">{{ __('Instagram') }}</span>
                                        @endif
                                        @if ($a->channel_tiktok ?? false)
                                            <span class="px-1.5 py-0.5 rounded-md bg-ink-900 text-paper-0 text-[10px] font-medium">{{ __('TikTok') }}</span>
                                        @endif
                                        @if ($a->shopify_tools ?? false)
                                            <span class="px-1.5 py-0.5 rounded-md bg-paper-100 text-ink-700 text-[10px] font-medium">{{ __('Shopify') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </a>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 lg:contents">
                                <div class="lg:w-[140px]">
                                    <div class="font-mono text-[9.5px] uppercase tracking-[0.14em] text-ink-500 mb-1 lg:hidden">{{ __('Provider') }}</div>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-mono {{ $provider['bg'] }} {{ $provider['text'] }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $provider['dot'] }}"></span>{{ $provider['label'] }}
                                    </span>
                                </div>
                                <div class="lg:w-[120px]">
                                    <div class="font-mono text-[9.5px] uppercase tracking-[0.14em] text-ink-500 mb-1 lg:hidden">{{ __('Knowledge') }}</div>
                                    <div class="text-[13px] {{ ($a->training_sources_count ?? 0) > 0 ? 'text-ink-900' : 'text-ink-500' }}">
                                        {{ ($a->training_sources_count ?? 0) > 0 ? number_format($a->training_sources_count) . ' ' . __('entries') : __('No knowledge yet') }}
                                    </div>
                                </div>
                                <div class="lg:w-[100px]">
                                    <div class="font-mono text-[9.5px] uppercase tracking-[0.14em] text-ink-500 mb-1 lg:hidden">{{ __('Tone') }}</div>
                                    <div class="text-[13px] text-ink-700 capitalize">{{ $a->tone ?? __('helpful') }}</div>
                                </div>
                                <div class="lg:w-[130px]">
                                    <div class="font-mono text-[9.5px] uppercase tracking-[0.14em] text-ink-500 mb-1 lg:hidden">{{ __('Updated') }}</div>
                                    <div class="font-mono text-[12px] text-ink-900">{{ $a->updated_at->diffForHumans(short: true) }}</div>
                                    <div class="text-[10.5px] text-ink-500 font-mono">{{ $a->updated_at->format('M d, H:i') }}</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 lg:justify-end">
                                <a href="{{ url('/ai-training/' . $a->id . '/edit') }}"
                                    class="w-9 h-9 rounded-full hover:bg-wa-mint text-wa-deep inline-flex items-center justify-center"
                                    title="{{ __('Edit agent') }}">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path d="M11 2l3 3-8 8H3v-3l8-8z" />
                                    </svg>
                                </a>
                                <form method="POST" action="{{ url('/ai-training/' . $a->id . '/duplicate') }}" class="inline">@csrf
                                    <button type="submit"
                                        class="w-9 h-9 rounded-full hover:bg-paper-100 inline-flex items-center justify-center"
                                        title="{{ __('Duplicate agent') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                            <rect x="3" y="3" width="9" height="9" rx="1.5" />
                                            <rect x="6" y="6" width="7" height="7" rx="1.5" fill="white" />
                                        </svg>
                                    </button>
                                </form>
                                <button data-delete data-id="{{ $a->id }}" data-name="{{ $a->name }}" type="button"
                                    class="w-9 h-9 rounded-full hover:bg-accent-coral/15 text-accent-coral inline-flex items-center justify-center"
                                    title="{{ __('Delete agent') }}">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path d="M3 4h10M6 4V2.5h4V4M5 4l1 9h4l1-9" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div id="ait-empty" class="rounded-[20px] border border-dashed border-wa-green/40 bg-wa-mint/25 px-6 py-14 text-center">
                        <div class="mx-auto w-14 h-14 rounded-2xl bg-wa-deep text-paper-0 grid place-items-center mb-4">
                            <svg viewBox="0 0 24 24" class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M12 3l7 4v5c0 4.2-2.9 7.1-7 8.5C7.9 19.1 5 16.2 5 12V7l7-4Z" />
                            </svg>
                        </div>
                        <div class="font-serif text-[26px] leading-tight mb-1">{{ __('No smart agents yet') }}</div>
                        <p class="text-[13.5px] text-ink-600 mb-6 max-w-md mx-auto">
                            {{ __('Build one in five steps — name it, set the voice, pick a model, add a human handoff, then teach it your FAQs.') }}
                        </p>
                        <a href="{{ url('/ai-training/create') }}"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M8 3v10M3 8h10" />
                            </svg>
                            {{ __('Build smart agent') }}
                        </a>
                    </div>
                @endforelse
            </div>

            <div class="px-5 py-3 border-t border-paper-200 flex items-center justify-between text-[12px] text-ink-500">
                <div>{{ __('Showing') }} <span class="font-mono text-ink-900">{{ $assistants->count() }}</span>
                    {{ __('of') }}
                    <span class="font-mono text-ink-900">{{ method_exists($assistants, 'total') ? number_format($assistants->total()) : number_format($stats['all']) }}</span>
                </div>
                <a href="{{ url('/chatbot-widgets') }}" class="text-wa-deep font-medium hover:underline">{{ __('Attach to a chat widget') }} →</a>
            </div>
        </section>

        @if (method_exists($assistants, 'links'))
            <div>{{ $assistants->links() }}</div>
        @endif
    </main>

</x-layouts.user>
