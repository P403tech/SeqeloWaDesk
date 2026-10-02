<x-layouts.user :title="__('Drip Campaigns')" nav-key="drip" page="user-drip-campaigns-index">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-5">

        @if (session('success'))
            <div class="px-4 py-2.5 rounded-xl bg-wa-bubble border border-wa-green/30 text-[12.5px] text-wa-deep inline-flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="m4 8 3 3 5-6" />
                </svg>
                {{ session('success') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="px-4 py-2.5 rounded-xl bg-accent-coral/10 border border-accent-coral/30 text-[12.5px] text-accent-coral">
                @foreach ($errors->all() as $e)
                    <div>{{ $e }}</div>
                @endforeach
            </div>
        @endif

        {{-- Header --}}
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    <a href="{{ url('/wa-campaigns') }}" class="hover:text-wa-deep">{{ __('Campaigns') }}</a>
                    <span class="mx-1.5 text-ink-500/60">/</span>
                    <span>{{ __('Drip') }}</span>
                </div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">
                    {{ __('Drip') }} <span class="italic text-wa-deep">{{ __('campaigns') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Timed follow-up sequences. Send a message, wait hours or days, send the next one — and stop the moment the contact replies.') }}
                </p>
            </div>
            <a href="{{ route('user.drip.create') }}"
                class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold inline-flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M8 3v10M3 8h10" />
                </svg>
                {{ __('New drip campaign') }}
            </a>
        </div>

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ([
                ['Campaigns', $stats['total'], __('total created')],
                ['Running', $stats['running'], __('currently active')],
                ['In sequence', $stats['active'], __('contacts mid-drip')],
                ['Completed', $stats['completed'], __('finished the sequence')],
            ] as [$label, $value, $hint])
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __($label) }}</div>
                    <div class="font-serif text-[30px] leading-none mt-2 tabular-nums">{{ number_format($value) }}</div>
                    <div class="text-[11px] text-ink-500 mt-1">{{ $hint }}</div>
                </div>
            @endforeach
        </div>

        {{-- List --}}
        @if ($campaigns->isEmpty())
            <div class="bg-paper-0 border border-dashed border-paper-200 rounded-2xl p-10 text-center">
                <div class="font-serif text-[22px] leading-tight">{{ __('No drip campaigns yet') }}</div>
                <p class="text-[12.5px] text-ink-600 mt-2 max-w-lg mx-auto">
                    {{ __('A drip is a schedule, not a conversation. Typical shape: confirm immediately, remind after 1 day, follow up after 3 days, check in after a week.') }}
                </p>
                <a href="{{ route('user.drip.create') }}"
                    class="mt-4 inline-flex px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">
                    {{ __('Create your first one') }}
                </a>
            </div>
        @else
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead class="bg-paper-50 text-left font-mono text-[10.5px] uppercase text-ink-500 tracking-wide">
                            <tr>
                                <th class="px-4 py-2.5">{{ __('Campaign') }}</th>
                                <th class="px-4 py-2.5">{{ __('Trigger') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ __('Steps') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ __('In sequence') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ __('Done') }}</th>
                                <th class="px-4 py-2.5">{{ __('Status') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-100">
                            @foreach ($campaigns as $c)
                                @php
                                    $badge = match ($c->status) {
                                        'active' => 'bg-wa-mint text-wa-deep border-wa-green/40',
                                        'paused' => 'bg-accent-amber/15 text-[#8B5A14] border-accent-amber/40',
                                        default  => 'bg-paper-100 text-ink-600 border-paper-200',
                                    };
                                @endphp
                                <tr class="hover:bg-paper-50">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('user.drip.edit', $c->id) }}"
                                            class="font-medium text-ink-900 hover:text-wa-deep">{{ $c->name }}</a>
                                        @if ($c->stop_on_reply)
                                            <div class="font-mono text-[10px] text-ink-500 mt-0.5">
                                                {{ __('stops on reply') }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-ink-700">
                                        {{ \App\Models\DripCampaign::TRIGGERS[$c->trigger_type] ?? $c->trigger_type }}
                                        @if ($c->trigger_value)
                                            <span class="font-mono text-[11px] text-ink-500">· {{ $c->trigger_value }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $c->steps_count }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $c->active_count }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $c->completed_count }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-mono text-[10px] uppercase px-2 py-0.5 rounded-full border {{ $badge }}">
                                            {{ $c->status }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5 justify-end">
                                            <form method="POST" action="{{ route('user.drip.toggle', $c->id) }}">
                                                @csrf
                                                <button type="submit"
                                                    class="px-3 py-1.5 rounded-full border border-paper-200 hover:bg-paper-50 text-[11.5px] font-semibold">
                                                    {{ $c->isRunning() ? __('Pause') : __('Activate') }}
                                                </button>
                                            </form>
                                            <a href="{{ route('user.drip.edit', $c->id) }}"
                                                class="px-3 py-1.5 rounded-full border border-paper-200 hover:bg-paper-50 text-[11.5px] font-semibold">{{ __('Edit') }}</a>
                                            <form method="POST" action="{{ route('user.drip.destroy', $c->id) }}"
                                                onsubmit="return confirm('{{ __('Delete this campaign and all its enrolments?') }}');">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                    class="px-3 py-1.5 rounded-full text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-semibold">{{ __('Delete') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <p class="text-[11.5px] text-ink-500">
            {{ __('Pending steps are stored with a due time, so a restart delays a follow-up rather than losing it.') }}
        </p>
    </main>

</x-layouts.user>
