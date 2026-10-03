<x-layouts.user :title="$platform === 'make' ? __('Make (Integromat) Integration') : __('Zapier Integration')" nav-key="more" page="user-integrations-automation">

    @php
        $isMake = ($platform === 'make');
        $appName = brand_name();
        $baseUrl = rtrim(config('app.url'), '/') . '/api/v1';
        $specUrl = url('/docs/api.json');
    @endphp

    <div class="border-b border-paper-200 bg-paper-0">
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-3 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ url('/integrations') }}"
                    class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center"
                    title="{{ __('Back to Integrations') }}"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none"
                        stroke="currentColor" stroke-width="1.6">
                        <path d="M10 4l-4 4 4 4" />
                    </svg></a>
                <div class="min-w-0">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                        {{ __('More / Integrations / ' . ($isMake ? 'Make' : 'Zapier')) }}</div>
                    <div class="font-serif text-[20px] leading-tight truncate">
                        {{ $isMake ? __('Make (Integromat)') : __('Zapier') }} &amp; {{ __('WhatsApp automation') }}
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ url('/integrations/automation?platform=zapier') }}"
                    class="px-3.5 py-1.5 rounded-full text-[12px] font-medium border transition {{ !$isMake ? 'bg-wa-deep text-paper-0 border-wa-deep' : 'bg-paper-0 text-ink-700 border-paper-200 hover:bg-paper-50' }}">
                    Zapier
                </a>
                <a href="{{ url('/integrations/automation?platform=make') }}"
                    class="px-3.5 py-1.5 rounded-full text-[12px] font-medium border transition {{ $isMake ? 'bg-wa-deep text-paper-0 border-wa-deep' : 'bg-paper-0 text-ink-700 border-paper-200 hover:bg-paper-50' }}">
                    Make (Integromat)
                </a>
                <a href="{{ $specUrl }}" target="_blank"
                    class="px-3.5 py-1.5 rounded-full text-[12px] font-medium bg-paper-0 text-ink-700 border border-paper-200 hover:bg-paper-50 inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 2v8M5 7l3 3 3-3M3 13h10"/></svg>
                    OpenAPI spec
                </a>
            </div>
        </div>
    </div>

    <main class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- Hero Platform Card --}}
        <section class="rounded-2xl p-6 sm:p-7 text-white shadow-soft relative overflow-hidden"
            style="background: {{ $isMake ? 'linear-gradient(135deg, #4A154B 0%, #6420AA 100%)' : 'linear-gradient(135deg, #C23800 0%, #FF4A00 100%)' }};">
            <div class="flex items-start justify-between gap-6 flex-wrap relative z-10">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[11px] font-mono bg-white/20 backdrop-blur-sm border border-white/30 uppercase tracking-wider text-white mb-3">
                        <span class="w-2 h-2 rounded-full bg-white"></span>
                        {{ $isMake ? __('Connect 1,500+ apps visually') : __('Connect 6,000+ apps with Zaps') }}
                    </div>
                    <h1 class="font-serif text-[28px] sm:text-[36px] leading-tight font-normal">
                        {{ __('Supercharge WhatsApp with') }} <em>{{ $isMake ? 'Make' : 'Zapier' }}</em>
                    </h1>
                    <p class="text-white/90 text-[13.5px] leading-relaxed mt-2.5">
                        {{ $isMake
                            ? __('Create instant multi-step automations. Receive new WhatsApp messages as triggers, or send WhatsApp notifications, catalogs, and order updates straight from your Make scenarios.')
                            : __('Build powerful two-way Zaps in minutes. Trigger automations when WhatsApp messages or orders arrive, and dispatch WhatsApp alerts or templates from Google Sheets, Stripe, CRM, and 6,000+ apps.') }}
                    </p>
                </div>
                <div class="flex flex-col sm:items-end gap-2.5 shrink-0">
                    <a href="{{ url('/webhooks/create') }}"
                        class="px-4 py-2.5 rounded-full bg-white text-ink-900 hover:bg-paper-50 font-semibold text-[13px] inline-flex items-center gap-2 shadow-sm transition">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10"/></svg>
                        {{ __('Add Outbound Webhook') }}
                    </a>
                    <a href="{{ url('/developers') }}"
                        class="px-4 py-2.5 rounded-full bg-white/20 hover:bg-white/30 border border-white/40 text-white font-medium text-[13px] inline-flex items-center gap-2 transition">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="6" cy="10" r="2.5"/><path d="M8 9l5-5M11 4l1.5 1.5"/></svg>
                        {{ __('Manage API Keys') }}
                    </a>
                </div>
            </div>
        </section>

        {{-- Connection Details Strip --}}
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-paper-0 border border-paper-200 rounded-xl p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-wider text-ink-500 mb-1">{{ __('REST API Base URL') }}</div>
                <div class="flex items-center gap-2">
                    <code id="api-base-code" class="text-[12px] font-mono text-ink-900 truncate flex-1">{{ $baseUrl }}</code>
                    <button type="button" onclick="navigator.clipboard.writeText('{{ $baseUrl }}'); this.textContent='✓'; setTimeout(()=>this.textContent='Copy',1200)"
                        class="px-2 py-1 text-[11px] font-medium border border-paper-200 rounded bg-paper-50 hover:bg-paper-100">Copy</button>
                </div>
            </div>

            <div class="bg-paper-0 border border-paper-200 rounded-xl p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-wider text-ink-500 mb-1">{{ __('Authentication Header') }}</div>
                <code class="text-[11.5px] font-mono text-wa-deep block truncate">Authorization: Bearer &lt;API_KEY&gt;</code>
            </div>

            <div class="bg-paper-0 border border-paper-200 rounded-xl p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-wider text-ink-500 mb-1">{{ __('OpenAPI Schema (JSON)') }}</div>
                <a href="{{ $specUrl }}" target="_blank" class="text-[12px] font-mono text-wa-deep hover:underline truncate block">
                    {{ url('/docs/api.json') }} ↗
                </a>
            </div>
        </section>

        {{-- How It Works --}}
        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1">{{ __('Two-way sync') }}</div>
            <h2 class="font-serif text-[24px] leading-tight mb-6">
                {{ __('How :platform + WhatsApp works', ['platform' => $isMake ? 'Make' : 'Zapier']) }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Part A: Triggers --}}
                <div class="border border-paper-200 rounded-xl p-5 bg-paper-50/50 flex flex-col">
                    <div class="flex items-center gap-2.5 mb-3">
                        <span class="w-6 h-6 rounded-full bg-wa-mint text-wa-deep font-mono text-[11px] font-bold grid place-items-center">1</span>
                        <h3 class="font-semibold text-[15px] text-ink-900">{{ __('Triggers (WhatsApp → ' . ($isMake ? 'Make' : 'Zapier') . ')') }}</h3>
                    </div>
                    <p class="text-[12.5px] text-ink-600 mb-4 leading-relaxed">
                        {{ __('When an event occurs in :app (such as a customer message or order), an Outbound Webhook instantly posts JSON data to your webhook URL.', ['app' => $appName]) }}
                    </p>

                    <div class="space-y-2 mt-auto">
                        <div class="text-[11px] font-mono uppercase text-ink-500 mb-1.5">{{ __('Popular trigger events:') }}</div>
                        <div class="p-2.5 rounded-lg bg-white border border-paper-200 flex items-center justify-between text-[12px]">
                            <span class="font-mono text-ink-800">conversation.received</span>
                            <span class="text-ink-500 text-[11px]">{{ __('New incoming WhatsApp message') }}</span>
                        </div>
                        <div class="p-2.5 rounded-lg bg-white border border-paper-200 flex items-center justify-between text-[12px]">
                            <span class="font-mono text-ink-800">order.created</span>
                            <span class="text-ink-500 text-[11px]">{{ __('New WhatsApp storefront order') }}</span>
                        </div>
                        <div class="p-2.5 rounded-lg bg-white border border-paper-200 flex items-center justify-between text-[12px]">
                            <span class="font-mono text-ink-800">conversation.resolved</span>
                            <span class="text-ink-500 text-[11px]">{{ __('Agent resolved customer chat') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Part B: Actions --}}
                <div class="border border-paper-200 rounded-xl p-5 bg-paper-50/50 flex flex-col">
                    <div class="flex items-center gap-2.5 mb-3">
                        <span class="w-6 h-6 rounded-full bg-wa-mint text-wa-deep font-mono text-[11px] font-bold grid place-items-center">2</span>
                        <h3 class="font-semibold text-[15px] text-ink-900">{{ __('Actions (' . ($isMake ? 'Make' : 'Zapier') . ' → WhatsApp)') }}</h3>
                    </div>
                    <p class="text-[12.5px] text-ink-600 mb-4 leading-relaxed">
                        {{ $isMake
                            ? __('In Make, add an "HTTP - Make a request" module. Send an authorized POST request to deliver a message or update contacts.')
                            : __('In Zapier, use "Webhooks by Zapier" (Custom Request / POST) or import our OpenAPI schema to execute actions automatically.') }}
                    </p>

                    <div class="space-y-2 mt-auto">
                        <div class="text-[11px] font-mono uppercase text-ink-500 mb-1.5">{{ __('Popular actions:') }}</div>
                        <div class="p-2.5 rounded-lg bg-white border border-paper-200 flex items-center justify-between text-[12px]">
                            <span class="font-mono text-ink-800">POST /api/v1/messages</span>
                            <span class="text-ink-500 text-[11px]">{{ __('Send WhatsApp text or media') }}</span>
                        </div>
                        <div class="p-2.5 rounded-lg bg-white border border-paper-200 flex items-center justify-between text-[12px]">
                            <span class="font-mono text-ink-800">POST /api/v1/broadcasts</span>
                            <span class="text-ink-500 text-[11px]">{{ __('Send WhatsApp template notification') }}</span>
                        </div>
                        <div class="p-2.5 rounded-lg bg-white border border-paper-200 flex items-center justify-between text-[12px]">
                            <span class="font-mono text-ink-800">POST /api/v1/contacts</span>
                            <span class="text-ink-500 text-[11px]">{{ __('Add or sync contact info') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Copyable Action Templates --}}
        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            <div class="px-6 py-4 border-b border-paper-200">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Copy & Paste Setup') }}</div>
                <h2 class="font-serif text-[22px] leading-tight mt-0.5">{{ __('Send WhatsApp Message action snippet') }}</h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="bg-ink-950 text-paper-0 rounded-xl p-4 font-mono text-[12px] overflow-x-auto relative group">
                    <pre class="leading-relaxed">curl -X POST "{{ $baseUrl }}/messages" \
  -H "Authorization: Bearer &lt;YOUR_API_KEY&gt;" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "+14155552671",
    "message": "Hello from {{ $isMake ? 'Make' : 'Zapier' }}! Your order #1042 has been confirmed."
  }'</pre>
                </div>
                <p class="text-[12px] text-ink-500">
                    {{ __('Replace +14155552671 with the recipient dynamic variable from your scenario or Zap.') }}
                </p>
            </div>
        </section>

        {{-- Workspace Webhooks List --}}
        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            <div class="px-6 py-4 border-b border-paper-200 flex items-center justify-between">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Live Outbound Webhooks') }}</div>
                    <h2 class="font-serif text-[22px] leading-tight mt-0.5">{{ __('Configured endpoints') }}</h2>
                </div>
                <a href="{{ url('/webhooks/create') }}"
                    class="px-3.5 py-1.5 rounded-full bg-wa-deep text-paper-0 hover:bg-wa-teal text-[12px] font-semibold inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10"/></svg>
                    New webhook
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="bg-paper-50 text-left font-mono text-[10.5px] uppercase text-ink-500">
                        <tr>
                            <th class="px-5 py-2.5">{{ __('Name / URL') }}</th>
                            <th class="px-5 py-2.5">{{ __('Events') }}</th>
                            <th class="px-5 py-2.5">{{ __('Deliveries') }}</th>
                            <th class="px-5 py-2.5">{{ __('Status') }}</th>
                            <th class="px-5 py-2.5 text-right">{{ __('Last Fired') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-100">
                        @forelse ($webhooks as $hook)
                            <tr class="hover:bg-paper-50">
                                <td class="px-5 py-3">
                                    <div class="font-semibold text-ink-900">{{ $hook->name ?: 'Webhook' }}</div>
                                    <div class="font-mono text-[11px] text-ink-500 truncate max-w-sm">{{ $hook->url }}</div>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="font-mono text-[11px] text-ink-700">
                                        {{ is_array($hook->events) ? implode(', ', $hook->events) : $hook->events }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 font-mono text-[11.5px]">
                                    <span class="text-wa-deep font-semibold">{{ $hook->fired_count }}</span> fired
                                    @if ($hook->failed_count > 0)
                                        · <span class="text-accent-coral">{{ $hook->failed_count }} failed</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="font-mono text-[10px] uppercase px-2 py-0.5 rounded-full {{ $hook->is_active ? 'bg-wa-mint text-wa-deep border border-wa-green/40' : 'bg-paper-100 text-ink-500' }}">
                                        {{ $hook->is_active ? 'Active' : 'Paused' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-[11px] text-ink-500">
                                    {{ $hook->last_fired_at ? \Illuminate\Support\Carbon::parse($hook->last_fired_at)->diffForHumans() : __('Never') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-ink-500 text-[12.5px]">
                                    {{ __('No webhooks created yet. Click "New webhook" above or paste your :platform webhook URL.', ['platform' => $isMake ? 'Make' : 'Zapier']) }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

    </main>

</x-layouts.user>
