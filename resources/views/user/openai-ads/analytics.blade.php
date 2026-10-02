<x-layouts.user :title="__('OpenAI Ads analytics')" nav-key="openai-ads" page="user-openai-ads-analytics">
    @php
        $cur = $account['currency_code'] ?? '';
        $num = fn ($n) => number_format((float) $n);
        $money = fn ($n) => trim($cur . ' ' . number_format((float) $n, 2));
    @endphp
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.openai-ads._rail', ['current' => 'analytics', 'account' => $account])

            <section class="space-y-5">
                <div class="flex items-end justify-between gap-3 flex-wrap">
                    <div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('OpenAI Ads') }}</div>
                        <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">{{ __('Analytics') }}</h1>
                        <p class="text-[13px] text-ink-600 mt-2">{{ __('Delivery and spend across your campaigns.') }}</p>
                    </div>
                    <form method="GET" action="{{ route('user.openai-ads.analytics') }}" class="flex items-end gap-2 flex-wrap">
                        <label class="block">
                            <span class="text-[10px] font-mono uppercase text-ink-500">{{ __('From') }}</span>
                            <input type="date" name="start" value="{{ $start }}" class="block mt-1 px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <label class="block">
                            <span class="text-[10px] font-mono uppercase text-ink-500">{{ __('To') }}</span>
                            <input type="date" name="end" value="{{ $end }}" class="block mt-1 px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <button type="submit" class="px-3.5 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Apply') }}</button>
                    </form>
                </div>

                @if ($error)
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $error }}</div>
                @endif

                {{-- KPI strip --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ([
                        [__('Spend'), $money($totals['spend'])],
                        [__('Impressions'), $num($totals['impressions'])],
                        [__('Clicks'), $num($totals['clicks'])],
                        [__('CTR'), number_format($totals['ctr'], 2) . '%'],
                        [__('CPC'), $money($totals['cpc'])],
                        [__('CPM'), $money($totals['cpm'])],
                        [__('From'), $start],
                        [__('To'), $end],
                    ] as [$label, $value])
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <div class="font-mono text-[10px] uppercase tracking-wide text-ink-500">{{ $label }}</div>
                            <div class="text-[22px] font-serif mt-1 text-ink-900 leading-none whitespace-nowrap">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Chart payload for ApexCharts --}}
                <script type="application/json" id="oaiads-chart-data">@json($chartData)</script>

                {{-- Trend line --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                    <div class="text-[12px] font-semibold text-ink-800 mb-2">{{ __('Daily spend & clicks') }}</div>
                    <div id="oaiads-trend" class="min-h-[300px]"></div>
                    <div id="oaiads-trend-empty" class="hidden py-12 text-center text-[12.5px] text-ink-500">{{ __('No daily data for this range yet.') }}</div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    {{-- Spend by campaign bar --}}
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                        <div class="text-[12px] font-semibold text-ink-800 mb-2">{{ __('Spend by campaign') }}</div>
                        <div id="oaiads-by-campaign" class="min-h-[280px]"></div>
                        <div id="oaiads-by-campaign-empty" class="hidden py-12 text-center text-[12.5px] text-ink-500">{{ __('No campaign spend yet.') }}</div>
                    </div>

                    {{-- Breakdown table --}}
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-3 border-b border-paper-100 text-[12px] font-semibold text-ink-800">{{ __('By campaign') }}</div>
                        @if (empty($rows))
                            <div class="px-5 py-10 text-center text-[12.5px] text-ink-500">{{ __('No delivery data for this range yet.') }}</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-[12.5px]">
                                    <thead class="text-ink-500 text-[11px] uppercase tracking-wide">
                                        <tr class="border-b border-paper-100">
                                            <th class="text-left font-medium px-5 py-2">{{ __('Campaign') }}</th>
                                            <th class="text-right font-medium px-5 py-2">{{ __('Clicks') }}</th>
                                            <th class="text-right font-medium px-5 py-2">{{ __('CTR') }}</th>
                                            <th class="text-right font-medium px-5 py-2">{{ __('Spend') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $r)
                                            <tr class="border-b border-paper-50">
                                                <td class="px-5 py-2.5 text-ink-900">{{ data_get($r, 'campaign_name', data_get($r, 'campaign_id', '—')) }}</td>
                                                <td class="px-5 py-2.5 text-right font-mono text-ink-700">{{ $num(data_get($r, 'clicks', 0)) }}</td>
                                                <td class="px-5 py-2.5 text-right font-mono text-ink-700">{{ number_format((float) data_get($r, 'ctr', 0), 2) }}%</td>
                                                <td class="px-5 py-2.5 text-right font-mono text-ink-700">{{ $money(data_get($r, 'spend', 0)) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </main>
</x-layouts.user>
