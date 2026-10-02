<x-layouts.user :title="__('Threads Replies')" nav-key="threads-posts" page="user-threads-replies">
    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-7">

        @if (session('status'))
            <div class="mb-4 bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 bg-accent-coral/10 border border-accent-coral/30 rounded-lg px-4 py-2 text-[12.5px] text-accent-coral font-mono">{{ session('error') }}</div>
        @endif

        <div class="flex items-center justify-between mb-5">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Threads') }}</div>
                <h1 class="font-serif text-[28px] leading-tight">{{ __('Reply automation') }}</h1>
                <p class="text-[12.5px] text-ink-500 mt-1 max-w-2xl">{{ __('When a reply to your posts matches a keyword, auto-post a public reply and/or hide it. Replies are checked when you open the Threads pages.') }}</p>
            </div>
            <a href="{{ route('user.threads.posts') }}" class="text-[12px] font-mono text-wa-deep">{{ __('Posts') }}</a>
        </div>

        @if ($accounts->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 text-center">
                <p class="text-[13px] text-ink-600 mb-3">{{ __('Connect a Threads account first.') }}</p>
                <a href="{{ route('user.threads.connect') }}" class="inline-flex px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">{{ __('Connect Threads') }}</a>
            </div>
        @else
            <div class="grid md:grid-cols-[1fr_1.2fr] gap-5">
                {{-- New rule --}}
                <form method="POST" action="{{ route('user.threads.replies.store') }}" class="bg-paper-0 border border-paper-200 rounded-2xl p-5 space-y-3 h-fit">
                    @csrf
                    <h2 class="font-serif text-[18px]">{{ __('New rule') }}</h2>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Name (optional)') }}</label>
                        <input type="text" name="name" maxlength="120" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50" />
                    </div>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Account') }}</label>
                        <select name="threads_account_id" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50">
                            <option value="">{{ __('Any connected account') }}</option>
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}">{{ '@' . ($a->username ?: $a->threads_user_id) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-[110px_1fr] gap-2">
                        <div>
                            <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Match') }}</label>
                            <select name="keyword_mode" class="w-full px-2 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50">
                                <option value="contains">{{ __('Contains') }}</option>
                                <option value="exact">{{ __('Exact') }}</option>
                                <option value="any">{{ __('Any reply') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Keyword(s)') }}</label>
                            <input type="text" name="keyword" placeholder="price, cost, buy" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Public reply') }}</label>
                        <textarea name="reply_text" rows="3" maxlength="500" placeholder="{{ __('Thanks for your reply! ...') }}" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[13px] bg-paper-50"></textarea>
                    </div>

                    <label class="flex items-center gap-2 text-[12.5px]">
                        <input type="checkbox" name="use_ai" value="1"> <span>{{ __('Generate the reply with AI') }}</span>
                    </label>
                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('AI instruction (optional)') }}</label>
                        <input type="text" name="ai_prompt" maxlength="500" placeholder="{{ __('e.g. be playful, mention our free trial') }}" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50" />
                        <p class="text-[11px] text-ink-400 mt-1">{{ __('When AI is on, this steers the reply. The fixed reply above is used as a fallback.') }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-[12.5px]">
                        <input type="checkbox" name="hide" value="1"> <span>{{ __('Hide the matching reply') }}</span>
                    </label>

                    <button class="w-full px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">{{ __('Add rule') }}</button>
                </form>

                {{-- Rules + activity --}}
                <div class="space-y-4">
                    <div>
                        <h2 class="font-serif text-[18px] mb-2">{{ __('Rules') }}</h2>
                        @forelse ($rules as $r)
                            <div class="bg-paper-0 border border-paper-200 rounded-xl p-3 flex items-start gap-3 mb-2">
                                <span class="mt-0.5 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-mono {{ $r->is_active ? 'bg-wa-green/15 text-wa-deep' : 'bg-paper-100 text-ink-500' }}">{{ $r->is_active ? __('On') : __('Off') }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[12.5px] text-ink-800">{{ $r->name ?: ($r->keyword_mode === 'any' ? __('Any reply') : $r->keyword) }}</div>
                                    <div class="text-[10.5px] text-ink-400 font-mono">
                                        {{ $r->keyword_mode }}{{ $r->keyword_mode !== 'any' ? ': '.$r->keyword : '' }}
                                        @if ($r->reply_text) · {{ __('reply') }} @endif
                                        @if ($r->hide) · {{ __('hide') }} @endif
                                        · {{ $r->matched_count }} {{ __('matched') }}
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('user.threads.replies.toggle', $r->id) }}">@csrf
                                    <button class="text-[11px] text-ink-500 hover:text-wa-deep">{{ $r->is_active ? __('Pause') : __('Resume') }}</button>
                                </form>
                                <form method="POST" action="{{ route('user.threads.replies.destroy', $r->id) }}">@csrf @method('DELETE')
                                    <button class="text-[11px] text-ink-400 hover:text-accent-coral">{{ __('Delete') }}</button>
                                </form>
                            </div>
                        @empty
                            <p class="text-[12.5px] text-ink-400">{{ __('No rules yet.') }}</p>
                        @endforelse
                    </div>

                    <div>
                        <h2 class="font-serif text-[18px] mb-2">{{ __('Recent activity') }}</h2>
                        @forelse ($activity as $log)
                            <div class="bg-paper-0 border border-paper-200 rounded-xl p-3 mb-2">
                                <div class="text-[12px] text-ink-700 truncate">"{{ \Illuminate\Support\Str::limit($log->text, 80) }}"</div>
                                <div class="text-[10.5px] text-ink-400 font-mono">{{ '@'.$log->from_username }} · {{ $log->action }} · {{ $log->created_at->diffForHumans() }}</div>
                            </div>
                        @empty
                            <p class="text-[12.5px] text-ink-400">{{ __('No auto-replies yet.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </main>
</x-layouts.user>
