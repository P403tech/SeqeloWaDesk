<x-layouts.user :title="__('Blocked contacts — Unified Inbox')" nav-key="team-inbox" page="user-team-inbox-blocked">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7" data-blocked-page>
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

            <!-- =============== SIDEBAR =============== -->
            <aside class="space-y-3">
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                    <div class="w-12 h-12 rounded-xl mb-3 grid place-items-center bg-wa-bubble">
                        <svg viewBox="0 0 24 24" class="w-7 h-7 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6">
                            <circle cx="12" cy="12" r="9" /><path d="M5.6 5.6l12.8 12.8" />
                        </svg>
                    </div>
                    <div class="font-serif text-[18px] leading-tight">{{ __('Blocked contacts') }}</div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mt-1">{{ __('Unified Inbox') }}</div>
                    <div class="mt-3 inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-mono bg-paper-50 border border-paper-200 text-ink-700">
                        <span class="w-1.5 h-1.5 rounded-full bg-paper-200"></span>
                        <span data-blocked-count>{{ __('Loading…') }}</span>
                    </div>
                    <a href="{{ route('user.team-inbox') }}" class="mt-4 inline-flex items-center gap-1.5 text-[11.5px] text-wa-deep font-semibold hover:underline">
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 4 6 8l4 4" /></svg>
                        {{ __('Back to inbox') }}
                    </a>
                </div>

                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">{{ __('How it works') }}</div>
                    <ol class="px-1 space-y-0.5 pb-1">
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-wa-deep text-paper-0 shrink-0 mt-0.5">1</span>
                            {{ __('Block a number here, or block a chat from the inbox') }}
                        </li>
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-paper-100 text-ink-500 shrink-0 mt-0.5">2</span>
                            {{ __('Their chats stop showing on every channel') }}
                        </li>
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-paper-100 text-ink-500 shrink-0 mt-0.5">3</span>
                            {{ __('Official WhatsApp is also blocked at Meta') }}
                        </li>
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-paper-100 text-ink-500 shrink-0 mt-0.5">4</span>
                            {{ __('Unblock anytime to restore their chats') }}
                        </li>
                    </ol>
                </div>
            </aside>

            <!-- =============== MAIN =============== -->
            <div class="space-y-5 min-w-0">

                <!-- Block a number -->
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="font-serif text-[18px] leading-tight">{{ __('Block a number') }}</div>
                    <div class="text-[11.5px] text-ink-500 mt-0.5 mb-3">{{ __('Their messages stop showing in the inbox on every channel. On official WhatsApp (WABA) they are also blocked at Meta.') }}</div>
                    <div class="flex items-end gap-3 flex-wrap">
                        <label class="block flex-1 min-w-[200px]">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Phone number (with country code)') }}</span>
                            <input type="tel" data-block-phone placeholder="+91 98765 43210"
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                        </label>
                        <button type="button" data-block-btn
                            class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Block') }}</button>
                    </div>
                </div>

                <!-- Blocked list -->
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between gap-3 flex-wrap">
                        <h3 class="font-serif text-[18px] leading-tight">{{ __('Blocked') }}</h3>
                        <div class="flex items-center gap-2">
                            <button type="button" data-refresh
                                class="px-3 py-1.5 rounded-full border border-paper-200 hover:bg-paper-50 text-[11.5px] font-medium">{{ __('Refresh') }}</button>
                            <button type="button" data-unblock-selected disabled
                                class="px-3.5 py-1.5 rounded-full bg-wa-deep text-paper-0 text-[11.5px] font-semibold disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Unblock selected') }}</button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 border-b border-paper-200 text-ink-500">
                                <tr>
                                    <th class="px-4 py-2.5 w-10 text-left">
                                        <input type="checkbox" data-select-all class="rounded border-paper-200 accent-wa-deep">
                                    </th>
                                    <th class="px-2 py-2.5 text-left font-mono text-[10px] uppercase tracking-[0.14em]">{{ __('Contact') }}</th>
                                    <th class="px-2 py-2.5 text-left font-mono text-[10px] uppercase tracking-[0.14em]">{{ __('Channel') }}</th>
                                    <th class="px-2 py-2.5 text-left font-mono text-[10px] uppercase tracking-[0.14em]">{{ __('Blocked') }}</th>
                                    <th class="px-4 py-2.5 text-right"></th>
                                </tr>
                            </thead>
                            <tbody data-rows class="divide-y divide-paper-200"></tbody>
                        </table>
                    </div>

                    <div data-empty hidden class="px-5 py-12 text-center text-ink-500 text-[12.5px]">
                        {{ __('No blocked contacts. Block a number above, or block a chat from the inbox.') }}
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="toast" class="toast"></div>
</x-layouts.user>
