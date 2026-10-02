{{--
 /admin/settings/features — Feature Toggles.

 Lists every user-facing feature/card (from App\Support\FeatureRegistry) with an
 on/off switch. OFF hides that feature from the sidebar rail, the classic top
 bar and the /more grid. Each switch maps 1:1 to a `feature_show_<key>` row.
 The form submits the keys left ON (name="show[]"); the controller turns every
 other registry key OFF. Default is ON, so nothing changes until an admin flips
 a switch.
--}}
<x-layouts.admin :title="__('Feature toggles')" admin-key="settings" page="admin-settings-features">
    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M4 3l3 3-3 3" />
            </svg>
            <a href="{{ url('/admin/settings') }}" class="hover:text-ink-900">{{ __('Settings') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M4 3l3 3-3 3" />
            </svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Features') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <form method="POST" action="{{ route('admin.settings.features.update') }}" class="contents">
        @csrf

        <main class="px-4 sm:px-7 py-7 space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                        {{ __('Admin · Project settings') }}</div>
                    <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[40px] leading-[1.0]">{{ __('Feature') }}
                        <span class="italic text-wa-deep">{{ __('toggles') }}</span>.</h1>
                    <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                        {{ __('Turn any feature off to hide it from the user dashboard — the sidebar, the top bar and the More page. Everything is on by default; switching one off removes it everywhere for all workspaces.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2 shrink-0 pb-1">
                    <a href="{{ url('/admin/settings') }}"
                        class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All settings') }}</a>
                    <button type="submit"
                        class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save changes') }}</button>
                </div>
            </div>

            @if (session('success'))
                <div class="rounded-xl border border-wa-green/40 bg-wa-bubble text-wa-deep px-4 py-3 text-[12.5px] font-medium">
                    {{ session('success') }}</div>
            @endif

            <div data-feat-tabs>
                {{-- Tab bar: one tab per group. --}}
                <div class="flex flex-wrap gap-0.5 border-b border-paper-200" role="tablist">
                    @foreach ($groups as $groupLabel => $items)
                        <button type="button" data-feat-tab="{{ $loop->index }}"
                            class="px-3.5 py-2.5 text-[12.5px] font-medium rounded-t-lg -mb-px border-b-2 border-transparent text-ink-600 hover:text-ink-900 hover:bg-paper-50 transition inline-flex items-center gap-1.5">
                            <span>{{ __($groupLabel) }}</span>
                            <span class="text-[10px] font-mono px-1.5 py-0.5 rounded-full bg-paper-100 text-ink-500">{{ count($items) }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- One panel per group. All panels stay in the form so a Save
                     submits every toggle, even the ones not on the visible tab. --}}
                @foreach ($groups as $groupLabel => $items)
                    <div data-feat-panel="{{ $loop->index }}" class="hidden pt-5">
                        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-6 gap-y-0.5">
                                @foreach ($items as $it)
                                    <label class="flex items-center gap-3 px-2 py-2.5 cursor-pointer hover:bg-paper-50 rounded-lg">
                                        <span class="relative inline-flex items-center w-10 h-5 shrink-0">
                                            <input type="checkbox" name="show[]" value="{{ $it['key'] }}"
                                                @checked($states[$it['key']] ?? true) class="sr-only peer">
                                            <span class="absolute inset-0 bg-paper-200 peer-checked:bg-wa-deep rounded-full transition"></span>
                                            <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-paper-0 rounded-full transition peer-checked:translate-x-5"></span>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block text-[13px] font-medium text-ink-900 truncate">{{ __($it['label']) }}</span>
                                            <span class="block text-[11px] font-mono text-ink-400 truncate">{{ $it['path'] ?? $it['flag'] ?? '' }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </section>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-2 pt-1">
                <button type="submit"
                    class="px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save changes') }}</button>
            </div>
        </main>
    </form>
</x-layouts.admin>
