<x-layouts.admin :title="__('Threads')" admin-key="threads" page="settings-threads">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ url('/admin/settings') }}" class="hover:text-ink-900">{{ __('Settings') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ url('/admin/settings/channel-setting') }}" class="hover:text-ink-900">{{ __('Channels') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Threads') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-7 py-7 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin - Threads publishing') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[40px] leading-[1.0]">
                    {{ __('Threads') }} <span class="italic text-wa-deep">{{ __('publishing') }}</span>.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Fill your Threads (Meta) app credentials here once. Workspaces then connect their Threads account via OAuth and publish or schedule posts through the official Threads API — right from the social calendar.') }}
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 pb-1">
                <a href="{{ url('/admin/settings/channel-setting') }}" class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All channels') }}</a>
                <button type="submit" form="threads-settings-form" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save changes') }}</button>
            </div>
        </div>

        <x-admin.flash />

        <form id="threads-settings-form" method="POST" action="{{ route('admin.settings.threads.save') }}" class="space-y-5">@csrf

            {{-- Enable + status --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5">
                <label class="inline-flex items-start gap-2.5 cursor-pointer">
                    <input type="checkbox" name="threads_enabled" value="1" @checked($threads_enabled)
                        class="mt-0.5 w-4 h-4 rounded border-paper-300 text-wa-deep focus:ring-wa-deep/20">
                    <span class="text-[12.5px] text-ink-700 leading-relaxed">
                        <span class="font-semibold text-ink-900">{{ __('Enable Threads platform-wide') }}</span><br>
                        {{ __('When on, workspaces with the Threads plan feature see the Threads channel and can connect an account.') }}
                    </span>
                </label>
                <div class="mt-3 text-[11.5px] text-ink-500 font-mono">{{ $connected_count }} {{ __('account(s) connected platform-wide') }}</div>
            </div>

            {{-- App credentials --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 space-y-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Threads app credentials') }}</div>
                <p class="text-[12px] leading-relaxed -mt-1 rounded-lg bg-wa-mint/60 border border-wa-green/30 px-3 py-2 text-wa-deep">
                    {{ __('Create a Threads app at developers.facebook.com (Use case: Access the Threads API). Paste its App ID + Secret below. A workspace can also enter its OWN Threads app keys in its settings — those take priority over these.') }}
                </p>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="block">
                        <span class="text-[11.5px] text-ink-700">{{ __('App ID') }} <span class="text-ink-400">{{ __('(Threads App ID)') }}</span></span>
                        <input name="threads_app_id" value="{{ $threads_app_id }}" placeholder="123456789012345"
                            class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                    </label>
                    <label class="block">
                        <span class="text-[11.5px] text-ink-700">{{ __('App Secret') }}</span>
                        <input name="threads_app_secret" type="password" autocomplete="new-password"
                            placeholder="{{ $threads_secret_set ? '•••••••• ('.__('saved — leave blank to keep').')' : __('paste your Threads app secret') }}"
                            class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                    </label>
                </div>
            </div>

            {{-- Paste this into your Threads app. --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 space-y-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Paste this into your Threads app') }}</div>
                <div class="space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('OAuth redirect URI') }}</span>
                        <span class="text-[10.5px] text-ink-400">{{ __('Threads app → Use cases → Settings → Redirect Callback URLs') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 min-w-0 truncate rounded-lg bg-paper-50 border border-paper-200 px-3 py-2 text-[12px] text-ink-800">{{ $redirect_uri }}</code>
                        <button type="button" onclick="navigator.clipboard.writeText(@js($redirect_uri))"
                            class="shrink-0 text-[11.5px] font-semibold text-wa-deep hover:text-wa-teal px-2 py-1">{{ __('Copy') }}</button>
                    </div>
                </div>
            </div>

            {{-- Setup steps --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('Setup steps') }}</div>
                <ol class="space-y-3">
                    @foreach ([
                        __('Go to developers.facebook.com → Create App → use case "Access the Threads API".'),
                        __('In the app, open the Threads use case and request the permissions: threads_basic, threads_content_publish (and threads_manage_replies, threads_read_replies, threads_manage_insights for later).'),
                        __('Add the OAuth redirect URI above to the app\'s Redirect Callback URLs.'),
                        __('Copy the App ID and App Secret into the fields above and Save.'),
                        __('Turn on "Enable Threads platform-wide", then grant the Threads plan feature to the plans that should get it.'),
                        __('A workspace user opens Threads, clicks Connect, authorizes, and can then publish or schedule posts.'),
                    ] as $i => $step)
                        <li class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-wa-mint text-wa-deep text-[11px] font-mono grid place-items-center">{{ $i + 1 }}</span>
                            <span class="text-[12.5px] text-ink-700 leading-relaxed">{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </form>
    </main>
</x-layouts.admin>
