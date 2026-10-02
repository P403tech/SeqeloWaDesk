<x-layouts.user :title="__('Threads Insights')" nav-key="threads-posts" page="user-threads-insights">
    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-7">

        <div class="flex items-center justify-between mb-5">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Threads') }}</div>
                <h1 class="font-serif text-[28px] leading-tight">{{ __('Insights') }}</h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('user.threads.insights', ['refresh' => 1]) }}" class="text-[12px] font-mono text-wa-deep hover:text-wa-teal">{{ __('Refresh') }}</a>
                <a href="{{ route('user.threads.posts') }}" class="text-[12px] font-mono text-ink-500">{{ __('Posts') }}</a>
            </div>
        </div>

        @php
            $fmt = fn ($n) => $n >= 1000000 ? round($n/1000000,1).'M' : ($n >= 1000 ? round($n/1000,1).'K' : (string) (int) $n);
            $acctCards = [['followers_count','Followers'],['views','Views'],['likes','Likes'],['replies','Replies'],['reposts','Reposts'],['quotes','Quotes']];
        @endphp

        @forelse ($data as $d)
            <section class="mb-8">
                <div class="flex items-center gap-2 mb-3">
                    @if ($d['account']->profile_pic_url)
                        <img src="{{ $d['account']->profile_pic_url }}" class="w-7 h-7 rounded-full" alt="">
                    @endif
                    <div class="font-semibold text-[13.5px] text-ink-900">{{ '@' . ($d['account']->username ?: $d['account']->threads_user_id) }}</div>
                </div>

                {{-- Account totals --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
                    @foreach ($acctCards as [$key, $label])
                        <div class="bg-paper-0 border border-paper-200 rounded-xl p-3">
                            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __($label) }}</div>
                            <div class="font-serif text-[24px] leading-none mt-1">{{ $fmt($d['totals'][$key] ?? 0) }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Follower demographics --}}
                @php $demo = $d['demographics'] ?? []; $hasDemo = collect($demo)->flatten()->isNotEmpty(); @endphp
                @if ($hasDemo)
                    <div class="grid sm:grid-cols-3 gap-3 mb-5">
                        @foreach (['country' => __('Top countries'), 'age' => __('Age'), 'gender' => __('Gender')] as $bd => $title)
                            <div class="bg-paper-0 border border-paper-200 rounded-xl p-3">
                                <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-2">{{ $title }}</div>
                                @php $rows = $demo[$bd] ?? []; $max = max(1, ...(count($rows) ? array_values($rows) : [1])); @endphp
                                @forelse ($rows as $label => $count)
                                    <div class="mb-1.5">
                                        <div class="flex justify-between text-[11px] text-ink-700"><span class="truncate">{{ $label }}</span><span class="font-mono text-ink-500">{{ $fmt($count) }}</span></div>
                                        <div class="h-1.5 rounded-full bg-paper-100 mt-0.5"><div class="h-1.5 rounded-full bg-wa-green" style="width: {{ max(4, round($count / $max * 100)) }}%"></div></div>
                                    </div>
                                @empty
                                    <div class="text-[11px] text-ink-400">{{ __('No data') }}</div>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Per-post table --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden">
                    <div class="grid grid-cols-[1.6fr_repeat(6,1fr)] gap-2 px-4 py-2.5 border-b border-paper-200 text-[10px] font-mono uppercase tracking-[0.12em] text-ink-500">
                        <div>{{ __('Post') }}</div>
                        <div class="text-right">{{ __('Views') }}</div>
                        <div class="text-right">{{ __('Likes') }}</div>
                        <div class="text-right">{{ __('Replies') }}</div>
                        <div class="text-right">{{ __('Reposts') }}</div>
                        <div class="text-right">{{ __('Quotes') }}</div>
                        <div class="text-right">{{ __('Shares') }}</div>
                    </div>
                    @forelse ($d['posts'] as $row)
                        @php $ins = $row['insights']; $p = $row['post']; @endphp
                        <div class="grid grid-cols-[1.6fr_repeat(6,1fr)] gap-2 px-4 py-2.5 border-b border-paper-100 last:border-0 items-center">
                            <div class="min-w-0">
                                <div class="text-[12px] text-ink-800 truncate">{{ $p->text ?: '('.$p->media_type.')' }}</div>
                                <div class="text-[10px] text-ink-400 font-mono">{{ optional($p->published_at)->format('M j, H:i') }}</div>
                            </div>
                            <div class="text-right text-[12.5px] font-mono">{{ $fmt($ins['views'] ?? 0) }}</div>
                            <div class="text-right text-[12.5px] font-mono">{{ $fmt($ins['likes'] ?? 0) }}</div>
                            <div class="text-right text-[12.5px] font-mono">{{ $fmt($ins['replies'] ?? 0) }}</div>
                            <div class="text-right text-[12.5px] font-mono">{{ $fmt($ins['reposts'] ?? 0) }}</div>
                            <div class="text-right text-[12.5px] font-mono">{{ $fmt($ins['quotes'] ?? 0) }}</div>
                            <div class="text-right text-[12.5px] font-mono">{{ $fmt($ins['shares'] ?? 0) }}</div>
                        </div>
                    @empty
                        <div class="px-4 py-4 text-[12.5px] text-ink-400">{{ __('No published posts yet.') }}</div>
                    @endforelse
                </div>
            </section>
        @empty
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 text-center">
                <p class="text-[13px] text-ink-600 mb-3">{{ __('Connect a Threads account to see insights.') }}</p>
                <a href="{{ route('user.threads.connect') }}" class="inline-flex px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">{{ __('Connect Threads') }}</a>
            </div>
        @endforelse
    </main>
</x-layouts.user>
