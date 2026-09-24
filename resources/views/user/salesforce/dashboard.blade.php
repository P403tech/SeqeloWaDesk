<x-layouts.user :title="__('Salesforce')" nav-key="more" page="user-salesforce-dashboard">

    @php $isConnected = $integration && $integration->isConnected(); @endphp

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

            <aside class="space-y-3">
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                    <div class="w-12 h-12 rounded-xl mb-3 grid place-items-center" style="background:#D6E4F5">
                        <svg viewBox="0 0 24 24" class="w-7 h-7" fill="#00A1E0">
                            <path d="M7.4 14.2c-.9-1.9.1-4 2.1-4.6.4-1.8 2-3.1 3.9-3.1 1.1 0 2.1.4 2.8 1.1 1.7-.7 3.6.1 4.2 1.8.2 0 .3 0 .5.1 1.6.3 2.6 1.9 2.3 3.5-.3 1.4-1.6 2.3-3 2.3H9.2c-.7 0-1.4-.4-1.8-1.1z"/>
                        </svg>
                    </div>
                    <div class="font-serif text-[18px] leading-tight">{{ __('Salesforce CRM') }}</div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mt-1">
                        {{ __('Integration') }}</div>
                    <div
                        class="mt-3 inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-mono {{ $isConnected ? 'bg-wa-mint text-wa-deep border border-wa-green/40' : 'bg-paper-50 text-ink-700 border border-paper-200' }}">
                        <span
                            class="w-1.5 h-1.5 rounded-full {{ $isConnected ? 'bg-wa-green' : 'bg-paper-200' }}"></span>
                        {{ $isConnected ? __('Connected') : __('Not connected') }}
                    </div>
                </div>

                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card text-[12px] text-ink-700">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                        {{ __('What this does') }}</div>
                    <ul class="space-y-1.5">
                        <li class="flex items-start gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 mt-0.5 text-wa-deep shrink-0" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m3.5 8.5 3 3 6-7" /></svg>
                            <span>{{ __('Connect your Salesforce org with OAuth. Tokens stay encrypted on this workspace.') }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 mt-0.5 text-wa-deep shrink-0" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m3.5 8.5 3 3 6-7" /></svg>
                            <span>{{ __('Contact import and WhatsApp send, plus order → Opportunity sync, will be added after connect is live.') }}</span>
                        </li>
                    </ul>
                </div>
            </aside>

            <section class="space-y-5">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                        <a href="{{ url('/integrations') }}" class="hover:text-wa-deep">{{ __('Integrations') }}</a>
                        <span class="mx-1.5 text-ink-500/60">/</span>
                        <span>{{ __('Salesforce') }}</span>
                    </div>
                    @if ($isConnected)
                        <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[38px] lg:text-[44px] leading-none">
                            {{ $integration->org_name ?: __('Salesforce') }} <span
                                class="italic text-wa-deep">{{ __('org') }}</span></h1>
                        <p class="text-[13px] text-ink-600 mt-2">{{ __('Org ID:') }} <span
                                class="font-mono">{{ $integration->org_id ?: '—' }}</span>
                            @if ($integration->instance_url)
                                · <span class="font-mono">{{ parse_url($integration->instance_url, PHP_URL_HOST) }}</span>
                            @endif
                            · {{ __('Connected') }}
                            {{ $integration->connected_at?->diffForHumans() ?? '—' }}</p>
                    @else
                        <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[38px] lg:text-[44px] leading-none">{{ __('Connect') }}
                            <span class="italic text-wa-deep">{{ __('Salesforce CRM') }}</span></h1>
                        <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                            {{ __('Link your Salesforce org. Sync (contacts, opportunities, WhatsApp send) can be turned on after this connection is saved.') }}
                        </p>
                    @endif
                </div>

                @if (session('success'))
                    <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">
                        {{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">
                        {{ session('error') }}</div>
                @endif

                @if (!$isConnected)
                    @if (!$appEnabled)
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card flex items-start gap-5">
                            <div class="w-12 h-12 rounded-xl bg-accent-amber/20 grid place-items-center shrink-0">
                                <svg viewBox="0 0 24 24" class="w-6 h-6 text-accent-amber" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <path d="M12 9v3M12 16h.01" />
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-serif text-[22px] leading-tight">
                                    {{ __("Salesforce isn't configured yet") }}</div>
                                <p class="text-[12.5px] text-ink-600 mt-1.5 max-w-2xl">
                                    {{ __('An admin needs to create a Salesforce Connected App and paste the Consumer Key + Secret at') }}
                                    <span class="font-mono text-wa-deep">/admin/settings/salesforce</span>.
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                            <h2 class="font-serif text-[22px] leading-tight mb-3">
                                {{ __('Connect your Salesforce org') }}</h2>
                            <p class="text-[12.5px] text-ink-600 mb-4">
                                {{ __("You'll be redirected to Salesforce to approve:") }}
                                <span class="font-mono text-[11.5px] text-ink-700 block mt-1">{{ \App\Models\SystemSetting::get('salesforce_scopes', \App\Services\Salesforce\SalesforceService::DEFAULT_SCOPES) }}</span>
                            </p>
                            <form method="POST" action="{{ url('/salesforce/connect') }}">
                                @csrf
                                <button type="submit"
                                    class="px-5 py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold inline-flex items-center gap-2">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path d="M3 8h10M9 4l4 4-4 4" />
                                    </svg>
                                    {{ __('Connect Salesforce') }}
                                </button>
                            </form>
                        </div>
                    @endif
                @else
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">
                                {{ __('Connections') }}</div>
                            <div class="font-serif text-[30px] leading-none mt-1.5 tabular-nums">
                                {{ number_format($stats['created'] ?? 0) }}</div>
                        </div>
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">
                                {{ __('Updates') }}</div>
                            <div class="font-serif text-[30px] leading-none mt-1.5 tabular-nums">
                                {{ number_format($stats['updated'] ?? 0) }}</div>
                        </div>
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">
                                {{ __('Failed') }}</div>
                            <div class="font-serif text-[30px] leading-none mt-1.5 tabular-nums {{ ($stats['failed'] ?? 0) > 0 ? 'text-accent-coral' : '' }}">
                                {{ number_format($stats['failed'] ?? 0) }}</div>
                        </div>
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">
                                {{ __('Last event') }}</div>
                            <div class="text-[13px] font-medium mt-2.5">
                                {{ !empty($stats['last']) ? \Illuminate\Support\Carbon::parse($stats['last'])->diffForHumans() : __('Never') }}
                            </div>
                        </div>
                    </div>

                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
                            <h2 class="font-serif text-[22px] leading-tight">{{ __('Recent activity') }}</h2>
                            <form method="POST" action="{{ url('/salesforce/' . $integration->id . '/disconnect') }}"
                                onsubmit="return confirm('Disconnect Salesforce?')">
                                @csrf
                                <button type="submit"
                                    class="px-3 py-1.5 rounded-full border border-accent-coral/40 text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-semibold">{{ __('Disconnect') }}</button>
                            </form>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 text-left font-mono text-[10.5px] uppercase text-ink-500">
                                <tr>
                                    <th class="px-4 py-2.5">{{ __('Event') }}</th>
                                    <th class="px-4 py-2.5">{{ __('Object ID') }}</th>
                                    <th class="px-4 py-2.5">{{ __('Status') }}</th>
                                    <th class="px-4 py-2.5 text-right">{{ __('When') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-paper-100">
                                @forelse ($recentLogs as $log)
                                    @php $statusCss = $log->status === 'sent' ? 'bg-wa-green/15 text-wa-deep' : 'bg-accent-coral/15 text-accent-coral'; @endphp
                                    <tr class="hover:bg-paper-50">
                                        <td class="px-4 py-2.5 font-mono">{{ $log->event_type }}</td>
                                        <td class="px-4 py-2.5 font-mono text-[11.5px] text-ink-700">
                                            {{ $log->object_id ?: '—' }}</td>
                                        <td class="px-4 py-2.5"><span
                                                class="font-mono text-[10px] uppercase px-2 py-0.5 rounded-full {{ $statusCss }}">{{ $log->status }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono text-[11px] text-ink-500">
                                            {{ $log->created_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-[12px] text-ink-500">
                                            {{ __('Connected. Contact import and order sync will appear here once those features are turned on.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </main>

</x-layouts.user>
