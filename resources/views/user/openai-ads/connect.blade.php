<x-layouts.user :title="__('Connect OpenAI Ads')" nav-key="openai-ads" page="user-openai-ads-connect">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.openai-ads._rail', ['current' => 'connect', 'account' => $account])

            <section class="space-y-5 max-w-2xl">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('OpenAI Ads') }}</div>
                    <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">{{ __('Connect your') }} <span class="italic text-wa-deep">{{ __('ad account') }}</span></h1>
                    <p class="text-[13px] text-ink-600 mt-2">{{ __('Paste your OpenAI Ads API key. Your key stays private to this workspace and is stored encrypted — it charges into and reports from your own ad account. One key connects one ad account.') }}</p>
                </div>

                @if (session('status'))
                    <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('user.openai-ads.keys.save') }}" class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-4" autocomplete="off">
                    @csrf
                    <label class="block">
                        <span class="text-[12px] font-semibold text-ink-700">{{ __('Ads API key') }}<span class="text-accent-coral">*</span></span>
                        <input type="password" name="api_key" autocomplete="new-password"
                            placeholder="{{ $hasKey ? '•••••••• '.__('stored — paste again to replace') : 'sk-...' }}"
                            class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                        <span class="text-[10px] text-ink-400 leading-snug mt-1 block">{{ __('Generate this in OpenAI Ads Manager → Settings. Each key is scoped to a single ad account.') }}</span>
                    </label>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ $hasKey ? __('Update key') : __('Connect') }}</button>
                        @if ($hasKey)
                            <a href="{{ route('user.openai-ads.index') }}" class="px-4 py-2 rounded-lg border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-50">{{ __('Back to campaigns') }}</a>
                        @endif
                    </div>
                </form>

                @if ($hasKey)
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-[13px] font-semibold text-ink-900">{{ $account['name'] ?? __('Connected account') }}</div>
                                <div class="text-[11.5px] text-ink-500 font-mono mt-0.5">
                                    {{ $account['id'] ?? '' }}
                                    @if (!empty($account['currency_code'])) · {{ $account['currency_code'] }} @endif
                                    @if (!empty($account['timezone'])) · {{ $account['timezone'] }} @endif
                                </div>
                            </div>
                            <form method="POST" action="{{ route('user.openai-ads.keys.destroy') }}"
                                onsubmit="return confirm('{{ __('Disconnect this ad account?') }}')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[11px] font-semibold text-accent-coral hover:underline">{{ __('Disconnect') }}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </main>
</x-layouts.user>
