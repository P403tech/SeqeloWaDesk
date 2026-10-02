<x-layouts.user :title="__('AI SDR')" nav-key="sdr" page="user-sdr-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7" id="sdr-root">
        <script id="sdr-seed" type="application/json">{!! json_encode($sdrSeed) !!}</script>

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <div class="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="5" r="2.4"/><path d="M3.5 13.5c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/></svg>
                    {{ __('Sales · Automation') }}
                </div>
                <h1 class="font-serif text-[26px] leading-tight">{{ __('AI') }} <span class="italic text-wa-deep">{{ __('SDR') }}</span></h1>
                <p class="text-[12.5px] text-ink-500 mt-1 max-w-2xl">{{ __('Qualify leads, run a personalised multi-channel cadence, score every reply, hand hot leads to your sales team, and stop chasing the moment a lead replies or converts.') }}</p>
            </div>
        </div>

        {{-- Embedded how-to for the operator/customer --}}
        <details class="mb-7 border border-paper-200 rounded-2xl bg-wa-mint/40 overflow-hidden" open>
            <summary class="cursor-pointer select-none px-5 py-3.5 flex items-center justify-between gap-3">
                <span class="inline-flex items-center gap-2 font-semibold text-[13.5px] text-wa-deep">
                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.2v3.3M8 5.2h.01"/></svg>
                    {{ __('How the AI SDR works') }}
                </span>
                <span class="text-[11px] font-mono text-ink-500">{{ __('tap to toggle') }}</span>
            </summary>
            <div class="px-5 pb-5 pt-1 text-[12.5px] text-ink-700 leading-relaxed">
                <ol class="space-y-2.5">
                    <li><span class="font-semibold text-wa-deep">1. {{ __('Build the cadence in Flows.') }}</span>
                        {{ __('Create a flow with your outreach steps — messages, delays, channels (WhatsApp, email, SMS…), even AI replies and meeting booking. That flow IS the SDR\'s outreach.') }}</li>
                    <li><span class="font-semibold text-wa-deep">2. {{ __('Add scoring rules (below).') }}</span>
                        {{ __('Decide what earns points: a reply, a keyword like "pricing", a booked meeting, a won deal. Points build a 0–100 score and an A–D grade for every lead.') }}</li>
                    <li><span class="font-semibold text-wa-deep">3. {{ __('Create a campaign.') }}</span>
                        {{ __('Pick the cadence flow, set the minimum score to enrol, the score at which a hot lead is handed to your sales team (and which team), and whether to stop when a lead replies or converts.') }}</li>
                    <li><span class="font-semibold text-wa-deep">4. {{ __('It runs itself.') }}</span>
                        {{ __('Leads enrol through your flow triggers; the SDR nurtures them, scores every interaction, routes A/B leads to your team, and stops chasing the moment they reply or buy — so no one gets over-messaged.') }}</li>
                </ol>
                <div class="mt-3.5 flex flex-wrap gap-2 text-[11px] font-mono">
                    <span class="px-2 py-0.5 rounded-full bg-paper-0 border border-paper-200">A · ≥75 {{ __('hot') }}</span>
                    <span class="px-2 py-0.5 rounded-full bg-paper-0 border border-paper-200">B · ≥50 {{ __('warm') }}</span>
                    <span class="px-2 py-0.5 rounded-full bg-paper-0 border border-paper-200">C · ≥25</span>
                    <span class="px-2 py-0.5 rounded-full bg-paper-0 border border-paper-200">D · {{ __('new') }}</span>
                </div>
            </div>
        </details>

        {{-- Campaigns --}}
        <section class="mb-9">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-serif text-[18px]">{{ __('Campaigns') }}</h2>
                <button data-sdr-new-campaign type="button"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal transition">
                    <span class="text-[15px] leading-none">+</span> {{ __('New campaign') }}
                </button>
            </div>
            <div id="sdr-campaigns" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
            <div id="sdr-campaigns-empty" class="hidden text-[13px] text-ink-500 border border-dashed border-paper-200 rounded-2xl p-8 text-center">
                {{ __('No campaigns yet. Create one and point it at a cadence flow to start qualifying and nurturing leads automatically.') }}
            </div>
        </section>

        {{-- Scoring rules --}}
        <section>
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="font-serif text-[18px]">{{ __('Lead scoring rules') }}</h2>
                    <p class="text-[12px] text-ink-500">{{ __('Each signal adds or subtracts points; the score (0–100) grades every lead A–D and drives the hand-off.') }}</p>
                </div>
                <button data-sdr-new-rule type="button"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl border border-paper-200 bg-paper-0 hover:border-wa-deep text-[12.5px] font-semibold text-ink-700 hover:text-wa-deep transition">
                    <span class="text-[15px] leading-none">+</span> {{ __('New rule') }}
                </button>
            </div>
            <div class="overflow-x-auto border border-paper-200 rounded-2xl bg-paper-0">
                <table class="w-full text-[12.5px]">
                    <thead class="text-ink-500 font-mono text-[10px] uppercase tracking-[0.12em] border-b border-paper-200">
                        <tr>
                            <th class="text-left font-medium px-4 py-2.5">{{ __('Rule') }}</th>
                            <th class="text-left font-medium px-4 py-2.5">{{ __('Signal') }}</th>
                            <th class="text-right font-medium px-4 py-2.5">{{ __('Points') }}</th>
                            <th class="text-right font-medium px-4 py-2.5">{{ __('Fired') }}</th>
                            <th class="text-right font-medium px-4 py-2.5">{{ __('Active') }}</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody id="sdr-rules"></tbody>
                </table>
                <div id="sdr-rules-empty" class="hidden text-[13px] text-ink-500 p-8 text-center">
                    {{ __('No scoring rules yet — add one so replies, bookings and won deals move the score.') }}
                </div>
            </div>
        </section>

        {{-- ── Campaign modal ─────────────────────────────────────────── --}}
        <div id="sdr-camp-modal" class="hidden fixed inset-0 z-[70] bg-ink-900/40 grid place-items-center p-4">
            <div class="w-full max-w-lg bg-paper-0 rounded-2xl shadow-2xl border border-paper-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-[16px] font-semibold" data-camp-title>{{ __('New campaign') }}</h3>
                    <button data-camp-close class="text-ink-400 hover:text-ink-900 text-[18px] leading-none">&times;</button>
                </div>
                <input type="hidden" data-camp-id>
                <div class="space-y-3.5">
                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Name') }}</label>
                        <input data-camp-name type="text" class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                    </div>
                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Cadence flow') }}</label>
                        <select data-camp-flow class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep"></select>
                        <p class="text-[11px] text-ink-500 mt-1">{{ __('The flow that sends the multi-step, multi-channel outreach. Build it in Flows.') }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Enrol min score') }}</label>
                            <input data-camp-min type="number" min="0" max="100" value="0" class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                        </div>
                        <div>
                            <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Route at score') }}</label>
                            <input data-camp-route type="number" min="0" max="100" placeholder="{{ __('never') }}" class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Route to team') }}</label>
                        <select data-camp-team class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep"></select>
                    </div>
                    <div class="flex flex-wrap gap-4 pt-1">
                        <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-700"><input data-camp-active type="checkbox" checked class="rounded"> {{ __('Active') }}</label>
                        <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-700"><input data-camp-reply type="checkbox" checked class="rounded"> {{ __('Stop on reply') }}</label>
                        <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-700"><input data-camp-convert type="checkbox" checked class="rounded"> {{ __('Stop on convert') }}</label>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-5">
                    <button data-camp-cancel class="px-3 py-2 rounded-xl text-[12.5px] font-semibold text-ink-600 hover:bg-paper-50">{{ __('Cancel') }}</button>
                    <button data-camp-save class="px-4 py-2 rounded-xl bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save') }}</button>
                </div>
            </div>
        </div>

        {{-- ── Rule modal ─────────────────────────────────────────────── --}}
        <div id="sdr-rule-modal" class="hidden fixed inset-0 z-[70] bg-ink-900/40 grid place-items-center p-4">
            <div class="w-full max-w-md bg-paper-0 rounded-2xl shadow-2xl border border-paper-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-[16px] font-semibold" data-rule-title>{{ __('New rule') }}</h3>
                    <button data-rule-close class="text-ink-400 hover:text-ink-900 text-[18px] leading-none">&times;</button>
                </div>
                <input type="hidden" data-rule-id>
                <div class="space-y-3.5">
                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Name') }}</label>
                        <input data-rule-name type="text" class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Signal') }}</label>
                            <select data-rule-signal class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep"></select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Points (±)') }}</label>
                            <input data-rule-points type="number" min="-100" max="100" value="10" class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                        </div>
                    </div>
                    <div data-rule-kw-wrap class="hidden">
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Text contains (optional)') }}</label>
                        <input data-rule-kw type="text" placeholder="{{ __('e.g. pricing') }}" class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                    </div>
                    <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-700"><input data-rule-active type="checkbox" checked class="rounded"> {{ __('Active') }}</label>
                </div>
                <div class="flex items-center justify-end gap-2 mt-5">
                    <button data-rule-cancel class="px-3 py-2 rounded-xl text-[12.5px] font-semibold text-ink-600 hover:bg-paper-50">{{ __('Cancel') }}</button>
                    <button data-rule-save class="px-4 py-2 rounded-xl bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    </main>
</x-layouts.user>
