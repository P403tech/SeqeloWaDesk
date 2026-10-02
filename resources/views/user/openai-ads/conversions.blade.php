<x-layouts.user :title="__('Conversions')" nav-key="openai-ads" page="user-openai-ads-conversions">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.openai-ads._rail', ['current' => 'conversions', 'account' => $account])

            <section class="space-y-5 max-w-3xl">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('OpenAI Ads') }}</div>
                    <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">{{ __('Conversion') }} <span class="italic text-wa-deep">{{ __('setup') }}</span></h1>
                    <p class="text-[13px] text-ink-600 mt-2">{{ __('Create a pixel, a server-side API key, then define the conversion events you want to optimise toward.') }}</p>
                </div>

                @if (session('status'))
                    <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $errors->first() }}</div>
                @endif
                @if (session('new_api_key'))
                    <div class="bg-paper-0 border-2 border-wa-deep rounded-xl px-4 py-3">
                        <div class="text-[11px] font-semibold text-ink-700">{{ __('Your new Conversions API key — copy it now, it will not be shown again:') }}</div>
                        <code class="block mt-1 font-mono text-[12px] text-wa-deep break-all select-all">{{ session('new_api_key') }}</code>
                    </div>
                @endif

                {{-- Pixels --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-4">
                    <h2 class="font-serif text-[18px]">{{ __('Pixels') }}</h2>
                    @forelse ($pixels as $p)
                        <div class="flex items-center justify-between gap-3 border-b border-paper-50 pb-2">
                            <div>
                                <div class="text-[12.5px] font-semibold text-ink-900">{{ $p->name }}</div>
                                <div class="text-[11px] text-ink-500 font-mono">{{ __('pixel_id') }}: {{ $p->pixel_id ?: '—' }} · {{ $p->remote_id }}</div>
                            </div>
                            <span class="text-[10.5px] font-mono px-1.5 py-0.5 rounded-full bg-wa-mint text-wa-deep">{{ $p->client_type }}</span>
                        </div>
                    @empty
                        <p class="text-[12px] text-ink-500">{{ __('No pixels yet.') }}</p>
                    @endforelse
                    <form method="POST" action="{{ route('user.openai-ads.conversions.pixels') }}" class="flex items-end gap-2 flex-wrap">
                        @csrf
                        <label class="flex-1 min-w-[200px]">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('New pixel name') }}</span>
                            <input type="text" name="name" required minlength="3" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <button type="submit" class="px-3.5 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Create pixel') }}</button>
                    </form>
                </section>

                {{-- Conversions API keys --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-4">
                    <h2 class="font-serif text-[18px]">{{ __('Conversions API keys') }}</h2>
                    <p class="text-[11.5px] text-ink-500">{{ __('For sending server-side conversion events. The full key is shown only once when created.') }}</p>
                    @forelse ($apiKeys as $k)
                        <div class="flex items-center justify-between gap-3 border-b border-paper-50 pb-2">
                            <span class="text-[12.5px] font-semibold text-ink-900">{{ $k->name }}</span>
                            <span class="text-[11px] text-ink-500 font-mono">{{ $k->masked ?: '—' }}</span>
                        </div>
                    @empty
                        <p class="text-[12px] text-ink-500">{{ __('No API keys yet.') }}</p>
                    @endforelse
                    <form method="POST" action="{{ route('user.openai-ads.conversions.api-keys') }}" class="flex items-end gap-2 flex-wrap">
                        @csrf
                        <label class="flex-1 min-w-[200px]">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('New key name') }}</span>
                            <input type="text" name="name" required minlength="3" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <button type="submit" class="px-3.5 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Create key') }}</button>
                    </form>
                </section>

                {{-- Event settings --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-4">
                    <h2 class="font-serif text-[18px]">{{ __('Conversion events') }}</h2>
                    <p class="text-[11.5px] text-ink-500">{{ __('The event you optimise toward. Use its ID (ces_…) when creating a Conversions campaign.') }}</p>
                    @forelse ($eventSettings as $e)
                        <div class="flex items-center justify-between gap-3 border-b border-paper-50 pb-2">
                            <div>
                                <div class="text-[12.5px] font-semibold text-ink-900">{{ $e->name }}</div>
                                <div class="text-[11px] text-ink-500 font-mono">{{ $e->event_type }}{{ $e->custom_event_name ? ' · '.$e->custom_event_name : '' }} · {{ $e->attribution_window_days }}d</div>
                            </div>
                            <code class="text-[11px] font-mono text-wa-deep select-all">{{ $e->remote_id }}</code>
                        </div>
                    @empty
                        <p class="text-[12px] text-ink-500">{{ __('No conversion events yet.') }}</p>
                    @endforelse

                    @if ($pixels->isEmpty())
                        <p class="text-[11.5px] text-[#A1431F]">{{ __('Create a pixel first — an event needs a source.') }}</p>
                    @else
                        <form method="POST" action="{{ route('user.openai-ads.conversions.events') }}" class="space-y-3">
                            @csrf
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Event name') }}</span>
                                    <input type="text" name="name" required minlength="3" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Event type') }}</span>
                                    <select name="event_type" required class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                                        @foreach ([
                                            'order_created', 'checkout_started', 'items_added', 'contents_viewed',
                                            'page_viewed', 'lead_created', 'registration_completed', 'subscription_created',
                                            'trial_started', 'appointment_scheduled', 'app_installed', 'app_opened', 'custom',
                                        ] as $evt)
                                            <option value="{{ $evt }}" @selected($evt === 'order_created')>{{ $evt }}</option>
                                        @endforeach
                                    </select>
                                    <span class="text-[10px] text-ink-400 leading-snug">{{ __('The OpenAI Ads supported events. Pick "custom" to name your own.') }}</span>
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Custom event name') }} <span class="text-ink-400">({{ __('if type = custom') }})</span></span>
                                    <input type="text" name="custom_event_name" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Attribution window (days)') }}</span>
                                    <input type="number" name="attribution_window_days" value="30" min="1" max="90" required class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                                </label>
                                <label class="block sm:col-span-2">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Source pixel') }}</span>
                                    <select name="pixel_local_id" required class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                        @foreach ($pixels as $p)
                                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->remote_id }})</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <button type="submit" class="px-3.5 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Create event') }}</button>
                        </form>
                    @endif
                </section>
            </section>
        </div>
    </main>
</x-layouts.user>
