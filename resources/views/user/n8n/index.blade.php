<x-layouts.user :title="__('n8n')" nav-key="more" page="user-n8n-index">

    @php
        $isConnected = $connection && $connection->status;
        $baseUrl = $baseUrl ?? rtrim((string) config('app.url'), '/') . '/api/v1';
        $selected = $selected ?? [];
    @endphp

    <!-- Sub header -->
    <div class="border-b border-paper-200 bg-paper-0">
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-3 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ url('/more') }}"
                    class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center"
                    title="{{ __('Back to More') }}"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none"
                        stroke="currentColor" stroke-width="1.6">
                        <path d="M10 4l-4 4 4 4" />
                    </svg></a>
                <div class="min-w-0">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                        {{ __('More / Automation') }}</div>
                    <div class="font-serif text-[20px] leading-tight truncate"><span
                            class="italic text-wa-deep">n8n</span> {{ __('automation') }}</div>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" id="n8n-help-open"
                    class="px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                        <circle cx="8" cy="8" r="6" />
                        <path d="M6.3 6.2a1.8 1.8 0 1 1 2.6 1.7c-.5.3-.9.6-.9 1.1M8 11.5h.01" />
                    </svg>
                    {{ __('How to use') }}
                </button>
                @if ($isConnected)
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-wa-mint text-wa-deep border border-wa-green/40 font-mono">
                        <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                @else
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-paper-50 text-ink-700 border border-paper-200 font-mono">
                        <span class="w-1.5 h-1.5 rounded-full bg-paper-300"></span>{{ __('Not connected') }}</span>
                @endif
                <a href="{{ url('/webhooks') }}"
                    class="px-3.5 py-1.5 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Webhooks') }}</a>
                <a href="{{ url('/developers') }}"
                    class="px-3.5 py-1.5 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('API keys') }}</a>
            </div>
        </div>
    </div>

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-6 space-y-6">

        @if (session('status'))
            <div
                class="bg-wa-mint/60 border border-wa-green/40 rounded-lg px-4 py-2.5 text-[12.5px] text-wa-deep inline-flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path d="M3.5 8.5l3 3 6-7" />
                </svg>{{ session('status') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2.5 text-[12.5px] text-[#A1431F]">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- How it works -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-paper-0 border border-paper-200 rounded-[14px] p-5 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('How it works') }}</div>
                <h3 class="font-serif text-[20px] leading-tight mt-0.5 mb-3">
                    {{ __('Connect your own n8n — automate with 400+ apps') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-paper-200 bg-paper-50 p-4">
                        <div class="flex items-center gap-2 text-wa-deep">
                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M3 8h8M8 4l4 4-4 4" /></svg>
                            <span class="font-semibold text-[13px] text-ink-900">{{ __('Triggers') }}</span>
                        </div>
                        <p class="mt-1.5 text-[12px] text-ink-600 leading-relaxed">
                            {{ __(':brand sends events (new message, new contact, campaign updates) to your n8n workflow in real time.', ['brand' => brand_name()]) }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-paper-200 bg-paper-50 p-4">
                        <div class="flex items-center gap-2 text-wa-deep">
                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M13 8H5m4-4L5 8l4 4" /></svg>
                            <span class="font-semibold text-[13px] text-ink-900">{{ __('Actions') }}</span>
                        </div>
                        <p class="mt-1.5 text-[12px] text-ink-600 leading-relaxed">
                            {{ __('Your n8n workflow calls back into :brand over the REST API to send messages, add contacts, start campaigns and more.', ['brand' => brand_name()]) }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="bg-wa-deep rounded-[14px] p-5 shadow-soft text-paper-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-paper-0/60">{{ __('Bring your own n8n') }}
                </div>
                <div class="font-serif text-[22px] leading-tight mt-1">{{ __('Self-hosted or n8n Cloud') }}</div>
                <p class="mt-2 text-[12px] text-paper-0/80 leading-relaxed">
                    {{ __('n8n is a separate product with its own licence. Connect the n8n instance you run — nothing to install here.') }}
                </p>
                <a href="https://n8n.io" target="_blank" rel="noopener"
                    class="mt-4 inline-flex items-center gap-2 rounded-full bg-paper-0 text-wa-deep px-4 py-2 text-[12px] font-semibold">
                    {{ __('Get n8n') }}
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M6 3l5 5-5 5" /></svg>
                </a>
            </div>
        </section>

        <!-- Connection form -->
        <section class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
            <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Step 1') }}</div>
                    <h2 class="font-serif text-[18px] leading-tight">{{ __('Send events to n8n') }}</h2>
                </div>
                @if ($isConnected)
                    <div class="text-[11px] text-ink-500 font-mono">
                        {{ __('Last fired') }}:
                        {{ $connection->last_fired_at ? $connection->last_fired_at->diffForHumans() : __('never') }}
                        @if ($connection->last_status_code)
                            &middot; HTTP {{ $connection->last_status_code }}
                        @endif
                    </div>
                @endif
            </div>

            <form method="POST" action="{{ route('user.n8n.save') }}" class="p-5 space-y-5">
                @csrf
                <div>
                    <label for="n8n_url"
                        class="block font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1.5">{{ __('Your n8n Webhook node URL') }}</label>
                    <input id="n8n_url" name="n8n_url" type="url" required
                        value="{{ old('n8n_url', $connection->webhook_url ?? '') }}"
                        placeholder="https://your-n8n.example.com/webhook/xxxxxxxx"
                        class="w-full px-3 py-2.5 border border-paper-200 rounded-lg bg-white text-[13px] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
                    <p class="mt-1.5 text-[11.5px] text-ink-500">
                        {{ __('In n8n, add a Webhook node and copy its Production URL here.') }}</p>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label
                            class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Events to send') }}</label>
                        <label class="inline-flex items-center gap-1.5 text-[11.5px] text-ink-600 cursor-pointer">
                            <input type="checkbox" id="n8n-select-all"
                                class="rounded border-paper-200 text-wa-deep focus:ring-wa-deep">
                            {{ __('Select all') }}
                        </label>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach ($events as $key => $label)
                            <label
                                class="flex items-start gap-2 rounded-lg border border-paper-200 bg-paper-50 px-3 py-2 hover:border-wa-deep/40 cursor-pointer">
                                <input type="checkbox" name="events[]" value="{{ $key }}"
                                    @checked(in_array($key, $selected, true))
                                    class="mt-0.5 rounded border-paper-200 text-wa-deep focus:ring-wa-deep n8n-event">
                                <span class="min-w-0">
                                    <span class="block text-[12.5px] text-ink-900 leading-tight">{{ $label }}</span>
                                    <span class="block font-mono text-[10px] text-ink-500 truncate">{{ $key }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap pt-1">
                    <button type="submit"
                        class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold inline-flex items-center gap-1.5">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M3.5 8.5l3 3 6-7" /></svg>
                        {{ $isConnected ? __('Save changes') : __('Connect n8n') }}
                    </button>
                    @if ($isConnected)
                        <button type="button" id="n8n-test"
                            class="px-4 py-2 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium inline-flex items-center gap-1.5">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M3 11h10M8 4v9M5 7l3-3 3 3" /></svg>
                            {{ __('Send test event') }}
                        </button>
                        <span id="n8n-test-result" class="text-[11.5px] font-mono text-ink-500"></span>
                    @endif
                </div>
            </form>

            @if ($isConnected)
                <div class="px-5 py-3 bg-paper-50 border-t border-paper-200 flex items-center justify-between gap-3 flex-wrap">
                    <span class="text-[11.5px] text-ink-500">{{ __('Stop sending events to this n8n instance.') }}</span>
                    <form method="POST" action="{{ route('user.n8n.disconnect') }}"
                        data-confirm-form data-confirm-title="{{ __('Disconnect n8n?') }}"
                        data-confirm-message="{{ __('Events will stop being sent to your n8n workflow. You can reconnect any time.') }}"
                        data-confirm-accept="{{ __('Disconnect') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="px-3.5 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:border-accent-coral hover:text-accent-coral text-[12px] font-medium">{{ __('Disconnect') }}</button>
                    </form>
                </div>
            @endif
        </section>

        <!-- API access -->
        <section class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Step 2') }}</div>
            <h2 class="font-serif text-[18px] leading-tight">{{ __('Let n8n act back on :brand', ['brand' => brand_name()]) }}</h2>
            <p class="mt-1 text-[12.5px] text-ink-500 leading-snug">
                {{ __('Add an HTTP Request node in n8n (or the credential) using this base URL and your API key.') }}</p>

            <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1.5">{{ __('Base URL') }}</div>
                    <div class="flex items-center gap-1.5">
                        <code id="n8n-base-url"
                            class="flex-1 min-w-0 truncate px-2.5 py-1.5 rounded-lg bg-paper-50 border border-paper-200 font-mono text-[11.5px] text-ink-900">{{ $baseUrl }}</code>
                        <button type="button" data-copy="n8n-base-url"
                            class="w-8 h-8 shrink-0 rounded-lg border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-600"
                            title="{{ __('Copy') }}">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                <rect x="5" y="5" width="8" height="9" rx="1.5" />
                                <path d="M3 11V3a1 1 0 0 1 1-1h6" /></svg>
                        </button>
                    </div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mt-3 mb-1.5">{{ __('Auth header') }}</div>
                    <code
                        class="block px-2.5 py-1.5 rounded-lg bg-ink-900 font-mono text-[11px] text-wa-mint overflow-x-auto whitespace-nowrap">Authorization: Bearer &lt;key&gt;</code>
                </div>
                <div class="rounded-xl border border-paper-200 bg-paper-50 p-4 flex flex-col">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1">{{ __('API key') }}</div>
                    @if ($hasApiKey)
                        <div class="inline-flex items-center gap-1.5 text-[12px] text-wa-deep font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>
                            {{ __('Key active') }} <code class="font-mono text-[11px] text-ink-600">{{ $apiKeyPrefix }}&hellip;</code>
                        </div>
                        <p class="mt-1.5 text-[11.5px] text-ink-500 leading-snug flex-1">
                            {{ __('Use this workspace key in your n8n credential. Manage or rotate it on the API keys page.') }}</p>
                    @else
                        <div class="inline-flex items-center gap-1.5 text-[12px] text-ink-700 font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-paper-300"></span>{{ __('No key yet') }}
                        </div>
                        <p class="mt-1.5 text-[11.5px] text-ink-500 leading-snug flex-1">
                            {{ __('Create a workspace API key so n8n can call back. It is shown once on creation.') }}</p>
                    @endif
                    <a href="{{ url('/developers') }}"
                        class="mt-3 inline-flex items-center gap-1.5 self-start px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">
                        {{ $hasApiKey ? __('Manage keys') : __('Create API key') }}
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M3 8h10M9 4l4 4-4 4" /></svg>
                    </a>
                </div>
            </div>

            <!-- Popular actions + ready recipe -->
            <div class="mt-5 pt-5 border-t border-paper-200">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Popular actions') }}</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ([
                        ['POST', '/messages', __('Send a message')],
                        ['POST', '/contacts', __('Create a contact')],
                        ['POST', '/templates/{id}/send', __('Send a template')],
                        ['POST', '/flows/{id}/enroll', __('Enroll in a flow')],
                        ['POST', '/broadcasts', __('Start a broadcast')],
                        ['POST', '/campaigns', __('Start a campaign')],
                    ] as $act)
                        <div class="flex items-center gap-2.5 rounded-lg border border-paper-200 bg-paper-50 px-3 py-2 min-w-0">
                            <span class="font-mono text-[10px] font-semibold px-1.5 py-0.5 rounded bg-wa-deep text-paper-0 shrink-0">{{ $act[0] }}</span>
                            <code class="font-mono text-[11px] text-ink-700 truncate">/api/v1{{ $act[1] }}</code>
                            <span class="text-[11.5px] text-ink-500 ml-auto truncate shrink-0">{{ $act[2] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Recipe — n8n HTTP Request node') }}</div>
                        <button type="button" data-copy="n8n-recipe"
                            class="text-[11px] text-wa-deep font-semibold hover:underline inline-flex items-center gap-1">
                            <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6">
                                <rect x="5" y="5" width="8" height="9" rx="1.5" />
                                <path d="M3 11V3a1 1 0 0 1 1-1h6" />
                            </svg>
                            {{ __('Copy body') }}
                        </button>
                    </div>
                    <div class="rounded-xl bg-ink-900 p-4 overflow-x-auto">
                        <div class="font-mono text-[11px] text-paper-0/60 space-y-0.5">
                            <div><span class="text-wa-mint">Method</span> &nbsp;POST</div>
                            <div><span class="text-wa-mint">URL</span> &nbsp;&nbsp;&nbsp;&nbsp;{{ $baseUrl }}/messages</div>
                            <div><span class="text-wa-mint">Header</span> Authorization: Bearer &lt;your key&gt;</div>
                            <div><span class="text-wa-mint">Header</span> Content-Type: application/json</div>
                            <div class="mt-1"><span class="text-wa-mint">Body (JSON)</span></div>
                        </div>
                        <pre id="n8n-recipe" class="mt-1 font-mono text-[11.5px] text-[#E8F0EE] leading-relaxed whitespace-pre">{
  "to": "919812345678",
  "type": "text",
  "text": "Hello from your n8n workflow"
}</pre>
                    </div>
                    <p class="mt-1.5 text-[11.5px] text-ink-500">
                        {{ __('Add an HTTP Request node in n8n with these settings, then map the number and text from your trigger.') }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Open n8n inside the app (embed the buyer's OWN editor) -->
        <section class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div class="min-w-0">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Optional') }}</div>
                    <h2 class="font-serif text-[18px] leading-tight">{{ __('Open n8n inside :brand', ['brand' => brand_name()]) }}</h2>
                    <p class="mt-1 text-[12.5px] text-ink-500 leading-snug">
                        {{ __('Embed your own n8n editor here so you can build flows without leaving the app.') }}</p>
                </div>
                @if ($editorUrl)
                    <a href="{{ $editorUrl }}" target="_blank" rel="noopener"
                        class="px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold inline-flex items-center gap-1.5 shrink-0">
                        {{ __('Open in new tab') }}
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M6 3h7v7M13 3 7 9M11 9v4H3V5h4" />
                        </svg>
                    </a>
                @endif
            </div>

            <div class="mt-3 flex items-end gap-2 flex-wrap">
                <form method="POST" action="{{ route('user.n8n.embed') }}" class="flex-1 min-w-[240px] flex items-end gap-2">
                    @csrf
                    <div class="flex-1 min-w-0">
                        <label for="editor_url"
                            class="block font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1.5">{{ __('Your n8n editor URL') }}</label>
                        <input id="editor_url" name="editor_url" type="url" value="{{ $editorUrl }}"
                            placeholder="https://your-n8n.example.com"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-white text-[13px] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
                    </div>
                    <button type="submit"
                        class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold shrink-0">{{ $editorUrl ? __('Update') : __('Save') }}</button>
                </form>
                @if ($editorUrl)
                    <form method="POST" action="{{ route('user.n8n.embed') }}">
                        @csrf
                        <input type="hidden" name="editor_url" value="">
                        <button type="submit"
                            class="px-4 py-2 border border-paper-200 rounded-full bg-paper-0 hover:border-accent-coral hover:text-accent-coral text-[12.5px] font-medium">{{ __('Remove') }}</button>
                    </form>
                @endif
            </div>

            @if ($editorUrl)
                <div class="mt-4 rounded-xl border border-paper-200 overflow-hidden bg-paper-50">
                    <iframe src="{{ $editorUrl }}" title="n8n" class="w-full h-[600px] border-0"
                        referrerpolicy="no-referrer"></iframe>
                </div>
                <p class="mt-2 text-[11.5px] text-ink-500 leading-relaxed">
                    {{ __('If the panel stays blank, your n8n blocks embedding — use "Open in new tab". This embeds your OWN single n8n; hosting n8n for your customers needs an n8n Embed licence.') }}
                </p>
            @endif
        </section>

        <!-- Setup steps -->
        <section class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <h2 class="font-serif text-[18px] leading-tight">{{ __('Setup checklist') }}</h2>
            <ol class="mt-3 space-y-3">
                @foreach ([
                    __('Run your own n8n (self-hosted or n8n Cloud).'),
                    __('In n8n, add a Webhook node and copy its Production URL.'),
                    __('Paste that URL above, pick the events you want, and save.'),
                    __('For actions, add an HTTP Request node in n8n using the base URL + API key above.'),
                ] as $i => $step)
                    <li class="flex items-start gap-3">
                        <span
                            class="w-6 h-6 shrink-0 rounded-full bg-wa-mint text-wa-deep grid place-items-center font-mono text-[11px] font-semibold">{{ $i + 1 }}</span>
                        <span class="text-[12.5px] text-ink-700 leading-relaxed pt-0.5">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <!-- Starter workflows -->
        <section class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                        {{ __('Starter workflows') }}</div>
                    <h2 class="font-serif text-[18px] leading-tight">{{ __('Import a ready-made workflow') }}</h2>
                </div>
                <span class="text-[11.5px] text-ink-500 font-mono">{{ __('n8n → Import from File') }}</span>
            </div>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach ([
                    ['auto-reply-inbound.json', __('Auto-reply to messages'), __('Reply automatically to every inbound message.')],
                    ['welcome-new-contact.json', __('Welcome new contacts'), __('Send a welcome message when a contact is created.')],
                    ['enroll-new-contact-in-flow.json', __('Enroll contacts in a flow'), __('Add every new contact to an onboarding flow.')],
                ] as $tpl)
                    <a href="{{ url('n8n-templates/' . $tpl[0]) }}" download
                        class="group rounded-xl border border-paper-200 bg-paper-50 p-4 hover:border-wa-deep hover:shadow-soft transition flex flex-col">
                        <span class="w-9 h-9 rounded-lg bg-wa-mint text-wa-deep grid place-items-center">
                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M8 2v8M5 7l3 3 3-3M3 13h10" />
                            </svg>
                        </span>
                        <span class="mt-3 text-[13px] font-semibold text-ink-900 leading-tight">{{ $tpl[1] }}</span>
                        <span class="mt-1 text-[11.5px] text-ink-500 leading-snug flex-1">{{ $tpl[2] }}</span>
                        <span class="mt-2 text-[11px] text-wa-deep font-semibold group-hover:underline">{{ __('Download .json') }}</span>
                    </a>
                @endforeach
            </div>
            <p class="mt-3 text-[11.5px] text-ink-500 leading-relaxed">
                {{ __('These use the free WaDesk node pack — install it in n8n via Settings → Community nodes (search "n8n-nodes-wadesk"), then add your API credential.') }}
            </p>
        </section>

    </main>

    <!-- How-to-use guide -->
    <div id="n8n-help" class="hidden fixed inset-0 z-[80]" role="dialog" aria-modal="true"
        aria-labelledby="n8n-help-title">
        <div class="absolute inset-0 bg-ink-950/50" data-n8n-help-close></div>
        <div class="absolute inset-0 flex items-start sm:items-center justify-center p-4 overflow-y-auto">
            <div class="relative bg-paper-0 rounded-2xl shadow-soft w-full max-w-2xl my-6 max-h-[88vh] overflow-y-auto">
                <div
                    class="sticky top-0 bg-paper-0 border-b border-paper-200 px-5 py-4 flex items-center justify-between gap-3 rounded-t-2xl">
                    <div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Guide') }}</div>
                        <h2 id="n8n-help-title" class="font-serif text-[20px] leading-tight">
                            {{ __('How to use n8n') }}</h2>
                    </div>
                    <button type="button" data-n8n-help-close
                        class="w-8 h-8 rounded-full border border-paper-200 hover:bg-paper-50 grid place-items-center text-ink-600"
                        aria-label="{{ __('Close') }}">
                        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M4 4l8 8M12 4l-8 8" />
                        </svg>
                    </button>
                </div>

                <div class="px-5 py-5 space-y-4">
                    <p class="text-[13px] text-ink-600 leading-relaxed">
                        {{ __('n8n lets you automate :brand with 400+ apps. You connect your own n8n (free at n8n.io, or self-hosted). Follow these steps.', ['brand' => brand_name()]) }}
                    </p>

                    @php
                        $guide = [
                            ['A', __('Connect n8n (send events out)'), [
                                __('Get n8n — sign up free at n8n.io, or self-host it.'),
                                __('In n8n, create a workflow and add a "WaDesk Trigger" node (or a plain Webhook node); copy its URL.'),
                                __('On this page under "Send events to n8n", paste that URL, tick the events you want, and press Connect.'),
                                __('Press "Send test event" to confirm n8n receives it.'),
                            ]],
                            ['B', __('Let n8n act back (send messages, add contacts)'), [
                                __('Open the Developers page and create an API key; copy it.'),
                                __('In n8n, install the node pack (Settings → Community nodes → search n8n-nodes-wadesk), then add a WaDesk credential using the Base URL + key shown here in "Step 2".'),
                                __('n8n can now send messages, create contacts, send templates and enroll flows. A ready copy-paste recipe is in Step 2.'),
                            ]],
                            ['C', __('Fastest — use a ready template'), [
                                __('Under "Starter workflows" below, download one (e.g. Auto-reply to messages).'),
                                __('In n8n choose Import from File, pick it, set your WaDesk credential, then Activate.'),
                            ]],
                            ['D', __('Optional — open n8n inside the app'), [
                                __('Paste your n8n address in "Open n8n inside :brand" to build flows without leaving the app.', ['brand' => brand_name()]),
                            ]],
                        ];
                    @endphp

                    @foreach ($guide as $sec)
                        <div class="rounded-xl border border-paper-200 bg-paper-50 p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span
                                    class="w-6 h-6 rounded-full bg-wa-deep text-paper-0 grid place-items-center font-mono text-[11px] font-semibold shrink-0">{{ $sec[0] }}</span>
                                <h3 class="font-semibold text-[13.5px] text-ink-900">{{ $sec[1] }}</h3>
                            </div>
                            <ol class="space-y-1.5">
                                @foreach ($sec[2] as $n => $step)
                                    <li class="flex gap-2 text-[12.5px] text-ink-700 leading-relaxed">
                                        <span class="text-wa-deep font-semibold shrink-0">{{ $n + 1 }}.</span>
                                        <span>{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach

                    <div class="rounded-xl border border-wa-green/30 bg-wa-mint/40 p-4 text-[12px] text-ink-700 leading-relaxed">
                        {{ __('Tip: n8n is a separate product with its own licence. You connect your own n8n — nothing to install inside the app.') }}
                    </div>
                </div>

                <div
                    class="sticky bottom-0 bg-paper-0 border-t border-paper-200 px-5 py-3 flex items-center justify-between gap-3 rounded-b-2xl">
                    <a href="https://n8n.io" target="_blank" rel="noopener"
                        class="text-[12px] font-semibold text-wa-deep hover:underline inline-flex items-center gap-1.5">
                        {{ __('Get n8n') }}
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M3 8h10M9 4l4 4-4 4" />
                        </svg>
                    </a>
                    <button type="button" data-n8n-help-close
                        class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Got it') }}</button>
                </div>
            </div>
        </div>
    </div>

</x-layouts.user>
