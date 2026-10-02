@php
    // Contact columns a question can be mapped onto. Kept in step with
    // MetaLeadsController::cleanMap() — that is the list that actually enforces
    // it; this one only decides what the picker offers.
    $contactColumns = [
        'name'         => __('Full name'),
        'first_name'   => __('First name'),
        'last_name'    => __('Last name'),
        'mobile'       => __('Phone'),
        'email'        => __('Email'),
        'country_code' => __('Country code'),
        'title'        => __('Job title'),
        'address'      => __('Address'),
        'language'     => __('Language'),
    ];

    $statusStyles = [
        'ok'      => ['bg-wa-mint text-wa-deep',            __('Added')],
        'pending' => ['bg-accent-amber/15 text-[#7B5A14]',  __('Waiting')],
        'failed'  => ['bg-accent-coral/12 text-accent-coral', __('Needs attention')],
    ];
@endphp

<x-layouts.user :title="__('Lead Ads')" nav-key="lead-ads" page="user-lead-ads">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">

        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

            {{-- ══════════ Left rail ══════════ --}}
            <aside class="space-y-3">

                {{-- Identity --}}
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <span class="w-9 h-9 rounded-xl grid place-items-center shrink-0" style="background:#1877F2">
                            <svg viewBox="0 0 24 24" class="w-4.5 h-4.5" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                        </span>
                        @if ($pages->isNotEmpty())
                            <span class="px-2 py-0.5 rounded-full bg-wa-mint text-wa-deep text-[10px] font-mono uppercase tracking-[0.12em]">{{ __('Connected') }}</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-paper-100 text-ink-500 text-[10px] font-mono uppercase tracking-[0.12em]">{{ __('Not connected') }}</span>
                        @endif
                    </div>
                    <div class="font-serif text-[18px] leading-tight">{{ __('Lead forms') }}</div>
                    <div class="font-mono text-[10.5px] text-ink-500 mt-1 truncate">
                        {{ $pages->isNotEmpty() ? ($pages->first()->name ?: $pages->first()->page_id) : __('no page yet') }}
                    </div>

                    <form method="POST" action="{{ route('user.lead-ads.sync') }}" class="mt-3">
                        @csrf
                        <button class="w-full px-3 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal transition">
                            {{ __('Sync forms from Facebook') }}
                        </button>
                    </form>
                </div>

                {{-- Form list --}}
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card">
                    <div class="px-2 py-1.5 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Your forms') }}</div>
                    @forelse ($forms as $f)
                        <a href="{{ route('user.lead-ads.index', ['form' => $f->id]) }}"
                           class="flex items-center gap-2 px-2.5 py-2 rounded-xl text-[12.5px] transition {{ $selected && $selected->id === $f->id ? 'bg-wa-deep/8 text-wa-deep font-semibold' : 'text-ink-700 hover:bg-paper-50' }}">
                            <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $f->enabled ? 'bg-wa-green' : 'bg-paper-300' }}"
                                  title="{{ $f->enabled ? __('Active') : __('Paused — leads are stored but not routed') }}"></span>
                            <span class="truncate flex-1">{{ $f->name }}</span>
                            <span class="font-mono text-[10px] text-ink-500 shrink-0">{{ $f->leads_count }}</span>
                        </a>
                    @empty
                        <p class="px-2.5 py-2 text-[12px] text-ink-500 leading-snug">{{ __('No forms yet. Sync to pull the Instant Forms your Page is running.') }}</p>
                    @endforelse
                </div>

                {{-- Good to know --}}
                <div class="border border-wa-green/30 bg-wa-bubble/50 rounded-2xl p-4 text-[12px] text-ink-700 leading-relaxed">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Good to know') }}</div>
                    <p>{{ __('A lead is saved the moment it arrives, before anything else runs. If something goes wrong on the way to your pipeline, the answers are still here and you can retry.') }}</p>
                    <p class="mt-2">{{ __('Facebook removes leads about 90 days after they are submitted, so opening this page also re-checks for anything that never came through.') }}</p>
                </div>
            </aside>

            {{-- ══════════ Main ══════════ --}}
            <section class="space-y-5">

                {{-- Header --}}
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Facebook') }} / {{ __('Lead ads') }}</div>
                    <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">
                        {{ __('Instant form') }} <span class="italic text-wa-deep">{{ __('leads') }}</span>
                    </h1>
                    <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                        {{ __('Someone fills in your ad form on Facebook or Instagram. Decide which answer goes into which field, where the deal lands, and who picks it up — it happens automatically from then on.') }}
                    </p>
                </div>

                {{-- KPI strip --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @php
                        $cards = [
                            ['label' => __('Leads captured'),  'value' => $kpis['total'],  'sub' => __('all time')],
                            ['label' => __('Last 7 days'),     'value' => $kpis['week'],   'sub' => __('recent submissions')],
                            ['label' => __('In your pipeline'),'value' => $kpis['ok'],     'sub' => __('contact + deal created')],
                            ['label' => __('Needs attention'), 'value' => $kpis['failed'], 'sub' => __('waiting or failed')],
                        ];
                    @endphp
                    @foreach ($cards as $c)
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ $c['label'] }}</div>
                            <div class="text-[26px] font-serif leading-none mt-1.5">{{ number_format($c['value']) }}</div>
                            <div class="text-[10.5px] text-ink-400 mt-1">{{ $c['sub'] }}</div>
                        </div>
                    @endforeach
                </div>

                @if ($pages->isEmpty())
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-8 text-center shadow-card">
                        <p class="text-[13.5px] text-ink-700">{{ __('No Facebook Page connected yet.') }}</p>
                        <p class="text-[12px] text-ink-500 mt-1">{{ __('Connect the Page that runs your lead ads, then come back and sync its forms.') }}</p>
                        <a href="{{ url('/devices') }}" class="mt-4 inline-flex px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Connect a Facebook account') }}</a>
                    </div>
                @elseif (! $selected)
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-8 text-center shadow-card">
                        <p class="text-[13.5px] text-ink-700">{{ __('No lead forms found on your Page.') }}</p>
                        <p class="text-[12px] text-ink-500 mt-1 max-w-md mx-auto leading-relaxed">{{ __('Create an Instant Form on your lead ad in Facebook Ads Manager, then press Sync. Forms already collecting leads show up here with their questions.') }}</p>
                    </div>
                @else

                    {{-- ── Form configuration ─────────────────────────────── --}}
                    <form method="POST" action="{{ route('user.lead-ads.forms.update', $selected->id) }}"
                          class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 sm:p-6 space-y-6" data-fbl-form>
                        @csrf

                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-serif text-[22px] leading-tight truncate">{{ $selected->name }}</h2>
                                <p class="font-mono text-[10.5px] text-ink-500 mt-1">
                                    {{ $selected->status }} · {{ $selected->page?->name ?: __('Page') }}
                                    @if ($selected->last_synced_at)
                                        · {{ __('checked :time', ['time' => $selected->last_synced_at->diffForHumans()]) }}
                                    @endif
                                </p>
                            </div>
                            <label class="inline-flex items-center gap-2.5 shrink-0 cursor-pointer">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" name="enabled" value="1" @checked($selected->enabled)
                                       class="rounded border-paper-300 text-wa-deep focus:ring-wa-deep">
                                <span class="text-[12.5px] font-medium text-ink-800">{{ __('Route new leads automatically') }}</span>
                            </label>
                        </div>

                        {{-- Question mapping --}}
                        <div>
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Where each answer goes') }}</div>
                            <p class="text-[10.5px] text-ink-400 leading-snug mb-3">{{ __('Left is what your form asks. Right is where that answer is saved. Anything left unmapped is still kept on the lead itself.') }}</p>

                            @php $questions = (array) ($selected->questions ?? []); @endphp
                            @if (! $questions)
                                <p class="text-[12px] text-ink-500">{{ __('Facebook has not shared this form’s questions yet. Press Sync once the form is live.') }}</p>
                            @else
                                <div class="overflow-x-auto -mx-1 px-1">
                                    <table class="w-full text-[12.5px]">
                                        <tbody class="divide-y divide-paper-100">
                                        @foreach ($questions as $q)
                                            @php
                                                $qKey   = (string) ($q['key'] ?? $q['name'] ?? '');
                                                $qLabel = (string) ($q['label'] ?? $q['name'] ?? $qKey);
                                                if ($qKey === '') continue;
                                                $rule    = (array) (($selected->field_map ?? [])[$qKey] ?? []);
                                                $current = ($rule['target'] ?? '') && ($rule['key'] ?? '')
                                                    ? $rule['target'].':'.$rule['key'] : '';
                                            @endphp
                                            <tr>
                                                <td class="py-2.5 pr-4 align-middle">
                                                    <div class="text-ink-800">{{ $qLabel }}</div>
                                                    <div class="font-mono text-[10px] text-ink-400">{{ $qKey }}</div>
                                                </td>
                                                <td class="py-2.5 w-[280px] align-middle">
                                                    <select name="map[{{ $qKey }}]" data-fbl-map
                                                            class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                                        <option value="">{{ __('Not saved anywhere') }}</option>
                                                        <optgroup label="{{ __('Contact') }}">
                                                            @foreach ($contactColumns as $col => $label)
                                                                <option value="contact:{{ $col }}" @selected($current === 'contact:'.$col)>{{ $label }}</option>
                                                            @endforeach
                                                        </optgroup>
                                                        @if ($contactFields->isNotEmpty())
                                                            <optgroup label="{{ __('Contact — custom field') }}">
                                                                @foreach ($contactFields as $cf)
                                                                    <option value="contact_custom:{{ $cf->key }}" @selected($current === 'contact_custom:'.$cf->key)>{{ $cf->label ?: $cf->key }}</option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endif
                                                        @if ($dealFields->isNotEmpty())
                                                            <optgroup label="{{ __('Deal — custom field') }}">
                                                                @foreach ($dealFields as $df)
                                                                    <option value="deal_custom:{{ $df->key }}" @selected($current === 'deal_custom:'.$df->key)>{{ $df->label ?: $df->key }}</option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endif
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-2">{{ __('Map at least the phone or the email — that is how we recognise a returning customer instead of creating them twice.') }}</p>
                            @endif
                        </div>

                        {{-- Routing --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="inline-flex items-center gap-2.5 cursor-pointer">
                                    <input type="hidden" name="create_deal" value="0">
                                    <input type="checkbox" name="create_deal" value="1" @checked($selected->create_deal)
                                           class="rounded border-paper-300 text-wa-deep focus:ring-wa-deep">
                                    <span class="text-[12.5px] font-medium text-ink-800">{{ __('Create a deal for every lead') }}</span>
                                </label>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-1">{{ __('If the same person submits again while their deal is still open, we add it to that deal instead of making a second one.') }}</p>
                            </div>

                            <div>
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Pipeline') }}</label>
                                <select name="pipeline_id" data-fbl-pipeline
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('Default pipeline') }}</option>
                                    @foreach ($pipelines as $p)
                                        <option value="{{ $p->id }}" @selected((int) $selected->pipeline_id === (int) $p->id)>{{ $p->name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-1">{{ __('Which board the new deal is added to.') }}</p>
                            </div>

                            <div>
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Starting stage') }}</label>
                                <select name="stage_id" data-fbl-stage
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('First stage') }}</option>
                                    @foreach ($pipelines as $p)
                                        @foreach ($p->stages as $st)
                                            <option value="{{ $st->id }}" data-pipeline="{{ $p->id }}"
                                                    @selected((int) $selected->stage_id === (int) $st->id)>{{ $st->name }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-1">{{ __('Where the deal card first appears.') }}</p>
                            </div>

                            <div>
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Who follows up') }}</label>
                                <select name="assign_strategy" data-fbl-strategy
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="fixed" @selected($selected->assign_strategy !== 'round_robin')>{{ __('Always the same person') }}</option>
                                    <option value="round_robin" @selected($selected->assign_strategy === 'round_robin')>{{ __('Share out in turn') }}</option>
                                </select>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-1">{{ __('Sharing out in turn rotates through the team so nobody gets every lead.') }}</p>
                            </div>

                            <div>
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Owner') }}</label>
                                <select name="owner_user_id"
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('Nobody yet') }}</option>
                                    @foreach ($members as $m)
                                        <option value="{{ $m->id }}" @selected((int) $selected->owner_user_id === (int) $m->id)>{{ $m->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Team') }}</label>
                                <select name="owner_team_id"
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('No team') }}</option>
                                    @foreach ($teams as $t)
                                        <option value="{{ $t->id }}" @selected((int) $selected->owner_team_id === (int) $t->id)>{{ $t->name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-1">{{ __('Used when leads are shared out in turn.') }}</p>
                            </div>

                            <div>
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Start a flow') }}</label>
                                <select name="flow_id"
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('Do not start one') }}</option>
                                    @foreach ($flows as $fl)
                                        <option value="{{ $fl->id }}" @selected((int) $selected->flow_id === (int) $fl->id)>{{ $fl->flow_name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[10.5px] text-ink-400 leading-snug mt-1">{{ __('An automatic welcome message the moment the lead arrives.') }}</p>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mb-1.5">{{ __('Tag the contact') }}</label>
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($tags as $tg)
                                        <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border border-paper-200 bg-paper-0 text-[11.5px] cursor-pointer hover:bg-paper-50">
                                            <input type="checkbox" name="tag_ids[]" value="{{ $tg->id }}"
                                                   @checked(in_array((int) $tg->id, array_map('intval', (array) ($selected->tag_ids ?? [])), true))
                                                   class="rounded border-paper-300 text-wa-deep focus:ring-wa-deep">
                                            <span>{{ $tg->name }}</span>
                                        </label>
                                    @empty
                                        <p class="text-[12px] text-ink-500">{{ __('No tags created yet.') }}</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3 pt-1">
                            <button formaction="{{ route('user.lead-ads.forms.backfill', $selected->id) }}" formnovalidate
                                    class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">
                                {{ __('Check for missed leads') }}
                            </button>
                            <button class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal transition">
                                {{ __('Save settings') }}
                            </button>
                        </div>
                    </form>

                    {{-- ── Leads ──────────────────────────────────────────── --}}
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-paper-100">
                            <div>
                                <h2 class="font-serif text-[20px] leading-tight">{{ __('Leads') }}</h2>
                                <p class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mt-0.5">{{ $selected->name }}</p>
                            </div>
                            <form method="GET" action="{{ route('user.lead-ads.index') }}">
                                <input type="hidden" name="form" value="{{ $selected->id }}">
                                <select name="status" onchange="this.form.submit()"
                                        class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('All leads') }}</option>
                                    <option value="ok" @selected(request('status') === 'ok')>{{ __('Added') }}</option>
                                    <option value="pending" @selected(request('status') === 'pending')>{{ __('Waiting') }}</option>
                                    <option value="failed" @selected(request('status') === 'failed')>{{ __('Needs attention') }}</option>
                                </select>
                            </form>
                        </div>

                        @if ($leads->isEmpty())
                            <div class="p-8 text-center">
                                <p class="text-[13px] text-ink-700">{{ __('No leads yet for this form.') }}</p>
                                <p class="text-[12px] text-ink-500 mt-1">{{ __('They appear here within seconds of someone submitting your ad form.') }}</p>
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-[12.5px]">
                                    <thead class="bg-paper-50 text-ink-500">
                                        <tr class="font-mono text-[10px] uppercase tracking-[0.12em] text-left">
                                            <th class="px-5 py-2.5 font-medium">{{ __('Person') }}</th>
                                            <th class="px-3 py-2.5 font-medium">{{ __('Answers') }}</th>
                                            <th class="px-3 py-2.5 font-medium">{{ __('Came from') }}</th>
                                            <th class="px-3 py-2.5 font-medium">{{ __('When') }}</th>
                                            <th class="px-5 py-2.5 font-medium text-right">{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-paper-100">
                                    @foreach ($leads as $lead)
                                        @php
                                            $answers = $lead->answers();
                                            [$pill, $pillLabel] = $statusStyles[$lead->ingest_status] ?? $statusStyles['pending'];
                                        @endphp
                                        <tr class="align-top hover:bg-paper-50/60">
                                            <td class="px-5 py-3">
                                                @if ($lead->contact?->id)
                                                    <a href="{{ url('/contacts?search='.urlencode((string) ($lead->contact->mobile ?: $lead->contact->email))) }}"
                                                       class="text-wa-deep font-medium hover:underline">{{ $lead->contact->name ?: __('Contact') }}</a>
                                                @else
                                                    <span class="text-ink-700">{{ $answers['full_name'] ?? ($answers['first_name'] ?? __('Unknown')) }}</span>
                                                @endif
                                                @if ($lead->deal?->id)
                                                    <div class="font-mono text-[10px] text-ink-400 mt-0.5">{{ __('deal #:id', ['id' => $lead->deal->id]) }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 max-w-[280px]">
                                                <div class="space-y-0.5">
                                                    @foreach (array_slice($answers, 0, 3, true) as $k => $v)
                                                        <div class="truncate text-ink-700"><span class="text-ink-400">{{ str_replace('_', ' ', $k) }}:</span> {{ $v }}</div>
                                                    @endforeach
                                                    @if (count($answers) > 3)
                                                        <div class="font-mono text-[10px] text-ink-400">{{ __('+:n more', ['n' => count($answers) - 3]) }}</div>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="text-ink-700 truncate max-w-[180px]">{{ $lead->campaign_name ?: ($lead->is_organic ? __('Organic') : __('Unknown campaign')) }}</div>
                                                @if ($lead->platform)
                                                    <div class="font-mono text-[10px] text-ink-400 uppercase">{{ $lead->platform === 'ig' ? 'instagram' : 'facebook' }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 whitespace-nowrap text-ink-600">
                                                {{ $lead->submitted_at?->diffForHumans() ?: '—' }}
                                            </td>
                                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-mono uppercase tracking-[0.1em] {{ $pill }}">{{ $pillLabel }}</span>
                                                @if ($lead->ingest_status !== 'ok')
                                                    <div class="mt-1.5">
                                                        <button type="button" data-fbl-retry="{{ $lead->id }}"
                                                                class="px-2.5 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[11px]">
                                                            {{ __('Try again') }}
                                                        </button>
                                                    </div>
                                                    @if ($lead->ingest_error)
                                                        <div class="font-mono text-[10px] text-ink-400 mt-1 max-w-[200px] ml-auto text-right leading-snug">{{ $lead->ingest_error }}</div>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($leads->hasPages())
                                <div class="px-5 py-3 border-t border-paper-100">{{ $leads->links() }}</div>
                            @endif
                        @endif
                    </div>
                @endif
            </section>
        </div>
    </main>
</x-layouts.user>
