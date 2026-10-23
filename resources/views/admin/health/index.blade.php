{{--
 /admin/health — System Health monitor. One screen for "is everything up?":
 database, cache, queue, the Node WhatsApp bridge, media storage, the error
 log, every connected engine, and host vitals. Polls ?format=json to stay live.
--}}
<x-layouts.admin :title="__('System Health')" admin-key="health" page="admin-health">

    @php
        $tone = [
            'up'   => ['dot' => 'bg-wa-green', 'text' => 'text-wa-deep', 'bg' => 'bg-wa-mint', 'ring' => 'ring-wa-green/15', 'word' => __('Operational')],
            'warn' => ['dot' => 'bg-amber-500',   'text' => 'text-amber-700',   'bg' => 'bg-amber-50',   'ring' => 'ring-amber-500/15',   'word' => __('Degraded')],
            'down' => ['dot' => 'bg-red-500',      'text' => 'text-red-700',     'bg' => 'bg-red-50',     'ring' => 'ring-red-500/15',     'word' => __('Down')],
            'idle' => ['dot' => 'bg-ink-300',      'text' => 'text-ink-500',     'bg' => 'bg-paper-100',  'ring' => 'ring-ink-500/10',     'word' => __('Idle')],
        ];
        $T = fn($s) => $tone[$s] ?? $tone['idle'];
        $ov = $T($overall);
    @endphp

    {{-- Header --}}
    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('System Health') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-7 py-5 space-y-5">

        {{-- Overall status banner --}}
        <div class="rounded-2xl border border-paper-200 shadow-card overflow-hidden">
            <div class="flex items-center gap-4 px-5 py-4 {{ $ov['bg'] }} ring-1 {{ $ov['ring'] }}">
                <span class="relative flex h-3.5 w-3.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $ov['dot'] }} opacity-60"></span>
                    <span class="relative inline-flex rounded-full h-3.5 w-3.5 {{ $ov['dot'] }}"></span>
                </span>
                <div class="flex-1">
                    <div class="font-serif text-[20px] leading-tight {{ $ov['text'] }}">{{ __('All systems') }} — {{ $ov['word'] }}</div>
                    <div class="text-[12px] text-ink-500 mt-0.5">{{ __('Last checked') }} <span data-hk-ts>{{ now()->format('H:i:s') }}</span> · {{ __('auto-refreshes every 20s') }}</div>
                </div>
                <button type="button" data-hk-refresh class="px-3 py-1.5 rounded-lg border border-paper-300 bg-paper-0/70 text-[12px] font-medium hover:bg-paper-0">{{ __('Refresh now') }}</button>
            </div>
        </div>

        {{-- Last 24h activity --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @php
                $acts = [
                    ['label' => __('Messages · 24h'), 'value' => $activity['messages'] ?? 0, 'icon' => '<path d="M2 3h12v8H6l-3 3V3Z"/>'],
                    ['label' => __('AI calls · 24h'), 'value' => $activity['ai_calls'] ?? 0, 'icon' => '<circle cx="8" cy="8" r="2.4"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2"/>'],
                    ['label' => __('Conversations · 24h'), 'value' => $activity['conversations'] ?? 0, 'icon' => '<path d="M2 4h12v7H5l-3 2V4Z"/>'],
                    ['label' => __('Webhooks · 24h'), 'value' => $activity['webhooks'] ?? 0, 'icon' => '<path d="M4 8a4 4 0 1 1 6 3.5M8 8l3 5M6 13H3"/>'],
                ];
            @endphp
            @foreach ($acts as $a)
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card flex items-center gap-3">
                    <span class="w-9 h-9 rounded-xl bg-wa-deep/10 text-wa-deep grid place-items-center shrink-0">
                        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5">{!! $a['icon'] !!}</svg>
                    </span>
                    <div>
                        <div class="font-serif text-[24px] leading-none text-ink-900">{{ number_format($a['value']) }}</div>
                        <div class="text-[11px] text-ink-500 mt-1">{{ $a['label'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Core service probes --}}
        <div>
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('Core services') }}</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($checks as $key => $c)
                    @php $t = $T($c['state']); @endphp
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card" data-hk-card="{{ $key }}">
                        <div class="flex items-center justify-between">
                            <div class="text-[13px] font-semibold text-ink-900">{{ $c['label'] }}</div>
                            <span class="flex items-center gap-1.5 text-[10px] font-mono uppercase tracking-wide px-2 py-0.5 rounded {{ $t['bg'] }} {{ $t['text'] }}" data-hk-badge>
                                <span class="w-2 h-2 rounded-full {{ $t['dot'] }}" data-hk-dot></span><span data-hk-state>{{ $t['word'] }}</span>
                            </span>
                        </div>
                        <div class="font-serif text-[19px] text-ink-900 mt-2.5 capitalize" data-hk-value>{{ $c['value'] }}</div>
                        <div class="text-[11.5px] text-ink-500 mt-1" data-hk-detail>{{ $c['detail'] }}</div>
                        {{-- Queue card gets a "Clear pending jobs" button. This app is
                             queue-less (no worker), so any pending jobs are stale
                             broadcasts from an older build that will never run — this
                             wipes them and returns the card to green. --}}
                        @if ($key === 'queue')
                            <button type="button" data-hk-clear-queue
                                class="mt-3 w-full px-3 py-1.5 rounded-lg border border-paper-300 bg-paper-0/70 text-[11.5px] font-semibold text-ink-800 hover:bg-paper-50 inline-flex items-center justify-center gap-1.5">
                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <path d="M3 4h10M6 4V2.5h4V4M5 4l1 9h4l1-9" />
                                </svg>
                                {{ __('Clear pending jobs') }}
                            </button>
                            <div data-hk-clear-msg class="mt-1.5 text-[11px] text-wa-deep hidden"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Latency + throughput --}}
        <div class="grid lg:grid-cols-[1fr_1.5fr] gap-5">
            {{-- Response-time bars --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('Response time') }}</div>
                @php $maxMs = max(1, collect($latency)->max('ms') ?: 1); @endphp
                @forelse ($latency as $l)
                    @php $lt = $T($l['state']); @endphp
                    <div class="mb-4 last:mb-0">
                        <div class="flex items-center justify-between text-[12.5px] mb-1">
                            <span class="font-medium text-ink-900">{{ $l['label'] }}</span>
                            <span class="tabular-nums text-ink-500">{{ $l['ms'] }} ms</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-paper-100 overflow-hidden">
                            <div class="h-full rounded-full {{ $lt['dot'] }}" style="width: {{ max(3, round(($l['ms'] / $maxMs) * 100)) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="text-[12.5px] text-ink-400 py-4">{{ __('No timing data.') }}</div>
                @endforelse
            </div>

            {{-- 14-day message throughput --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                @php
                    $tvals = array_values($throughput['series'] ?? []);
                    $tmax = max(1, count($tvals) ? max($tvals) : 1);
                    $tn = max(1, count($tvals));
                    $TW = 1000; $TH = 180;
                    $tpts = [];
                    foreach ($tvals as $i => $v) {
                        $x = $tn > 1 ? ($i / ($tn - 1)) * $TW : 0;
                        $y = $TH - ($v / $tmax) * ($TH - 12) - 4;
                        $tpts[] = round($x, 1) . ',' . round($y, 1);
                    }
                    $tline = implode(' ', $tpts);
                    $tarea = $tn > 1 ? "0,{$TH} " . $tline . " {$TW},{$TH}" : '';
                @endphp
                <div class="flex items-center justify-between mb-3">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Message throughput · 14 days') }}</div>
                    <div class="text-[11px] text-ink-400">{{ __('peak') }} {{ number_format($tmax) }}/{{ __('day') }}</div>
                </div>
                @if (array_sum($tvals) > 0)
                    <svg viewBox="0 0 {{ $TW }} {{ $TH }}" preserveAspectRatio="none" class="w-full h-40 text-wa-deep">
                        <defs>
                            <linearGradient id="hbFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="currentColor" stop-opacity="0.20" />
                                <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        @if ($tarea)<polygon points="{{ $tarea }}" fill="url(#hbFill)" />@endif
                        <polyline points="{{ $tline }}" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    </svg>
                @else
                    <div class="h-40 grid place-items-center text-[12.5px] text-ink-400">{{ __('No message activity in the last 14 days.') }}</div>
                @endif
            </div>
        </div>

        {{-- Engine / channel health --}}
        <div>
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('WhatsApp engines & connected numbers') }}</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse ($engines as $e)
                    @php $t = $T($e['state']); @endphp
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                        <div class="flex items-center justify-between">
                            <div class="text-[13px] font-semibold text-ink-900">{{ $e['label'] }}</div>
                            <span class="w-2.5 h-2.5 rounded-full {{ $t['dot'] }}"></span>
                        </div>
                        <div class="font-serif text-[26px] text-ink-900 mt-2 leading-none">{{ $e['connected'] }}<span class="text-[14px] text-ink-400"> / {{ $e['total'] }}</span></div>
                        <div class="text-[11.5px] text-ink-500 mt-1">{{ __('connected of total numbers') }}</div>
                        @if (!empty($e['breakdown']))
                            <div class="flex flex-wrap gap-1.5 mt-3">
                                @foreach ($e['breakdown'] as $status => $count)
                                    <span class="text-[10.5px] font-mono px-1.5 py-0.5 rounded bg-paper-100 text-ink-600">{{ $status }} {{ $count }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-[12.5px] text-ink-400 py-4">{{ __('No engines configured yet.') }}</div>
                @endforelse
            </div>
        </div>

        {{-- Host vitals --}}
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('Host vitals') }}</div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                @php
                    $vit = [
                        ['PHP', $system['php'] ?? '—'],
                        ['Laravel', $system['laravel'] ?? '—'],
                        ['Environment', $system['env'] ?? '—'],
                        ['Debug', $system['debug'] ?? '—'],
                        ['Disk free', $system['disk_free'] ?? '—'],
                        ['Memory limit', $system['memory_limit'] ?? '—'],
                    ];
                @endphp
                @foreach ($vit as [$k, $v])
                    <div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.12em] text-ink-400">{{ $k }}</div>
                        <div class="text-[13.5px] font-medium text-ink-900 mt-1 break-words">{{ $v }}</div>
                    </div>
                @endforeach
            </div>
            @if (isset($system['disk_used_pct']))
                <div class="mt-4">
                    <div class="flex items-center justify-between text-[11px] text-ink-500 mb-1"><span>{{ __('Disk used') }}</span><span>{{ $system['disk_used_pct'] }}%</span></div>
                    <div class="h-2 rounded-full bg-paper-100 overflow-hidden">
                        <div class="h-full rounded-full {{ $system['disk_used_pct'] > 90 ? 'bg-red-500' : ($system['disk_used_pct'] > 75 ? 'bg-amber-500' : 'bg-wa-deep') }}" style="width: {{ $system['disk_used_pct'] }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Live logs — Laravel + Node, straight from this server --}}
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            <div class="px-5 pt-5 pb-3">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Logs') }}</div>
                <p class="text-[12px] text-ink-500 mt-1">{{ __('Watch the server logs live or download the complete file — no hosting panel needed.') }}</p>
            </div>
            @foreach ([
                ['laravel', __('Laravel log'), 'storage/logs/laravel.log', __('Web app, webhooks, campaigns, payments, errors.')],
                ['node', __('Node log'), 'node/node.log', __('WhatsApp engines, flows, broadcasts sent through Node.')],
            ] as [$lk, $ll, $lp, $ld])
                @php $lpath = \App\Http\Controllers\Admin\LogViewerController::path($lk); @endphp
                <div class="border-t border-paper-200" data-log-row="{{ $lk }}"
                    data-tail-url="{{ route('admin.health.logs.tail', $lk) }}">
                    <div class="px-5 py-3.5 flex flex-wrap items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-paper-100 text-ink-700 grid place-items-center shrink-0">
                            <svg viewBox="0 0 24 24" class="w-4.5 h-4.5" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13.5px] font-semibold text-ink-900">{{ $ll }} <span class="font-mono text-[11px] font-normal text-ink-400">{{ $lp }}</span></div>
                            <div class="text-[11.5px] text-ink-500 mt-0.5">
                                {{ $ld }}
                                @if ($lpath)
                                    <span class="font-mono">· {{ number_format(filesize($lpath) / 1048576, 2) }} MB · {{ __('updated') }} {{ \Carbon\Carbon::createFromTimestamp(filemtime($lpath))->diffForHumans() }}</span>
                                @else
                                    <span class="text-amber-700">· {{ __('not found on this server') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button type="button" data-log-open
                                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">
                                <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Live log') }}
                            </button>
                            @if ($lpath)
                                <a href="{{ route('admin.health.logs.download', $lk) }}"
                                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium text-ink-800">
                                    <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v8M4.5 6.5L8 10l3.5-3.5M3 13h10"/></svg>{{ __('Download full file') }}
                                </a>
                            @endif
                        </div>
                    </div>
                    <div data-log-panel hidden class="px-5 pb-5">
                        <div class="rounded-xl overflow-hidden border border-ink-900/80 bg-[#0B1210]">
                            <div class="flex flex-wrap items-center gap-2 px-3 py-2 border-b border-white/10">
                                <span data-log-state class="inline-flex items-center gap-1.5 text-[11px] font-mono text-emerald-300"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>{{ __('LIVE') }}</span>
                                <span data-log-meta class="text-[11px] font-mono text-white/45"></span>
                                <input type="search" data-log-filter placeholder="{{ __('Filter lines…') }}"
                                    class="ml-auto w-40 sm:w-56 px-2.5 py-1 rounded-md bg-white/10 border border-white/10 text-[11.5px] text-white placeholder-white/40 focus:outline-none focus:border-emerald-400/60">
                                <label class="inline-flex items-center gap-1.5 text-[11px] text-white/60 cursor-pointer select-none">
                                    <input type="checkbox" data-log-follow checked class="rounded border-white/20 bg-white/10"> {{ __('Follow') }}
                                </label>
                                <button type="button" data-log-pause class="px-2.5 py-1 rounded-md bg-white/10 hover:bg-white/20 text-[11.5px] text-white">{{ __('Pause') }}</button>
                                <button type="button" data-log-clear class="px-2.5 py-1 rounded-md bg-white/10 hover:bg-white/20 text-[11.5px] text-white">{{ __('Clear') }}</button>
                                <button type="button" data-log-stop class="px-2.5 py-1 rounded-md bg-red-500/80 hover:bg-red-500 text-[11.5px] font-semibold text-white">{{ __('Stop') }}</button>
                            </div>
                            <pre data-log-out class="m-0 h-[460px] overflow-auto px-3 py-2 text-[11.5px] leading-[1.55] font-mono text-[#CFE3D8] whitespace-pre-wrap break-all"></pre>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    </main>

    <script>
        (function () {
            var TONE = {
                up:   { dot: 'bg-wa-green', text: 'text-wa-deep', bg: 'bg-wa-mint', word: @json(__('Operational')) },
                warn: { dot: 'bg-amber-500',   text: 'text-amber-700',   bg: 'bg-amber-50',   word: @json(__('Degraded')) },
                down: { dot: 'bg-red-500',      text: 'text-red-700',     bg: 'bg-red-50',     word: @json(__('Down')) },
                idle: { dot: 'bg-ink-300',      text: 'text-ink-500',     bg: 'bg-paper-100',  word: @json(__('Idle')) },
            };
            var url = @json(url('/admin/health')) + '?format=json';

            function paint(data) {
                (Object.keys(data.checks || {})).forEach(function (key) {
                    var card = document.querySelector('[data-hk-card="' + key + '"]');
                    if (!card) return;
                    var c = data.checks[key], t = TONE[c.state] || TONE.idle;
                    var dot = card.querySelector('[data-hk-dot]');
                    var state = card.querySelector('[data-hk-state]');
                    var badge = card.querySelector('[data-hk-badge]');
                    var val = card.querySelector('[data-hk-value]');
                    var det = card.querySelector('[data-hk-detail]');
                    if (dot) dot.className = 'w-2 h-2 rounded-full ' + t.dot;
                    if (state) state.textContent = t.word;
                    if (badge) badge.className = 'flex items-center gap-1.5 text-[10px] font-mono uppercase tracking-wide px-2 py-0.5 rounded ' + t.bg + ' ' + t.text;
                    if (val) val.textContent = c.value;
                    if (det) det.textContent = c.detail;
                });
                var ts = document.querySelector('[data-hk-ts]');
                if (ts) ts.textContent = new Date().toLocaleTimeString();
            }
            function tick() {
                fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) { if (d) paint(d); })
                    .catch(function () {});
            }
            var btn = document.querySelector('[data-hk-refresh]');
            if (btn) btn.addEventListener('click', tick);
            setInterval(tick, 20000);

            // Clear pending jobs — wipes the stale `jobs` / `failed_jobs` rows
            // (this app runs no queue worker, so they never drain on their own),
            // then re-polls so the Queue card flips back to Operational.
            var clearBtn = document.querySelector('[data-hk-clear-queue]');
            if (clearBtn) {
                var msg = document.querySelector('[data-hk-clear-msg]');
                clearBtn.addEventListener('click', function () {
                    if (!window.confirm('Clear all pending & failed jobs? This app has no queue worker, so these are stale rows that will never run.')) return;
                    clearBtn.disabled = true;
                    var original = clearBtn.innerHTML;
                    clearBtn.textContent = 'Clearing…';
                    fetch(@json(url('/admin/health/clear-queue')), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').content : '',
                        },
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            clearBtn.disabled = false;
                            clearBtn.innerHTML = original;
                            if (d && d.ok) {
                                if (msg) { msg.textContent = d.message || 'Cleared.'; msg.classList.remove('hidden'); }
                                tick();
                            } else if (msg) {
                                msg.textContent = 'Failed: ' + ((d && d.message) || 'unknown error');
                                msg.classList.remove('hidden');
                                msg.classList.add('text-red-600');
                            }
                        })
                        .catch(function (e) {
                            clearBtn.disabled = false;
                            clearBtn.innerHTML = original;
                            if (msg) { msg.textContent = 'Failed: ' + e.message; msg.classList.remove('hidden'); msg.classList.add('text-red-600'); }
                        });
                });
            }
        })();
    </script>
</x-layouts.admin>
