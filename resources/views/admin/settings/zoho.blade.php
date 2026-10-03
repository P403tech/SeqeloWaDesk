<x-layouts.admin :title="__('Zoho CRM settings')" admin-key="settings" page="admin-settings-zoho">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-6 lg:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ url('/admin/settings') }}" class="hover:text-ink-900">{{ __('Settings') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Zoho CRM') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-6 lg:px-7 py-7 space-y-5">
        @if (session('success'))
            <div class="px-4 py-2.5 rounded-xl bg-wa-bubble border border-wa-green/30 text-[12.5px] text-wa-deep">
                {{ session('success') }}</div>
        @endif
        @if (isset($errors) && $errors->any())
            <div class="px-4 py-2.5 rounded-xl bg-accent-coral/10 border border-accent-coral/30 text-[12.5px] text-accent-coral">
                {{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.settings.zoho.update') }}" class="space-y-5">
            @csrf

            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                        {{ __('Admin · Project settings') }}</div>
                    <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[34px] lg:text-[40px] leading-[1.0]">{{ __('Zoho CRM') }}
                        <span class="italic text-wa-deep">{{ __('settings') }}</span>.</h1>
                    <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                        {{ __('Configure platform OAuth credentials once. Workspaces can then 1-click connect their Zoho CRM account via OAuth 2.0 to automatically sync contacts, leads, deals, and conversations.') }}
                    </p>
                </div>
                <div class="flex items-center flex-wrap gap-2 shrink-0 pb-1">
                    <a href="{{ url('/admin/settings/integration') }}"
                        class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All integrations') }}</a>
                    <button type="submit"
                        class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save changes') }}</button>
                </div>
            </div>

            <section class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-5 items-start">
                <div class="space-y-5 min-w-0">
                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Getting started') }}</div>
                            <h2 class="font-serif text-[25px] leading-tight mt-1">{{ __('How to register your Zoho App') }}</h2>
                        </div>
                        <ol class="divide-y divide-paper-100">
                            <li class="px-5 py-4 flex items-start gap-4">
                                <span class="w-7 h-7 rounded-full bg-wa-bubble text-wa-deep grid place-items-center font-mono text-[12px] font-semibold shrink-0">1</span>
                                <div>
                                    <div class="font-semibold text-[13px]">{{ __('Open Zoho API Console') }}</div>
                                    <p class="text-[12px] text-ink-600 mt-1">
                                        {{ __('Go to') }} <a href="https://api-console.zoho.com" target="_blank" rel="noopener" class="text-wa-deep underline font-medium">api-console.zoho.com</a> {{ __('and click "Add Client" → choose "Server-based Applications".') }}
                                    </p>
                                </div>
                            </li>
                            <li class="px-5 py-4 flex items-start gap-4">
                                <span class="w-7 h-7 rounded-full bg-wa-bubble text-wa-deep grid place-items-center font-mono text-[12px] font-semibold shrink-0">2</span>
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold text-[13px]">{{ __('Enter Authorized Redirect URI') }}</div>
                                    <p class="text-[12px] text-ink-600 mt-1">{{ __('Paste this exact URL into the "Authorized Redirect URIs" field:') }}</p>
                                    <div class="mt-2 flex gap-2">
                                        <input value="{{ url('/zoho/oauth/callback') }}" readonly id="copy-redirect"
                                            class="flex-1 rounded-lg border border-paper-200 bg-paper-50 px-3 py-2 text-[12px] font-mono">
                                        <button type="button" data-copy="copy-redirect"
                                            class="rounded-lg border border-paper-200 bg-paper-0 hover:bg-paper-50 px-3 text-[11.5px] font-medium">{{ __('Copy') }}</button>
                                    </div>
                                </div>
                            </li>
                            <li class="px-5 py-4 flex items-start gap-4">
                                <span class="w-7 h-7 rounded-full bg-wa-bubble text-wa-deep grid place-items-center font-mono text-[12px] font-semibold shrink-0">3</span>
                                <div>
                                    <div class="font-semibold text-[13px]">{{ __('Select your primary Data Center region') }}</div>
                                    <p class="text-[12px] text-ink-600 mt-1">{{ __('Zoho operates separate cloud accounts for US, Europe (.eu), India (.in), Australia (.com.au), Japan (.jp), and Canada (.ca). Select your organization’s region below.') }}</p>
                                </div>
                            </li>
                            <li class="px-5 py-4 flex items-start gap-4">
                                <span class="w-7 h-7 rounded-full bg-wa-bubble text-wa-deep grid place-items-center font-mono text-[12px] font-semibold shrink-0">4</span>
                                <div>
                                    <div class="font-semibold text-[13px]">{{ __('Copy Client ID & Client Secret here') }}</div>
                                    <p class="text-[12px] text-ink-600 mt-1">
                                        {{ __('Paste the credentials below, toggle "Enable Zoho CRM", and save. Workspaces can immediately connect at') }}
                                        <a href="{{ url('/zoho') }}" target="_blank" class="text-wa-deep font-medium underline">/zoho</a>.
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
                            <div>
                                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('zoho crm · oauth credentials') }}</div>
                                <h2 class="font-serif text-[25px] leading-tight mt-1">{{ __('App credentials') }}</h2>
                            </div>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <span class="text-[12px] text-ink-700">{{ __('Enable Zoho CRM') }}</span>
                                <input type="hidden" name="zoho_enabled" value="0">
                                <input type="checkbox" name="zoho_enabled" value="1" @checked($enabled) class="w-5 h-5 accent-wa-deep">
                            </label>
                        </div>
                        <div class="p-5 grid grid-cols-2 gap-4">
                            <label class="space-y-1.5 col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __('Client ID') }} <span class="text-accent-coral">*</span></span>
                                <input name="zoho_client_id" value="{{ old('zoho_client_id', $clientId) }}"
                                    placeholder="1000.XXXXXXXXXXXXXXXXXXXXXXXXXXXX"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __('Client Secret') }} <span class="text-accent-coral">*</span></span>
                                <input type="password" name="zoho_client_secret" value="{{ old('zoho_client_secret') }}"
                                    placeholder="{{ $hasSecret ? __('•••••••• saved — leave blank to keep') : '' }}"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 col-span-2 sm:col-span-1">
                                <span class="text-[11.5px] font-semibold">{{ __('Default Data Center / Region') }}</span>
                                <select name="zoho_data_center" class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                    @foreach ($dataCenters as $key => $dc)
                                        <option value="{{ $key }}" @selected($dataCenter === $key)>
                                            {{ $dc['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="space-y-1.5 col-span-2 sm:col-span-1">
                                <span class="text-[11.5px] font-semibold">{{ __('OAuth scopes') }}</span>
                                <input name="zoho_scopes" value="{{ old('zoho_scopes', $scopes) }}"
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            </label>
                            <label class="space-y-1.5 col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __('Redirect URI') }}</span>
                                <div class="flex gap-2">
                                    <input name="zoho_redirect_uri" value="{{ old('zoho_redirect_uri', $redirectUri) }}" id="redirect-uri"
                                        class="flex-1 rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                                    <button type="button" data-copy="redirect-uri"
                                        class="rounded-xl border border-paper-200 bg-paper-0 hover:bg-paper-50 px-3 text-[12px]">{{ __('Copy') }}</button>
                                </div>
                            </label>
                        </div>
                    </section>

                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('install-status') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Install readiness') }}</h2>
                        </div>
                        <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-3 text-[11px] font-mono">
                            @php
                                $checks = [
                                    'credentials saved' => $clientId !== '' && $hasSecret,
                                    'feature enabled' => $enabled,
                                    'redirect set' => $redirectUri !== '',
                                ];
                            @endphp
                            @foreach ($checks as $label => $ok)
                                <span class="rounded-full px-3 py-1.5 text-center {{ $ok ? 'bg-wa-mint text-wa-deep border border-wa-green/40' : 'bg-paper-100 text-ink-500 border border-paper-200' }}">
                                    {{ $ok ? '✓ ' : '○ ' }}{{ $label }}
                                </span>
                            @endforeach
                        </div>
                    </section>

                    <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('usage') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('Live across workspaces') }}</h2>
                        </div>
                        <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <div class="font-mono text-[10px] uppercase text-ink-500 tracking-wide">{{ __('Accounts connected') }}</div>
                                <div class="font-serif text-[28px] leading-none mt-1 tabular-nums">{{ number_format($integrationsCount) }}</div>
                            </div>
                            <div>
                                <div class="font-mono text-[10px] uppercase text-ink-500 tracking-wide">{{ __('Active connections') }}</div>
                                <div class="font-serif text-[28px] leading-none mt-1 tabular-nums">{{ number_format($activeCount) }}</div>
                            </div>
                            <div>
                                <div class="font-mono text-[10px] uppercase text-ink-500 tracking-wide">{{ __('Events logged') }}</div>
                                <div class="font-serif text-[28px] leading-none mt-1 tabular-nums">{{ number_format($logsCount) }}</div>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="space-y-4 lg:sticky lg:top-[88px]">
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="px-4 py-3 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Quick guide') }}</div>
                            <h3 class="font-serif text-[18px] leading-tight mt-0.5">{{ __('Key features') }}</h3>
                        </div>
                        <div class="p-4 space-y-3 text-[12px] text-ink-700">
                            <p>{{ __('Automatic token refresh: Seqelo handles refresh token rotations without user interruptions.') }}</p>
                            <p>{{ __('Deduplication: Contacts are automatically deduped on phone number or email address.') }}</p>
                            <p>{{ __('Multi-DC support: Works with all Zoho accounts worldwide (US, Europe, India, Australia, Japan, Canada).') }}</p>
                        </div>
                    </div>
                    <div class="bg-wa-bubble border border-wa-green/40 rounded-2xl p-4">
                        <div class="font-semibold text-[12.5px]">{{ __('Workspace test') }}</div>
                        <p class="text-[11.5px] text-ink-600 mt-1">
                            {{ __('Once saved, open any workspace and navigate to More → Integrations → Zoho CRM to complete authorization.') }}
                        </p>
                    </div>
                </aside>
            </section>
        </form>
    </main>
</x-layouts.admin>
