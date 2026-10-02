<x-layouts.user :title="$campaign->name" nav-key="openai-ads" page="user-openai-ads-show">
    @php
        $cur = $account['currency_code'] ?? '';
        $micros = fn ($m) => number_format(((int) $m) / 1000000, 2);
        $isActive = $campaign->status === 'active';
    @endphp
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.openai-ads._rail', ['current' => 'campaigns', 'account' => $account])

            <section class="space-y-5 max-w-4xl">
                <div>
                    <a href="{{ route('user.openai-ads.index') }}" class="text-[11.5px] text-ink-500 hover:text-wa-deep">← {{ __('All campaigns') }}</a>
                </div>

                @if (session('status'))
                    <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $errors->first() }}</div>
                @endif

                {{-- Campaign header --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <div class="flex items-center gap-2">
                                <h1 class="font-serif text-[24px] leading-tight">{{ $campaign->name }}</h1>
                                <span class="text-[10.5px] font-mono px-1.5 py-0.5 rounded-full {{ $isActive ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-500' }}">{{ $campaign->status }}</span>
                            </div>
                            <div class="text-[11.5px] text-ink-500 font-mono mt-1">
                                {{ $campaign->remote_id }} · {{ $campaign->bidding_type }} · {{ $cur }} {{ $micros($campaign->budget_micros) }} {{ __('lifetime') }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($isActive)
                                <form method="POST" action="{{ route('user.openai-ads.pause', $campaign->id) }}">
                                    @csrf
                                    <button type="submit" class="px-3.5 py-1.5 rounded-lg border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-50">{{ __('Pause') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('user.openai-ads.activate', $campaign->id) }}"
                                    onsubmit="return confirm('{{ __('Take this campaign live?') }}')">
                                    @csrf
                                    <button type="submit" class="px-3.5 py-1.5 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Activate') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Ad groups + ads --}}
                @foreach ($campaign->adGroups as $ag)
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-3 border-b border-paper-100 flex items-center justify-between">
                            <div>
                                <span class="text-[13px] font-semibold text-ink-900">{{ $ag->name }}</span>
                                <span class="text-[11px] text-ink-500 font-mono ml-2">{{ $ag->billing_event_type }} · {{ __('max bid') }} {{ $cur }} {{ $micros($ag->max_bid_micros) }}</span>
                            </div>
                            <span class="text-[10.5px] font-mono px-1.5 py-0.5 rounded-full {{ $ag->status === 'active' ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-500' }}">{{ $ag->status }}</span>
                        </div>
                        <div class="divide-y divide-paper-50">
                            @forelse ($ag->ads as $ad)
                                <div class="px-5 py-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-[12.5px] font-semibold text-ink-900">{{ $ad->title ?: $ad->name }}</div>
                                        <div class="text-[11.5px] text-ink-600 mt-0.5">{{ $ad->body }}</div>
                                        <div class="text-[10.5px] text-ink-400 font-mono mt-0.5 truncate">{{ $ad->creative_type }}@if($ad->target_url) · {{ $ad->target_url }}@endif</div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1 shrink-0">
                                        <span class="text-[10.5px] font-mono px-1.5 py-0.5 rounded-full {{ $ad->status === 'active' ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-500' }}">{{ $ad->status }}</span>
                                        @if ($ad->review_status)
                                            <span class="text-[10px] text-ink-400 font-mono">{{ __('review') }}: {{ $ad->review_status }}</span>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="px-5 py-4 text-[12px] text-ink-500">{{ __('No ads in this group.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </section>
        </div>
    </main>
</x-layouts.user>
