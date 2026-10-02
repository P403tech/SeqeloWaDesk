<x-layouts.user :title="__('OpenAI Ads')" nav-key="openai-ads" page="user-openai-ads-index">
    @php
        $reviewStatus = data_get($account, 'review.status') ?? ($account['status'] ?? null);
        $micros = fn ($m) => number_format(((int) $m) / 1000000, 2);
        $activeCount = $campaigns->where('status', 'active')->count();
    @endphp
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.openai-ads._rail', ['current' => 'campaigns', 'account' => $account])

            <section class="space-y-5">
                <div class="flex items-end justify-between gap-3 flex-wrap">
                    <div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('OpenAI Ads') }}</div>
                        <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">{{ __('Ad') }} <span class="italic text-wa-deep">{{ __('campaigns') }}</span></h1>
                        <p class="text-[13px] text-ink-600 mt-2">{{ __('Campaigns running on your own OpenAI ad account.') }}</p>
                    </div>
                    @if ($connected)
                        <div class="flex items-center gap-2">
                            <a href="{{ route('user.openai-ads.create') }}" class="px-4 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('New campaign') }}</a>
                            <form method="POST" action="{{ route('user.openai-ads.verify') }}">
                                @csrf
                                <button type="submit" class="px-3.5 py-2 rounded-lg border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-50">{{ __('Verify') }}</button>
                            </form>
                        </div>
                    @endif
                </div>

                @if (session('status'))
                    <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
                @endif
                @if ($error)
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $error }}</div>
                @endif

                @if (! $connected)
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-8 shadow-card text-center">
                        <h2 class="font-serif text-[20px] text-ink-900">{{ __('Connect your OpenAI ad account') }}</h2>
                        <p class="text-[13px] text-ink-600 mt-1 max-w-md mx-auto">{{ __('Add your Ads API key to view and manage campaigns here. Your key is stored encrypted and used only for your own account.') }}</p>
                        <a href="{{ route('user.openai-ads.connect') }}" class="inline-block mt-4 px-4 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Connect OpenAI Ads') }}</a>
                    </div>
                @else
                    {{-- KPI strip --}}
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach ([
                            [__('Campaigns'), $campaigns->count()],
                            [__('Active'), $activeCount],
                            [__('Currency'), $account['currency_code'] ?? '—'],
                            [__('Account review'), $reviewStatus ?: '—'],
                        ] as [$label, $value])
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                                <div class="font-mono text-[10px] uppercase tracking-wide text-ink-500">{{ $label }}</div>
                                <div class="text-[26px] font-serif mt-1 text-ink-900 leading-none">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Campaign list --}}
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-3 border-b border-paper-100 flex items-center justify-between">
                            <span class="text-[12px] font-semibold text-ink-800">{{ __('All campaigns') }}</span>
                            <a href="{{ route('user.openai-ads.create') }}" class="text-[11.5px] text-wa-deep font-semibold hover:underline">{{ __('New campaign') }}</a>
                        </div>
                        @if ($campaigns->isEmpty())
                            <div class="px-5 py-8 text-center text-[12.5px] text-ink-500">
                                {{ __('No campaigns yet.') }}
                                <a href="{{ route('user.openai-ads.create') }}" class="text-wa-deep font-semibold hover:underline">{{ __('Create your first campaign') }}</a>.
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-[12.5px]">
                                    <thead class="text-ink-500 text-[11px] uppercase tracking-wide">
                                        <tr class="border-b border-paper-100">
                                            <th class="text-left font-medium px-5 py-2">{{ __('Name') }}</th>
                                            <th class="text-left font-medium px-5 py-2">{{ __('Status') }}</th>
                                            <th class="text-left font-medium px-5 py-2">{{ __('Bidding') }}</th>
                                            <th class="text-right font-medium px-5 py-2">{{ __('Lifetime budget') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($campaigns as $c)
                                            <tr class="border-b border-paper-50 hover:bg-paper-50 cursor-pointer" onclick="window.location='{{ route('user.openai-ads.show', $c->id) }}'">
                                                <td class="px-5 py-2.5 text-ink-900 font-medium">{{ $c->name }}</td>
                                                <td class="px-5 py-2.5">
                                                    <span class="text-[10.5px] font-mono px-1.5 py-0.5 rounded-full {{ $c->status === 'active' ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-500' }}">{{ $c->status }}</span>
                                                </td>
                                                <td class="px-5 py-2.5 text-ink-600">{{ $c->bidding_type }}</td>
                                                <td class="px-5 py-2.5 text-right font-mono text-ink-700">{{ $account['currency_code'] ?? '' }} {{ $micros($c->budget_micros) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        </div>
    </main>
</x-layouts.user>
