{{--
 /admin/workspaces/trash — soft-deleted workspaces with restore / force-delete.
 Mirrors the /admin/users/trash layout exactly:
 • Restore → POST /admin/workspaces/{id}/restore
 • Delete now → DELETE /admin/workspaces/{id}/force (CSRF + confirm)
 • Empty trash → POST /admin/workspaces/trash/empty (wipes anything past 30 days)
 Filter pills + search + pagination run through ?filter=&q=.
--}}
<x-layouts.admin :title="__('Admin · Trashed workspaces')" admin-key="workspaces" page="admin-workspaces-trash">
    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-6 lg:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ route('admin.workspaces.index') }}" class="hover:text-ink-900">{{ __('Workspaces') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Trash') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('admin.workspaces.index') }}"
                class="px-3.5 py-1.5 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 4 5 8l4 4M5 8h8" /></svg>
                {{ __('Back to workspaces') }}
            </a>
            <form method="POST" action="{{ route('admin.workspaces.trash.empty') }}"
                onsubmit="return confirm('{{ __('Permanently delete every trashed workspace older than 30 days? This cannot be undone.') }}')">
                @csrf
                <button type="submit"
                    class="px-3.5 py-1.5 hairline border border-accent-coral/40 text-accent-coral rounded-full bg-paper-0 hover:bg-accent-coral/10 text-[12px] font-medium">
                    {{ __('Empty trash') }}
                </button>
            </form>
        </div>
    </header>

    <main class="px-4 sm:px-6 lg:px-7 py-7 space-y-5">

        <div class="flex items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin · Workspaces · Trash') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[34px] lg:text-[40px] leading-[1.0]">{{ __('Trashed') }}
                    <span class="italic text-wa-deep">{{ __('workspaces') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">{{ $kpi['total'] }} {{ __('deleted workspaces. Trashed workspaces are recoverable for 30 days, after which they are permanently deleted along with their data.') }}</p>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-wa-green/40 bg-wa-bubble text-wa-deep px-4 py-3 text-[12.5px] font-medium">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-accent-coral/40 bg-accent-coral/10 text-accent-coral px-4 py-3 text-[12.5px]">{{ $errors->first() }}</div>
        @endif

        <div class="rounded-2xl border border-accent-coral/30 bg-accent-coral/5 p-4 flex items-start gap-3">
            <svg viewBox="0 0 16 16" class="w-5 h-5 text-accent-coral mt-0.5" fill="none" stroke="currentColor" stroke-width="1.7">
                <path d="M8 1l7 13H1z" /><path d="M8 6v3M8 11h.01" />
            </svg>
            <div class="flex-1">
                <div class="text-[13px] font-semibold text-accent-coral">{{ __('Permanent deletion is irreversible') }}</div>
                <div class="text-[12px] text-ink-700 mt-0.5">{{ __('Once a workspace passes the 30-day window or is force-deleted, all its contacts, messages, campaigns and audit records are wiped per our retention policy.') }}</div>
            </div>
        </div>

        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-2 flex items-center gap-1 shadow-card flex-wrap">
            @php $pills = [['key' => 'all', 'label' => __('All trashed'), 'count' => $kpi['total']], ['key' => 'recent', 'label' => __('Last 7 days'), 'count' => $kpi['recent']], ['key' => 'expiring', 'label' => __('Expiring soon'), 'count' => $kpi['expiring']]]; @endphp
            @foreach ($pills as $p)
                <a href="{{ route('admin.workspaces.trash', array_filter(['filter' => $p['key'] === 'all' ? null : $p['key'], 'q' => $q ?: null])) }}"
                    class="inline-flex items-center gap-1.5 px-4 py-[7px] rounded-full text-[13px] transition {{ $filter === $p['key'] || ($filter === 'all' && $p['key'] === 'all') ? 'bg-ink-900 text-paper-0' : 'text-ink-600 hover:bg-paper-50' }}">
                    {{ $p['label'] }} <span class="font-mono text-[11px] opacity-80">{{ $p['count'] }}</span>
                </a>
            @endforeach
            <div class="flex-1"></div>
            <form method="GET" action="{{ route('admin.workspaces.trash') }}" class="relative">
                @if ($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="7" cy="7" r="5" /><path d="m11 11 3 3" />
                </svg>
                <input name="q" value="{{ $q }}" placeholder="{{ __('Search trashed workspaces…') }}"
                    class="hairline border border-paper-200 rounded-full pl-9 pr-3 py-1.5 text-[12px] bg-paper-0 w-72 focus:outline-none focus:border-wa-deep">
            </form>
        </div>

        <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
            <div class="overflow-x-auto">
            <table class="w-full text-[12.5px] table-fixed min-w-[1040px]">
                <thead class="bg-paper-50 text-ink-500 border-b border-paper-200">
                    <tr>
                        <th class="text-left px-3 py-3 w-[64px]">{{ __('Icon') }}</th>
                        <th class="text-left px-3 py-3">{{ __('Workspace') }}</th>
                        <th class="text-left px-3 py-3 w-[200px]">{{ __('Owner') }}</th>
                        <th class="text-left px-3 py-3 w-[140px]">{{ __('Trashed') }}</th>
                        <th class="text-left px-3 py-3 w-[140px]">{{ __('Auto-delete in') }}</th>
                        <th class="text-left px-3 py-3 w-[120px]">{{ __('Plan') }}</th>
                        <th class="text-left px-4 py-3 w-[200px]">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-paper-200">
                    @forelse ($workspaces as $ws)
                        @php
                            $initials = strtoupper(substr(preg_replace('/\s+/', '', $ws->name ?: $ws->slug), 0, 2));
                            $palette = [
                                ['bg' => 'bg-paper-100', 'text' => 'text-ink-500'],
                                ['bg' => 'bg-[#FFF4E0]', 'text' => 'text-[#7B5A14]'],
                                ['bg' => 'bg-[#F3E9FF]', 'text' => 'text-[#5B3D8A]'],
                                ['bg' => 'bg-wa-bubble', 'text' => 'text-wa-deep'],
                                ['bg' => 'bg-[#D9E5F2]', 'text' => 'text-[#13478A]'],
                            ];
                            $pal = $palette[$ws->id % count($palette)];
                            $daysLeft = max(0, (int) ceil(30 - (float) ($ws->deleted_at?->diffInDays(now()) ?? 0)));
                            $expiringSoon = $daysLeft <= 7;
                            $owner = $owners[$ws->owner_user_id] ?? null;
                        @endphp
                        <tr class="{{ $expiringSoon ? 'hover:bg-paper-50/60 bg-accent-amber/10' : 'hover:bg-paper-50/60' }}">
                            <td class="px-3 py-3"><span class="w-9 h-9 rounded-full {{ $pal['bg'] }} {{ $pal['text'] }} grid place-items-center text-[11px] font-bold">{{ $initials }}</span></td>
                            <td class="px-3 py-3">
                                <div class="font-semibold leading-tight">{{ $ws->name ?: '(no name)' }}</div>
                                <div class="text-[11px] text-ink-500 mt-0.5 font-mono">{{ $ws->slug }}</div>
                            </td>
                            <td class="px-3 py-3">
                                @if ($owner)
                                    <div class="text-[12px] font-semibold">{{ $owner->name ?: '(no name)' }}</div>
                                    <div class="text-[11px] text-ink-500">{{ $owner->email }}</div>
                                @else
                                    <div class="text-[11px] text-ink-500 italic">{{ __('(no owner)') }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 font-mono text-[11px]">{{ $ws->deleted_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center gap-1 text-[11px] {{ $expiringSoon ? 'text-accent-coral font-semibold' : 'text-ink-600' }}">
                                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="8" cy="8" r="6" /><path d="M8 5v3l2 1.5" /></svg>
                                    {{ $daysLeft }} {{ __('days') }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-[11px] text-ink-700 font-mono uppercase tracking-wider">{{ $ws->plan ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.workspaces.restore', $ws->id) }}" class="inline">
                                    @csrf
                                    <button class="px-2.5 py-1 rounded-full border border-wa-green/40 text-wa-deep text-[11px] font-semibold hover:bg-wa-bubble/40 mr-1">{{ __('Restore') }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.workspaces.force-delete', $ws->id) }}" class="inline"
                                    onsubmit="return confirm('{{ __('Permanently delete this workspace? This cannot be undone — all its data will be wiped.') }}')">
                                    @csrf @method('DELETE')
                                    <button class="px-2.5 py-1 rounded-full border border-accent-coral/40 text-accent-coral text-[11px] font-semibold hover:bg-accent-coral/10">{{ __('Delete now') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-[12.5px] text-ink-500">
                                @if ($q || $filter !== 'all')
                                    {{ __('No trashed workspaces match those filters.') }}
                                    <a href="{{ route('admin.workspaces.trash') }}" class="text-wa-deep font-semibold hover:underline ml-1">{{ __('Clear filters') }}</a>
                                @else
                                    {{ __('Nothing in trash. Workspaces you delete from') }} <a href="{{ route('admin.workspaces.index') }}" class="text-wa-deep font-semibold hover:underline">/admin/workspaces</a> {{ __('show up here for 30 days before being permanently wiped.') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <div class="px-4 py-3 border-t border-paper-200 bg-paper-50/40 flex flex-wrap gap-2 items-center justify-between">
                <div class="text-[11px] font-mono text-ink-500">
                    @if ($workspaces->total() > 0)
                        {{ __('Showing') }} {{ $workspaces->firstItem() }}–{{ $workspaces->lastItem() }} {{ __('of') }} {{ $workspaces->total() }} {{ __('trashed') }}
                    @else
                        {{ __('No trashed workspaces') }}
                    @endif
                </div>
                <div class="text-[11px] text-ink-500">{{ __('Auto-deletion runs nightly at 02:00 UTC') }}</div>
            </div>
            @if ($workspaces->hasPages())
                <div class="px-4 py-3 border-t border-paper-200">{{ $workspaces->links() }}</div>
            @endif
        </div>
    </main>
</x-layouts.admin>
