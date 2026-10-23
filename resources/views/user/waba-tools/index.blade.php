<x-layouts.user :title="__('WhatsApp Tools — Official WABA')" nav-key="waba-tools" page="user-waba-tools">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7" data-waba-tools>
        @unless ($connected)
            <div class="max-w-xl mx-auto bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6 text-center">
                <div class="w-12 h-12 rounded-xl bg-accent-amber/15 grid place-items-center mx-auto mb-3">
                    <svg viewBox="0 0 24 24" class="w-6 h-6 text-accent-amber" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 8v4M12 16h.01" /><circle cx="12" cy="12" r="9" /></svg>
                </div>
                <div class="font-serif text-[20px] leading-tight">{{ __('Connect an official WhatsApp number') }}</div>
                <p class="text-[12.5px] text-ink-600 mt-1.5">{{ __('These tools use the official WhatsApp (WABA) Cloud API. Connect a WABA number to use them.') }}</p>
                <a href="{{ url('/devices') }}" class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Connect a number') }}</a>
            </div>
        @else
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

            <!-- =============== SIDEBAR =============== -->
            <aside class="space-y-3 lg:sticky lg:top-4 self-start">
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                    <div class="w-12 h-12 rounded-xl mb-3 grid place-items-center bg-wa-bubble">
                        <svg viewBox="0 0 24 24" class="w-7 h-7 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                    </div>
                    <div class="font-serif text-[18px] leading-tight">{{ __('WhatsApp Tools') }}</div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mt-1">{{ __('Official WhatsApp · WABA') }}</div>
                    <div class="mt-3 inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-mono bg-wa-mint text-wa-deep border border-wa-green/40">
                        <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}
                    </div>
                </div>

                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">{{ __('Jump to') }}</div>
                    <nav class="px-1 pb-1">
                        @foreach ([['receipts', __('Read receipts & typing')], ['profile', __('Business profile')], ['automation', __('Welcome & commands')], ['qr', __('QR codes')], ['pin', __('Two-step PIN')], ['analytics', __('Analytics')]] as [$anchor, $label])
                            <a href="#sec-{{ $anchor }}" class="block px-3 py-2 rounded-lg text-[12.5px] text-ink-700 hover:bg-paper-50 hover:text-wa-deep">{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>
            </aside>

            <!-- =============== MAIN =============== -->
            <div class="space-y-5 min-w-0">

                {{-- Read receipts + typing --}}
                <section id="sec-receipts" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 scroll-mt-4">
                    <div class="font-serif text-[18px]">{{ __('Read receipts & typing') }}</div>
                    <div class="text-[11.5px] text-ink-500 mt-0.5 mb-3">{{ __('Show customers the blue double-ticks when their message is received, and a "typing…" bubble while a reply is prepared. How: tick what you want and Save — it then applies automatically to every new incoming message.') }}</div>
                    <label class="flex items-center gap-3 text-[12.5px] mb-2">
                        <input type="checkbox" data-read-receipts @checked($readReceipts) class="w-4 h-4 rounded border-paper-200 accent-wa-deep">
                        <span class="font-semibold">{{ __('Send read receipts (blue ticks)') }}</span>
                    </label>
                    <label class="flex items-center gap-3 text-[12.5px]">
                        <input type="checkbox" data-typing @checked($typing) class="w-4 h-4 rounded border-paper-200 accent-wa-deep">
                        <span class="font-semibold">{{ __('Show typing indicator') }}</span>
                    </label>
                    <div class="flex justify-end mt-3"><button type="button" data-save-receipts class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Save') }}</button></div>
                </section>

                {{-- Business profile --}}
                <section id="sec-profile" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 scroll-mt-4" data-profile>
                    <div class="flex items-center justify-between"><div class="font-serif text-[18px]">{{ __('Business profile') }}</div>
                        <button type="button" data-profile-load class="text-[11.5px] text-wa-deep font-semibold hover:underline">{{ __('Load current') }}</button></div>
                    <div class="text-[11.5px] text-ink-500 mt-0.5">{{ __('How: tap "Load current" to pull what is live on WhatsApp, edit any field, then "Save profile". Changes apply to your public business profile instantly.') }}</div>
                    <div class="grid sm:grid-cols-2 gap-3 mt-3">
                        <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('About') }}</span>
                            <input data-f="about" maxlength="139" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                        <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Description') }}</span>
                            <textarea data-f="description" rows="2" maxlength="512" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></textarea></label>
                        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Email') }}</span>
                            <input data-f="email" type="email" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Industry / vertical') }}</span>
                            <input data-f="vertical" placeholder="RETAIL" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
                        <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Address') }}</span>
                            <input data-f="address" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Website 1') }}</span>
                            <input data-f="web0" type="url" placeholder="https://" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
                        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Website 2') }}</span>
                            <input data-f="web1" type="url" placeholder="https://" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
                    </div>
                    <div class="flex justify-end mt-3"><button type="button" data-profile-save class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Save profile') }}</button></div>
                </section>

                {{-- Conversational automation --}}
                <section id="sec-automation" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 scroll-mt-4" data-automation>
                    <div class="flex items-center justify-between"><div class="font-serif text-[18px]">{{ __('Welcome, ice breakers & commands') }}</div>
                        <button type="button" data-automation-load class="text-[11.5px] text-wa-deep font-semibold hover:underline">{{ __('Load current') }}</button></div>
                    <div class="text-[11.5px] text-ink-500 mt-0.5">{{ __('How: these appear natively in the customer chat. Tick welcome for a first-contact greeting, add up to 4 tap-to-start ice breakers and /commands, then Save. Use "Load current" to see what is live now.') }}</div>
                    <label class="flex items-center gap-3 text-[12.5px] mt-3">
                        <input type="checkbox" data-welcome class="w-4 h-4 rounded border-paper-200 accent-wa-deep">
                        <span class="font-semibold">{{ __('Greet first-time users with a welcome message') }}</span>
                    </label>
                    <div class="mt-3">
                        <div class="text-[11px] font-semibold text-ink-700 mb-1">{{ __('Ice breakers (up to 4, tap-to-start prompts)') }}</div>
                        <div class="grid sm:grid-cols-2 gap-2">
                            @for ($i = 0; $i < 4; $i++)
                                <input data-ib="{{ $i }}" maxlength="80" placeholder="{{ __('e.g. See our menu') }}" class="rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            @endfor
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="flex items-center justify-between mb-1">
                            <div class="text-[11px] font-semibold text-ink-700">{{ __('Commands (e.g. /menu, /support)') }}</div>
                            <button type="button" data-add-command class="text-[11px] text-wa-deep font-semibold hover:underline">+ {{ __('Add command') }}</button>
                        </div>
                        <div data-commands class="space-y-2"></div>
                    </div>
                    <div class="flex justify-end mt-3"><button type="button" data-automation-save class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Save') }}</button></div>
                </section>

                {{-- QR codes --}}
                <section id="sec-qr" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 scroll-mt-4" data-qr>
                    <div class="flex items-center justify-between"><div class="font-serif text-[18px]">{{ __('QR codes & short links') }}</div>
                        <button type="button" data-qr-load class="text-[11.5px] text-wa-deep font-semibold hover:underline">{{ __('Refresh') }}</button></div>
                    <div class="text-[11.5px] text-ink-500 mt-0.5 mb-3">{{ __('Generate a QR / click-to-chat link that opens a chat with a prefilled message. How: type the message, pick PNG or SVG, press Create — then share the link or print the QR image. Delete removes it from Meta.') }}</div>
                    <div class="flex items-end gap-3 flex-wrap">
                        <label class="block flex-1 min-w-[200px]"><span class="text-[11px] font-semibold text-ink-700">{{ __('Prefilled message') }}</span>
                            <input data-qr-msg placeholder="{{ __('Hi! I want the candle offer') }}" class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                        <select data-qr-fmt class="rounded-xl border border-paper-200 px-3 py-2 text-[12.5px]"><option value="PNG">PNG</option><option value="SVG">SVG</option></select>
                        <button type="button" data-qr-create class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Create') }}</button>
                    </div>
                    <div data-qr-list class="mt-4 space-y-2"></div>
                </section>

                {{-- Two-step PIN --}}
                <section id="sec-pin" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 scroll-mt-4">
                    <div class="font-serif text-[18px]">{{ __('Two-step verification PIN') }}</div>
                    <div class="text-[11.5px] text-ink-500 mt-0.5 mb-3">{{ __('Set the 6-digit PIN Meta asks for when re-registering this number. How: enter 6 digits and press "Set PIN" — keep it somewhere safe.') }}</div>
                    <div class="flex items-end gap-3">
                        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('New 6-digit PIN') }}</span>
                            <input data-pin inputmode="numeric" maxlength="6" class="mt-1 w-40 rounded-xl border border-paper-200 px-3 py-2 text-[14px] font-mono tracking-[0.3em] focus:outline-none focus:border-wa-deep"></label>
                        <button type="button" data-pin-save class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Set PIN') }}</button>
                    </div>
                </section>

                {{-- Analytics --}}
                <section id="sec-analytics" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 scroll-mt-4" data-analytics>
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="font-serif text-[18px]">{{ __('Messaging analytics') }}</div>
                        <div class="flex items-center gap-2">
                            <select data-an-days class="rounded-xl border border-paper-200 px-3 py-1.5 text-[12px]">
                                <option value="7">{{ __('Last 7 days') }}</option>
                                <option value="30" selected>{{ __('Last 30 days') }}</option>
                                <option value="90">{{ __('Last 90 days') }}</option>
                            </select>
                            <button type="button" data-an-load class="px-3 py-1.5 rounded-full border border-paper-200 hover:bg-paper-50 text-[11.5px] font-medium">{{ __('Load') }}</button>
                        </div>
                    </div>
                    <div class="text-[11.5px] text-ink-500 mt-1">{{ __('How: pick a date range and press "Load" to see messages sent and delivered for your number, straight from Meta.') }}</div>
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div class="border border-paper-200 rounded-xl p-4"><div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('Sent') }}</div>
                            <div class="font-serif text-[28px] leading-none mt-2" data-an-sent>—</div></div>
                        <div class="border border-paper-200 rounded-xl p-4"><div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('Delivered') }}</div>
                            <div class="font-serif text-[28px] leading-none mt-2 text-wa-deep" data-an-delivered>—</div></div>
                    </div>
                </section>
            </div>
        </div>
        @endunless
    </main>

    <div id="toast" class="toast"></div>
</x-layouts.user>
