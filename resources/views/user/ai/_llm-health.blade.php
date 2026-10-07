@php
    $llmHealth = $llmHealth ?? ['errors' => [], 'blocked' => []];
@endphp
@if (!empty($llmHealth['blocked']) || !empty($llmHealth['errors']))
    <div class="rounded-2xl border border-accent-coral/30 bg-accent-coral/5 overflow-hidden">
        <div class="px-5 py-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-accent-coral">{{ __('Last AI errors') }}</div>
                <p class="text-[13px] text-ink-700 mt-1">{{ __('Agents that cannot resolve a key, and recent provider failures in this workspace.') }}</p>
            </div>
            <a href="{{ url('/settings?tab=aikeys') }}" class="text-[12px] font-semibold text-wa-deep hover:underline shrink-0">{{ __('Settings → AI keys') }}</a>
        </div>
        @if (!empty($llmHealth['blocked']))
            <div class="px-5 pb-3 space-y-1.5">
                @foreach ($llmHealth['blocked'] as $b)
                    <div class="flex items-start justify-between gap-3 text-[12.5px]">
                        <a href="{{ url('/ai-training/' . $b['id'] . '/edit') }}" class="font-medium text-ink-900 hover:text-wa-deep truncate">{{ $b['name'] }}</a>
                        <span class="font-mono text-[11px] text-ink-500 shrink-0">{{ $b['provider'] }}{{ !empty($b['model']) ? ' / '.$b['model'] : '' }}</span>
                    </div>
                @endforeach
            </div>
        @endif
        @if (!empty($llmHealth['errors']))
            <div class="border-t border-accent-coral/20 divide-y divide-paper-100">
                @foreach ($llmHealth['errors'] as $err)
                    <div class="px-5 py-2.5">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[12.5px] font-medium text-ink-900 capitalize">{{ $err['provider'] ?: __('unknown') }}</span>
                            <span class="text-[11px] font-mono text-ink-400">{{ \Illuminate\Support\Carbon::parse($err['at'])->diffForHumans() }}</span>
                        </div>
                        <div class="text-[12px] text-ink-600 mt-0.5 leading-snug">{{ $err['message'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
