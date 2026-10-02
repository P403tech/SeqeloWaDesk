<x-layouts.user :title="__('Drip Campaign')" nav-key="drip" page="user-drip-campaigns-edit">

    @php
        $isNew = ! $campaign->exists;

        // Built here, NOT inline in the attribute. A multi-line closure inside
        // @json() sitting in an HTML attribute is fragile, and it blows up the
        // whole page if $steps is ever missing. Coerced through collect() so a
        // null/array/Collection all behave, and `body` is read once here rather
        // than decrypting inside the attribute.
        // Merge-tag braces built by concatenation so the literal sequence never
        // appears in the template. Blade reads {{name}} as an echo wherever it
        // finds it — including inside a string — and rewrites the surrounding
        // line into broken PHP. Same guard the Shopify view uses.
        $lb = '{' . '{';
        $rb = '}' . '}';
        $bodyPlaceholder = __('Message to send. Use :a or :b for personalisation.', [
            'a' => $lb . 'name' . $rb,
            'b' => $lb . 'first_name' . $rb,
        ]);

        $stepsPayload = collect($steps ?? [])->map(fn ($s) => [
            'delay_amount' => (int) ($s->delay_amount ?? 0),
            'delay_unit'   => (string) ($s->delay_unit ?? 'hour'),
            'body'         => (string) ($s->body ?? ''),
            'template_id'  => $s->template_id ?? null,
            'var_map'      => is_array($s->var_map ?? null) ? $s->var_map : [],
        ])->values();
    @endphp

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">

        @if (session('success'))
            <div class="mb-5 px-4 py-2.5 rounded-xl bg-wa-bubble border border-wa-green/30 text-[12.5px] text-wa-deep inline-flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="m4 8 3 3 5-6" />
                </svg>
                {{ session('success') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-5 px-4 py-2.5 rounded-xl bg-accent-coral/10 border border-accent-coral/30 text-[12.5px] text-accent-coral">
                @foreach ($errors->all() as $e)
                    <div>{{ $e }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST"
            action="{{ $isNew ? route('user.drip.store') : route('user.drip.update', $campaign->id) }}"
            id="drip-form" data-steps='@json($stepsPayload)'
            data-param-counts='@json($paramCounts ?? [])'
            data-test-url="{{ $isNew ? '' : route('user.drip.test', $campaign->id) }}">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

                {{-- ============ SETTINGS RAIL ============ --}}
                <aside data-keep-rail class="space-y-3">
                    <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card space-y-3">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Settings') }}</div>

                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Name') }}</span>
                            <input type="text" name="name" required maxlength="191"
                                value="{{ old('name', $campaign->name) }}"
                                placeholder="{{ __('Patient follow-up') }}"
                                class="mt-1 w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        </label>

                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Who enters') }}</span>
                            <select name="trigger_type" id="drip-trigger"
                                class="mt-1 w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                @foreach (\App\Models\DripCampaign::TRIGGERS as $k => $label)
                                    <option value="{{ $k }}" @selected(old('trigger_type', $campaign->trigger_type) === $k)>
                                        {{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block" id="drip-trigger-value-wrap">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Tag / group') }}</span>
                            <input type="text" name="trigger_value" maxlength="191"
                                value="{{ old('trigger_value', $campaign->trigger_value) }}"
                                placeholder="{{ __('e.g. consult-booked') }}"
                                class="mt-1 w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        </label>

                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Timezone') }}</span>
                            @php
                                // Canonical IANA list, same source the account page uses.
                                // safe_timezone() normalises a legacy stored value
                                // (Asia/Calcutta → Asia/Kolkata) which Carbon rejects, so the
                                // right option is pre-selected instead of falling through.
                                $wsTz   = auth()->user()?->currentWorkspace?->timezone ?: config('app.timezone', 'UTC');
                                $tzNow  = safe_timezone(old('timezone', $campaign->timezone ?: $wsTz), 'UTC');
                                try { $tzList = \DateTimeZone::listIdentifiers(); }
                                catch (\Throwable $e) { $tzList = ['UTC', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Dubai', 'Europe/London', 'America/New_York']; }
                            @endphp
                            <select name="timezone" required
                                class="mt-1 w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                @foreach ($tzList as $z)
                                    <option value="{{ $z }}" @selected($tzNow === $z)>{{ $z }}</option>
                                @endforeach
                            </select>
                            <p class="text-[10.5px] text-ink-500 mt-1">
                                {{ __('Quiet hours are read in this timezone.') }}</p>
                        </label>

                        <div>
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Quiet hours') }}</span>
                            <div class="mt-1 flex items-center gap-2">
                                <input type="number" name="quiet_start_hour" min="0" max="23"
                                    value="{{ old('quiet_start_hour', $campaign->quiet_start_hour) }}"
                                    placeholder="21"
                                    class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                <span class="text-[11px] text-ink-500">{{ __('to') }}</span>
                                <input type="number" name="quiet_end_hour" min="0" max="23"
                                    value="{{ old('quiet_end_hour', $campaign->quiet_end_hour) }}"
                                    placeholder="9"
                                    class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            </div>
                            <p class="text-[10.5px] text-ink-500 mt-1">
                                {{ __('A step due inside this window waits until it opens. Leave blank to send any time.') }}
                            </p>
                        </div>

                        <label class="flex items-start gap-2 pt-1">
                            <input type="hidden" name="stop_on_reply" value="0">
                            <input type="checkbox" name="stop_on_reply" value="1"
                                @checked(old('stop_on_reply', $campaign->stop_on_reply ?? true)) class="mt-0.5">
                            <span class="text-[11.5px] text-ink-700 leading-snug">{{ __('Stop when the contact replies') }}
                                <span class="block text-[10.5px] text-ink-500">{{ __('Hands the conversation to a human instead of continuing to chase.') }}</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2">
                            <input type="hidden" name="stop_on_deal_won" value="0">
                            <input type="checkbox" name="stop_on_deal_won" value="1"
                                @checked(old('stop_on_deal_won', $campaign->stop_on_deal_won)) class="mt-0.5">
                            <span class="text-[11.5px] text-ink-700 leading-snug">{{ __('Stop when their deal is won') }}</span>
                        </label>

                        <div class="pt-2 border-t border-paper-100">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Goal') }}</span>
                            <p class="text-[10.5px] text-ink-500 mb-1">
                                {{ __('The outcome this sequence is chasing. Once it happens the remaining steps are cancelled.') }}
                            </p>
                            <select name="goal_type" id="drip-goal"
                                class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                @foreach (\App\Models\DripCampaign::GOALS as $k => $label)
                                    <option value="{{ $k }}" @selected(old('goal_type', $campaign->goal_type ?? '') === $k)>
                                        {{ __($label) }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="goal_value" id="drip-goal-value" maxlength="191"
                                value="{{ old('goal_value', $campaign->goal_value) }}"
                                placeholder="{{ __('which tag, e.g. booked') }}"
                                class="mt-1.5 w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        </div>

                        <button type="submit"
                            class="w-full px-4 py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">
                            {{ $isNew ? __('Create campaign') : __('Save changes') }}
                        </button>
                    </div>

                    @unless ($isNew)
                        <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card text-[12px] text-ink-600">
                            <div class="font-semibold text-ink-900 mb-1">{{ __('Status') }}:
                                <span class="font-mono">{{ $campaign->status }}</span></div>
                            {{ __('Activate from the campaigns list. Pausing keeps queued steps — they resume where they left off.') }}
                        </div>

                        @if (!empty($stats))
                            <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                                    {{ __('Performance') }}</div>

                                <div class="grid grid-cols-2 gap-2 text-[12px]">
                                    @foreach ([
                                        __('Enrolled')  => $stats['totals']['enrolled'],
                                        __('In flight') => $stats['totals']['active'],
                                        __('Completed') => $stats['totals']['completed'],
                                        __('Replied')   => $stats['totals']['stopped'],
                                    ] as $k => $v)
                                        <div class="rounded-lg bg-paper-50 px-2.5 py-2">
                                            <div class="font-mono text-[9.5px] uppercase text-ink-500">{{ $k }}</div>
                                            <div class="font-serif text-[18px] leading-none mt-1 tabular-nums">{{ number_format($v) }}</div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="mt-3 pt-3 border-t border-paper-100 text-[11.5px] space-y-1">
                                    <div class="flex justify-between"><span class="text-ink-500">{{ __('Messages sent') }}</span>
                                        <span class="font-mono">{{ number_format($stats['totals']['sent']) }}</span></div>
                                    <div class="flex justify-between"><span class="text-ink-500">{{ __('Failed sends') }}</span>
                                        <span class="font-mono {{ $stats['totals']['failed_sends'] > 0 ? 'text-accent-coral' : '' }}">{{ number_format($stats['totals']['failed_sends']) }}</span></div>
                                    @if ($stats['failing'] > 0)
                                        <div class="flex justify-between"><span class="text-ink-500">{{ __('Retrying now') }}</span>
                                            <span class="font-mono text-accent-amber">{{ $stats['failing'] }}</span></div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endunless
                </aside>

                {{-- ============ STEP BUILDER ============ --}}
                <section class="space-y-4">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                                <a href="{{ route('user.drip.index') }}" class="hover:text-wa-deep">{{ __('Drip campaigns') }}</a>
                                <span class="mx-1.5 text-ink-500/60">/</span>
                                <span>{{ $isNew ? __('New') : __('Edit') }}</span>
                            </div>
                            <h1 class="font-serif font-normal tracking-tight text-[28px] sm:text-[34px] leading-none">
                                {{ __('The') }} <span class="italic text-wa-deep">{{ __('sequence') }}</span></h1>
                            <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                                {{ __('Each step waits, then sends. The wait is measured from the previous step — so "1 day" on step 2 means one day after step 1 went out.') }}
                            </p>
                        </div>
                        <button type="button" id="drip-add-step"
                            class="px-4 py-2 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium inline-flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M8 3v10M3 8h10" />
                            </svg>
                            {{ __('Add step') }}
                        </button>
                    </div>

                    <div id="drip-steps" class="space-y-3"></div>

                    <template id="drip-step-template">
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4" data-step>
                            <div class="flex items-center gap-3 mb-3">
                                <span class="w-8 h-8 rounded-full bg-wa-mint text-wa-deep grid place-items-center font-mono text-[12px]"
                                    data-step-number>1</span>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11.5px] text-ink-600">{{ __('Wait') }}</span>
                                    <input type="number" min="0" data-delay-amount value="0"
                                        class="w-20 px-2 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <select data-delay-unit
                                        class="px-2 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                        <option value="minute">{{ __('minutes') }}</option>
                                        <option value="hour" selected>{{ __('hours') }}</option>
                                        <option value="day">{{ __('days') }}</option>
                                    </select>
                                    <span class="text-[11.5px] text-ink-500" data-delay-hint></span>
                                </div>
                                <div class="ml-auto flex items-center gap-1.5">
                                    <button type="button" data-test-step
                                        class="px-2.5 py-1.5 rounded-full border border-paper-200 hover:bg-paper-50 text-[11.5px] font-semibold inline-flex items-center gap-1.5"
                                        title="{{ __('Send this step to a number now') }}">
                                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor"
                                            stroke-width="1.7">
                                            <path d="M2 8h10M9 4l4 4-4 4" />
                                        </svg>
                                        {{ __('Test') }}
                                    </button>
                                    <button type="button" data-remove-step
                                        class="px-2.5 py-1.5 rounded-full text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-semibold">{{ __('Remove') }}</button>
                                </div>
                            </div>

                            <textarea data-body rows="3" placeholder="{{ $bodyPlaceholder }}"
                                class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep"></textarea>

                            <div class="mt-2 flex items-center gap-2">
                                <span class="text-[11px] text-ink-500">{{ __('or send an approved template') }}</span>
                                <select data-template
                                    class="px-2 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep">
                                    <option value="">— {{ __('none') }} —</option>
                                    @foreach ($templates as $t)
                                        <option value="{{ $t->id }}">{{ $t->template_name }} · {{ strtoupper($t->language) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- One picker per positional slot the chosen template declares.
                                 Rendered by JS from data-param-counts, because the count only
                                 becomes known once a template is picked. --}}
                            <div class="mt-2 hidden" data-varmap></div>

                            <p class="mt-2 text-[10.5px] text-ink-500" data-template-note hidden>
                                {{ __('After 24 hours of silence WhatsApp only delivers approved templates — steps a day or more out should use one.') }}
                            </p>
                        </div>
                    </template>

                    <div id="drip-empty"
                        class="bg-paper-0 border border-dashed border-paper-200 rounded-2xl p-8 text-center text-[12.5px] text-ink-500">
                        {{ __('No steps yet. Add the first message — set its wait to 0 to send as soon as someone is enrolled.') }}
                    </div>
                </section>
            </div>
        </form>

        @unless ($isNew)
            {{-- Manual enrolment --}}
            <form method="POST" action="{{ route('user.drip.enrol', $campaign->id) }}"
                class="mt-5 bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                @csrf
                <h3 class="font-serif text-[18px] leading-tight">{{ __('Enrol contacts') }}</h3>
                <p class="text-[11.5px] text-ink-500 mt-1 mb-3">
                    {{ __('Adds everyone in a group at step 1. Already-enrolled contacts are skipped, so you can run this again safely.') }}
                </p>
                <div class="flex items-end gap-3 flex-wrap">
                    <label class="block">
                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Contact group') }}</span>
                        <select name="group_id"
                            class="mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">— {{ __('pick a group') }} —</option>
                            @foreach ($groups as $g)
                                <option value="{{ $g->id }}">{{ $g->user_group }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit"
                        class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">
                        {{ __('Enrol group') }}
                    </button>
                </div>
            </form>
        @endunless
    </main>

</x-layouts.user>
