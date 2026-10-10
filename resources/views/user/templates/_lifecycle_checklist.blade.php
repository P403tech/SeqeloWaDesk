@if (!empty($templateLifecycleChecklist))
    @php $c = $templateLifecycleChecklist; @endphp
    <div class="mb-5 rounded-2xl border border-wa-green/30 bg-wa-mint/40 p-4 sm:p-5">
        <div class="text-[11px] font-mono uppercase tracking-wider text-wa-deep mb-2">{{ __('From the Seqelo library') }}</div>
        <div class="font-semibold text-[14px] text-ink-900 mb-3">{{ __('Meta approval checklist') }}</div>
        <ol class="grid sm:grid-cols-3 gap-3 text-[12.5px]">
            <li class="flex gap-2 items-start">
                <span class="w-6 h-6 rounded-full bg-wa-deep text-paper-0 grid place-items-center text-[11px] shrink-0">1</span>
                <span><strong>{{ __('Installed') }}</strong> — {{ $c['installed'] }} {{ __('from admin library') }}</span>
            </li>
            <li class="flex gap-2 items-start {{ $c['not_submitted'] > 0 ? 'text-accent-amber' : '' }}">
                <span class="w-6 h-6 rounded-full {{ $c['not_submitted'] > 0 ? 'bg-accent-amber text-paper-0' : 'bg-paper-200 text-ink-600' }} grid place-items-center text-[11px] shrink-0">2</span>
                <span>
                    <strong>{{ __('Submit to Meta') }}</strong>
                    @if ($c['not_submitted'] > 0)
                        — {{ $c['not_submitted'] }} {{ __('waiting to submit') }}.
                        <a href="{{ $c['filter_all'] }}" class="text-wa-deep underline font-medium">{{ __('Open templates') }}</a>
                    @else
                        — {{ __('Done or in review') }}
                    @endif
                </span>
            </li>
            <li class="flex gap-2 items-start">
                <span class="w-6 h-6 rounded-full {{ $c['approved'] > 0 ? 'bg-wa-green text-paper-0' : 'bg-paper-200 text-ink-600' }} grid place-items-center text-[11px] shrink-0">3</span>
                <span>
                    <strong>{{ __('Approved') }}</strong> — {{ number_format($c['approved']) }} {{ __('ready for campaigns') }}
                    @if ($c['pending'] > 0)
                        · <a href="{{ $c['filter_pending'] }}" class="text-wa-deep underline">{{ $c['pending'] }} {{ __('in review') }}</a>
                    @endif
                </span>
            </li>
        </ol>
    </div>
@endif
