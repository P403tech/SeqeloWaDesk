<x-layouts.user :title="__('Threads Posts')" nav-key="threads-posts" page="user-threads-posts">
    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-7">

        @if (session('status'))
            <div class="mb-4 bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 bg-accent-coral/10 border border-accent-coral/30 rounded-lg px-4 py-2 text-[12.5px] text-accent-coral font-mono">{{ session('error') }}</div>
        @endif

        <div class="flex items-center justify-between mb-5">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Channel') }}</div>
                <h1 class="font-serif text-[28px] leading-tight">Threads</h1>
            </div>
            @if (! $accounts->isEmpty())
                <div class="flex items-center gap-4">
                    <a href="{{ route('user.threads.insights') }}" class="text-[12px] font-mono text-wa-deep hover:text-wa-teal">{{ __('Insights') }}</a>
                    <a href="{{ route('user.threads.replies') }}" class="text-[12px] font-mono text-wa-deep hover:text-wa-teal">{{ __('Reply automation') }}</a>
                </div>
            @endif
        </div>

        @if ($accounts->isEmpty())
            {{-- Empty state: connect. --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 text-center">
                <h2 class="font-serif text-[20px] mb-1">{{ __('Connect a Threads account') }}</h2>
                <p class="text-[12.5px] text-ink-500 mb-4">{{ __('Publish and schedule posts to Threads from here.') }}</p>
                <a href="{{ route('user.threads.connect') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">
                    {{ __('Connect Threads') }}</a>

                <form method="POST" action="{{ route('user.threads.manual') }}" class="mt-5 max-w-md mx-auto text-left">
                    @csrf
                    <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Or paste a long-lived token') }}</label>
                    <div class="flex gap-2">
                        <input type="text" name="access_token" required placeholder="THQ..."
                               class="flex-1 px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] font-mono bg-paper-50" />
                        <button class="px-4 py-2 rounded-lg bg-paper-100 text-ink-800 text-[12.5px] font-semibold border border-paper-200">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        @else
            <div class="grid md:grid-cols-[1fr_1.2fr] gap-5">
                {{-- Composer --}}
                <form method="POST" action="{{ route('user.threads.posts.store') }}" enctype="multipart/form-data"
                      class="bg-paper-0 border border-paper-200 rounded-2xl p-5 space-y-3 h-fit">
                    @csrf
                    <h2 class="font-serif text-[18px]">{{ __('New post') }}</h2>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Account') }}</label>
                        <select name="threads_account_id" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50">
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}">{{ '@' . ($a->username ?: $a->threads_user_id) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Type') }}</label>
                        <select name="media_type" class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50">
                            <option value="text">{{ __('Text') }}</option>
                            <option value="image">{{ __('Image') }}</option>
                            <option value="video">{{ __('Video') }}</option>
                            <option value="carousel">{{ __('Carousel') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Text') }}</label>
                        <textarea name="text" rows="4" maxlength="500" placeholder="{{ __('What\'s happening?') }}"
                                  class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[13px] bg-paper-50"></textarea>
                    </div>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Media (image / video)') }}</label>
                        <input type="file" name="media[]" multiple accept="image/*,video/*"
                               class="w-full text-[12px]" />
                    </div>

                    <div>
                        <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Schedule (optional)') }}</label>
                        <input type="datetime-local" name="scheduled_at"
                               class="w-full px-3 py-2 border border-paper-200 rounded-lg text-[12.5px] bg-paper-50" />
                        <p class="text-[11px] text-ink-400 mt-1">{{ __('Leave blank to post now.') }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-[12.5px]">
                        <input type="checkbox" name="cross_to_ig" value="1"> <span>{{ __('Also share to Instagram') }}</span>
                    </label>

                    <button class="w-full px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">
                        {{ __('Post / Schedule') }}</button>
                </form>

                {{-- Post list --}}
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h2 class="font-serif text-[18px]">{{ __('Posts') }}</h2>
                        <a href="{{ route('user.threads.connect') }}" class="text-[11.5px] font-mono text-wa-deep">{{ __('Add account') }}</a>
                    </div>
                    @forelse ($posts as $p)
                        @php [$badge, $label] = \App\Services\Social\SocialPostAggregator::statusStyle(strtolower($p->status)); @endphp
                        <div class="bg-paper-0 border border-paper-200 rounded-xl p-3 flex items-start gap-3">
                            <span class="mt-0.5 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-mono {{ $badge }}">{{ $label }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[12.5px] text-ink-800 truncate">{{ $p->text ?: '('.$p->media_type.')' }}</div>
                                <div class="text-[10.5px] text-ink-400 font-mono">
                                    {{ $p->media_type }}
                                    @if ($p->scheduled_at) · {{ $p->scheduled_at->format('M j, H:i') }} @endif
                                    @if ($p->last_error) · <span class="text-accent-coral">{{ \Illuminate\Support\Str::limit($p->last_error, 60) }}</span> @endif
                                </div>
                            </div>
                            @if ($p->status !== 'published')
                                <form method="POST" action="{{ route('user.threads.posts.destroy', $p->id) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-[11px] text-ink-400 hover:text-accent-coral">{{ __('Remove') }}</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-[12.5px] text-ink-400">{{ __('No posts yet.') }}</p>
                    @endforelse
                </div>
            </div>
        @endif
    </main>
</x-layouts.user>
