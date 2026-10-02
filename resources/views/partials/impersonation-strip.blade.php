{{-- Impersonation strip — only present when the ImpersonationBanner middleware
     shared a non-null active `$impersonation`. `$wrapClass` is passed by the
     caller so the sidebar shell can render it as a `shrink-0` row INSIDE its
     100vh flex column (keeping the shell exactly one viewport tall — no phantom
     "scroll to blank"), while the header layout keeps it `sticky top-0`. --}}
@if (!empty($impersonation) && ($impersonation['active'] ?? false))
    <div class="{{ $wrapClass ?? 'sticky top-0' }} z-[60] bg-accent-amber text-ink-900 border-b border-accent-amber/60 shadow-sm">
        <div class="max-w-screen-2xl mx-auto px-4 py-2 flex items-center gap-3 text-[12.5px]">
            <span class="font-mono uppercase tracking-[0.16em] text-[10px]">{{ __('Impersonating') }}</span>
            <span class="font-semibold">{{ $impersonation['target_workspace_name'] ?? 'workspace' }}</span>
            <span class="hidden md:inline text-ink-700">— {{ $impersonation['reason'] }}</span>
            <form method="POST" action="{{ url('/admin/impersonate/stop') }}" class="ml-auto">
                @csrf
                <button type="submit"
                    class="px-3 py-1 rounded-full bg-ink-900 text-paper-0 text-[11.5px] font-semibold hover:bg-ink-700">
                    {{ __('Stop impersonating') }}
                </button>
            </form>
        </div>
    </div>
@endif
