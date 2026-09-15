@php $auto = $auto ?? []; @endphp
<form method="POST" action="{{ route('user.catalog.automation') }}"
    class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
    @csrf
    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Share with customers') }}</div>
    <div class="font-serif text-[18px] leading-tight mt-1">{{ __('Send the catalog automatically') }}</div>
    <p class="text-[12.5px] text-ink-600 mt-1.5 max-w-3xl">
        {{ __('Unofficial WhatsApp cannot host a Meta catalog on the phone. Seqelo sends product cards (or your shop link) from the main device you picked above.') }}
    </p>
    @error('automation')
        <div class="mt-2 text-[12px] text-accent-coral">{{ $message }}</div>
    @enderror

    <div class="border border-paper-200 rounded-xl p-4 mt-4">
        <label class="flex items-start gap-3 text-[12.5px]">
            <input type="hidden" name="share_on_keyword" value="0">
            <input type="checkbox" name="share_on_keyword" value="1" @checked($auto['share_on_keyword'] ?? false)
                class="mt-0.5 rounded border-paper-200 text-wa-deep">
            <span>
                <span class="font-semibold block">{{ __('Send catalog when they ask') }}</span>
                <span class="text-[10.5px] text-ink-500">{{ __('If a customer writes catalog, menu, price list, products, or shop — we send product cards from the main phone. If there are no products yet, we send the shop link.') }}</span>
            </span>
        </label>
    </div>

    <div class="border border-paper-200 rounded-xl p-4 mt-3">
        <label class="flex items-start gap-3 text-[12.5px]">
            <input type="hidden" name="share_on_hello" value="0">
            <input type="checkbox" name="share_on_hello" value="1" @checked($auto['share_on_hello'] ?? false)
                class="mt-0.5 rounded border-paper-200 text-wa-deep">
            <span>
                <span class="font-semibold block">{{ __('Send catalog on first hello') }}</span>
                <span class="text-[10.5px] text-ink-500">{{ __('Once per customer per day when they say hi / hello / hey. Use this as a welcome catalog.') }}</span>
            </span>
        </label>
    </div>

    <div class="border border-paper-200 rounded-xl p-4 mt-3">
        <label class="flex items-start gap-3 text-[12.5px]">
            <input type="hidden" name="concierge_enabled" value="0">
            <input type="checkbox" name="concierge_enabled" value="1" @checked($auto['concierge_enabled'] ?? false)
                class="mt-0.5 rounded border-paper-200 text-wa-deep">
            <span>
                <span class="font-semibold block">{{ __('Match products from their question') }}</span>
                <span class="text-[10.5px] text-ink-500">{{ __('Example: “red shoes under 2000” → we send matching product cards.') }}</span>
            </span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
            <label class="block md:col-span-2">
                <span class="text-[10.5px] font-mono uppercase tracking-[0.12em] text-ink-500">{{ __('Reply header') }}</span>
                <input type="text" name="concierge_header" maxlength="60"
                    value="{{ $auto['concierge_header'] ?? '' }}" placeholder="{{ __("Here's what I found") }}"
                    class="mt-1 w-full border border-paper-200 rounded-lg px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
            </label>
            <label class="block">
                <span class="text-[10.5px] font-mono uppercase tracking-[0.12em] text-ink-500">{{ __('Max products') }}</span>
                <input type="number" name="concierge_max" min="1" max="30"
                    value="{{ $auto['concierge_max'] ?? 10 }}"
                    class="mt-1 w-full border border-paper-200 rounded-lg px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
            </label>
        </div>
    </div>

    <div class="flex justify-end mt-4 pt-3 border-t border-paper-200">
        <button class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Save auto-share') }}</button>
    </div>
</form>
