@php
    /** @var \Illuminate\Support\Collection $pages */
    /** @var \Illuminate\Support\Collection $flows */
    /** @var \Illuminate\Support\Collection $rules */
    $pageName = fn ($id) => optional($pages->firstWhere('id', $id))->name ?: ('#' . $id);
@endphp

<x-layouts.user :title="__('Facebook comment auto-reply')" nav-key="facebook" page="user-facebook-comment-rules">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Facebook') }} · {{ __('Comments') }}</div>
                <h1 class="font-serif text-[26px] leading-tight">{{ __('Comment') }} <span class="italic text-wa-deep">{{ __('auto-reply') }}</span></h1>
                <p class="text-[12.5px] text-ink-500 mt-1">{{ __('When someone comments a keyword on a post, reply publicly, DM them, or start a flow — automatically.') }}</p>
            </div>
            <a href="{{ route('user.facebook.broadcasts') }}" class="text-[12px] text-ink-500 hover:text-wa-deep underline shrink-0">{{ __('Facebook broadcasts') }} →</a>
        </div>

        @if ($pages->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <p class="text-[13px] text-ink-600">{{ __('Connect a Facebook Page first, then create comment rules here.') }}</p>
                <a href="{{ route('facebook.connect') }}" class="inline-block mt-3 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold">{{ __('Connect a Page') }}</a>
            </div>
        @else
            {{-- ===== Create rule ===== --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                <div class="px-5 py-4 border-b border-paper-200">
                    <h2 class="font-serif text-[20px] leading-tight">{{ __('New rule') }}</h2>
                </div>
                <form method="POST" action="{{ route('user.facebook.comment-rules.store') }}" class="p-5 grid gap-4 sm:grid-cols-2">
                    @csrf
                    @include('user.facebook._comment-rule-fields', ['rule' => null, 'pages' => $pages, 'flows' => $flows])
                    <div class="sm:col-span-2 flex justify-end">
                        <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Create rule') }}</button>
                    </div>
                </form>
            </div>

            {{-- ===== Existing rules ===== --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
                    <h2 class="font-serif text-[20px] leading-tight">{{ __('Your rules') }}</h2>
                    <span class="text-[11px] font-mono text-ink-500">{{ $rules->count() }}</span>
                </div>

                @if ($rules->isEmpty())
                    <div class="px-5 py-10 text-center text-[12.5px] text-ink-500">{{ __('No comment rules yet. Add one above.') }}</div>
                @else
                    <div class="divide-y divide-paper-100">
                        @foreach ($rules as $rule)
                            <div class="p-5">
                                <div class="flex items-center justify-between gap-3 mb-3">
                                    <div class="text-[13px] font-semibold text-ink-900 truncate">
                                        {{ $rule->name ?: ($rule->keyword_mode === 'any' ? __('Any comment') : $rule->keyword) }}
                                        <span class="ml-2 text-[11px] font-mono {{ $rule->is_active ? 'text-wa-deep' : 'text-ink-400' }}">{{ $rule->is_active ? __('active') : __('paused') }}</span>
                                    </div>
                                    <div class="flex items-center gap-3 shrink-0 text-[11px] text-ink-500 font-mono">
                                        <span>{{ __('Page') }}: {{ $pageName($rule->fb_page_id) }}</span>
                                        <span>{{ __('matched') }}: {{ (int) $rule->matched_count }}</span>
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('user.facebook.comment-rules.update', $rule->id) }}" class="grid gap-4 sm:grid-cols-2">
                                    @csrf
                                    @method('PUT')
                                    @include('user.facebook._comment-rule-fields', ['rule' => $rule, 'pages' => $pages, 'flows' => $flows])
                                    <div class="sm:col-span-2 flex items-center justify-end gap-2">
                                        <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Save') }}</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('user.facebook.comment-rules.destroy', $rule->id) }}" class="mt-2 flex justify-end"
                                    onsubmit="return confirm('{{ __('Delete this comment rule?') }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-[11.5px] text-accent-coral hover:underline">{{ __('Delete rule') }}</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </main>
</x-layouts.user>
