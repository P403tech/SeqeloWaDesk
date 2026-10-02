<x-layouts.admin :title="__('Advanced Scaling')" admin-key="scaling" page="admin-scaling-index">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Advanced Scaling') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <form method="POST" action="{{ route('admin.settings.scaling.save') }}">
        @csrf
        <input type="hidden" name="queue_connection" value="redis">

        <main class="px-4 sm:px-6 lg:px-7 py-7 space-y-5">

            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin · Performance') }}</div>
                    <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[34px] lg:text-[40px] leading-[1.0]">{{ __('Advanced') }}
                        <span class="italic text-wa-deep">{{ __('scaling') }}</span>.</h1>
                    <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                        {{ __('For high-volume or multi-tenant servers. Runs the main time-critical jobs on a real OS cron and pushes campaign & broadcast sending to a Redis queue worker, instead of relying only on the Node heartbeat. Leave OFF on shared hosting — everything already works without it.') }}
                    </p>
                </div>
                <div class="flex items-center flex-wrap gap-2 shrink-0 pb-1">
                    <a href="{{ url('/admin/settings') }}"
                        class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All settings') }}</a>
                    <x-admin.flash inline />
                    <button type="submit"
                        class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save changes') }}</button>
                </div>
            </div>

            <section class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-5 items-start">

                {{-- LEFT: mode toggle + live status --}}
                <div class="space-y-5 min-w-0">

                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
                            <div>
                                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('mode') }}</div>
                                <h2 class="font-serif text-[25px] leading-tight mt-1">{{ __('Processing') }}</h2>
                            </div>
                            <span class="rounded-full {{ $enabled ? 'bg-wa-mint text-wa-deep border-wa-green/40' : 'bg-paper-100 text-ink-500 border-paper-200' }} border px-2.5 py-1 text-[11px] font-mono">{{ $enabled ? __('advanced') : __('standard') }}</span>
                        </div>
                        <div class="p-5">
                            <label class="rounded-2xl border border-paper-200 p-4 flex items-center justify-between gap-3">
                                <span>
                                    <span class="block text-[12.5px] font-semibold">{{ __('Enable Advanced Scaling') }}</span>
                                    <span class="block text-[10.5px] text-ink-500 mt-0.5">{{ __('ON = main sweeps run on cron + campaign/broadcast sends run on a Redis worker. OFF (default) = Node heartbeat + inline, zero setup.') }}</span>
                                </span>
                                <span class="toggle">
                                    <input type="hidden" name="scaling_mode" value="node">
                                    <input type="checkbox" name="scaling_mode" value="cron_queue" @checked(old('scaling_mode', $scaling_mode) === 'cron_queue')>
                                    <span class="track"></span><span class="thumb"></span>
                                </span>
                            </label>
                            <p class="text-[11px] text-ink-500 mt-3">{{ __('Real-time replies (AI / keyword auto-replies) always stay instant — they are never queued. Only bulk campaign/broadcast sending uses the worker.') }}</p>
                        </div>
                    </section>

                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('live status') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Health') }}</h2>
                        </div>
                        <div class="p-5">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="scaling-health">
                                @foreach ([
                                    'redis_reachable' => __('Redis'),
                                    'worker_alive'    => __('Queue worker'),
                                    'cron_alive'      => __('Cron scheduler'),
                                ] as $k => $label)
                                    <div class="rounded-2xl border border-paper-200 p-3.5">
                                        <div class="text-[11px] text-ink-500">{{ $label }}</div>
                                        <div class="flex items-center gap-1.5 mt-1.5">
                                            <span data-dot="{{ $k }}" class="w-2 h-2 rounded-full bg-ink-300"></span>
                                            <span data-h="{{ $k }}" class="text-[13px] font-semibold text-ink-500">{{ __('checking…') }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-[11px] text-ink-500 mt-3">{{ __('The worker and cron report in only after you enable scaling AND start them. Redis must be reachable before it can be turned on.') }}</p>
                        </div>
                    </section>
                </div>

                {{-- RIGHT: setup steps --}}
                <aside class="space-y-5">
                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('setup') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Turn it on') }}</h2>
                        </div>
                        <div class="p-5 space-y-4 text-[12.5px] text-ink-700">
                            <div>
                                <div class="font-semibold text-ink-900 mb-1">{{ __('1. Redis + PHP client') }}</div>
                                <p class="text-ink-600">{{ __('Install the Redis server + a PHP client (phpredis extension, or run):') }}</p>
                                <pre class="mt-1.5 bg-paper-50 border border-paper-200 rounded-lg p-2.5 text-[11px] overflow-x-auto">composer require predis/predis</pre>
                                <p class="text-ink-600 mt-1.5">{{ __('Then in') }} <code>.env</code>:</p>
                                <pre class="mt-1.5 bg-paper-50 border border-paper-200 rounded-lg p-2.5 text-[11px] overflow-x-auto">REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis</pre>
                            </div>
                            <div>
                                <div class="font-semibold text-ink-900 mb-1">{{ __('2. Cron (every minute)') }}</div>
                                <pre class="mt-1 bg-paper-50 border border-paper-200 rounded-lg p-2.5 text-[11px] overflow-x-auto">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
                            </div>
                            <div>
                                <div class="font-semibold text-ink-900 mb-1">{{ __('3. Queue worker (keep alive with pm2 / supervisor)') }}</div>
                                <pre class="mt-1 bg-paper-50 border border-paper-200 rounded-lg p-2.5 text-[11px] overflow-x-auto">php artisan queue:work redis --queue=bulk --tries=1 --timeout=3600</pre>
                                <p class="text-ink-600 mt-1.5">{{ __('Scale by running more workers (same command) pointed at the same Redis.') }}</p>
                            </div>
                            <div>
                                <div class="font-semibold text-ink-900 mb-1">{{ __('4. Enable + Save') }}</div>
                                <p class="text-ink-600">{{ __('Flip the toggle and Save. The status dots turn green within a minute.') }}</p>
                            </div>
                        </div>
                    </section>
                </aside>

            </section>
        </main>
    </form>

    <script>
        (function () {
            var url = @json(route('admin.settings.scaling.health'));
            function set(k, ok, okText, badText, extra) {
                var dot = document.querySelector('[data-dot="' + k + '"]');
                var txt = document.querySelector('[data-h="' + k + '"]');
                if (!dot || !txt) return;
                dot.className = 'w-2 h-2 rounded-full ' + (ok ? 'bg-wa-green' : 'bg-accent-coral');
                txt.className = 'text-[13px] font-semibold ' + (ok ? 'text-wa-deep' : 'text-accent-coral');
                txt.textContent = (ok ? okText : badText) + (extra || '');
            }
            function poll() {
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (h) {
                        set('redis_reachable', h.redis_reachable, @json(__('Reachable')), @json(__('Not reachable')));
                        set('worker_alive', h.worker_alive, @json(__('Running')), @json(__('Not seen')));
                        set('cron_alive', h.cron_alive, @json(__('Running')), @json(__('Not seen')),
                            (h.cron_last_run != null ? ' · ' + h.cron_last_run + 's' : ''));
                    })
                    .catch(function () {});
            }
            poll();
            setInterval(poll, 5000);
        })();
    </script>
</x-layouts.admin>
