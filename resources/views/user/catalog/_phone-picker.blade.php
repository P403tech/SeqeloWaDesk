{{-- Pick one connected phone as the catalog's main device. --}}
@php
    $phones = $phones ?? collect();
    $catalogSender = $catalogSender ?? '';
@endphp

@if ($phones->isNotEmpty())
    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Step 1') }}</div>
        <div class="font-serif text-[20px] leading-tight mt-1">{{ __('Choose the main catalog phone') }}</div>
        <p class="text-[12.5px] text-ink-600 mt-1.5 max-w-3xl">
            {{ __('Every WhatsApp number on this account is listed below. Pick one as the main device. Catalog messages send from that phone.') }}
        </p>

        <div class="mt-4 rounded-xl border border-wa-deep/20 bg-wa-mint/30 px-4 py-3 text-[12px] text-ink-700 space-y-1.5">
            <div><b>{{ __('Official WhatsApp (WABA)') }}:</b>
                {{ __('We look up the catalog already linked to that Business Account. If none exists, we create a Meta Commerce catalog on the Business and attach it to this number. Products you sync then appear in WhatsApp on this phone.') }}</div>
            <div><b>{{ __('Unofficial API') }}:</b>
                {{ __('We mark this phone as the sender. Product cards (carousel) go out from it. Meta does not host a Commerce catalog on unofficial numbers.') }}</div>
        </div>

        @error('sender')
            <div class="mt-3 text-[12px] text-accent-coral">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('user.catalog.choose-main') }}" class="mt-4 space-y-3">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ($phones as $p)
                    @php
                        $phone = trim(($p->country_code ?? '') . ' ' . ($p->phone_number ?? ''));
                        $engineLabel = match ($p->engine ?? '') {
                            'waba' => __('Official WhatsApp'),
                            'twilio' => 'Twilio',
                            default => __('Unofficial API'),
                        };
                        $checked = (bool) ($p->is_main ?? false);
                    @endphp
                    <label class="border rounded-xl p-3 flex items-start gap-3 cursor-pointer has-[:checked]:border-wa-deep has-[:checked]:bg-wa-mint/40 {{ $p->live ? 'border-paper-200' : 'border-paper-200 opacity-80' }}">
                        <input type="radio" name="sender" value="{{ $p->key }}" class="mt-1 accent-wa-deep" @checked($checked) required>
                        <span class="w-9 h-9 rounded-full {{ $p->live ? 'bg-wa-bubble/70' : 'bg-paper-50' }} grid place-items-center shrink-0 mt-0.5">
                            <svg viewBox="0 0 24 24" class="w-4 h-4 {{ $p->live ? 'text-wa-deep' : 'text-ink-500' }}" fill="none" stroke="currentColor" stroke-width="1.7">
                                <rect x="7" y="2" width="10" height="20" rx="2" />
                                <path d="M11 18h2" />
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-[13px] truncate">{{ $p->device_name }}</span>
                            <span class="block font-mono text-[11px] text-ink-500 truncate">{{ $phone !== '' ? $phone : __('No number') }} · {{ $engineLabel }}</span>
                            <span class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-mono {{ $p->live ? 'bg-wa-mint text-wa-deep' : 'bg-paper-50 text-ink-500' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $p->live ? 'bg-wa-green' : 'bg-paper-300' }}"></span>
                                {{ $p->live ? __('Connected') : ($p->status ?: __('Offline')) }}
                            </span>
                            @if ($p->is_main)
                                <span class="ml-1 inline-flex px-2 py-0.5 rounded-full text-[10px] font-mono bg-wa-deep text-paper-0">{{ __('Main') }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2 pt-1">
                <button type="submit"
                    class="px-5 py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">
                    {{ __('Use this phone as main catalog device') }}
                </button>
                <button type="button" data-connect-device
                    class="px-4 py-2 rounded-full border border-paper-200 hover:bg-paper-50 text-[12px] font-medium">
                    {{ __('Connect another phone') }}
                </button>
            </div>
        </form>
    </div>
@endif
