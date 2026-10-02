<x-layouts.user :title="__('New campaign')" nav-key="openai-ads" page="user-openai-ads-create">
    @php $cur = $account['currency_code'] ?? ''; @endphp
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.openai-ads._rail', ['current' => 'create', 'account' => $account])

            <section class="space-y-5 max-w-3xl">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('OpenAI Ads') }}</div>
                    <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none">{{ __('New') }} <span class="italic text-wa-deep">{{ __('campaign') }}</span></h1>
                    <p class="text-[13px] text-ink-600 mt-2">{{ __('This creates the campaign, one ad group, and one ad — all paused. You activate it from the campaign page when ready.') }}</p>
                </div>

                @if ($errors->any())
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $errors->first() }}</div>
                @endif

                {{-- Build with AI — generates the names + headline + body from a brief. --}}
                <section class="bg-wa-bubble/40 border border-wa-green/30 rounded-2xl p-5 shadow-card space-y-3"
                    data-oaiads-ai
                    data-models-url="{{ route('user.openai-ads.api.ai-models') }}"
                    data-generate-url="{{ route('user.openai-ads.api.ai-generate') }}">
                    <div class="flex items-center gap-2">
                        <svg viewBox="0 0 16 16" class="w-4 h-4 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 1.5l1.6 3.9 3.9 1.6-3.9 1.6L8 12.5 6.4 8.6 2.5 7l3.9-1.6z"/></svg>
                        <h2 class="font-serif text-[17px]">{{ __('Build with AI') }}</h2>
                    </div>
                    <p class="text-[11.5px] text-ink-500">{{ __('Describe your offer and let AI draft the campaign names, headline and body. You can edit everything before creating.') }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <label class="block sm:col-span-2">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('AI model') }}</span>
                            <select data-ai-model class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep"><option value="">{{ __('Loading models…') }}</option></select>
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Business name') }}</span>
                            <input type="text" data-ai-business class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Product / service') }}</span>
                            <input type="text" data-ai-product class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Audience') }}</span>
                            <input type="text" data-ai-audience class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Tone') }}</span>
                            <input type="text" data-ai-tone placeholder="{{ __('e.g. friendly, premium') }}" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Notes') }} <span class="text-ink-400">({{ __('optional') }})</span></span>
                            <textarea data-ai-prompt rows="2" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep"></textarea>
                        </label>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" data-ai-generate class="px-3.5 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal disabled:opacity-60">{{ __('Generate') }}</button>
                        <span data-ai-status class="text-[11.5px] text-ink-500"></span>
                    </div>
                </section>

                <form method="POST" action="{{ route('user.openai-ads.store') }}" class="space-y-5" data-oaiads-form>
                    @csrf

                    {{-- Campaign --}}
                    <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-3.5">
                        <h2 class="font-serif text-[18px]">{{ __('Campaign') }}</h2>
                        <label class="block">
                            <span class="text-[12px] font-semibold text-ink-700">{{ __('Campaign name') }}</span>
                            <input type="text" name="name" value="{{ old('name') }}" required minlength="3" maxlength="1000"
                                class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Lifetime budget') }} @if($cur)<span class="text-ink-400">({{ $cur }})</span>@endif</span>
                                <input type="number" name="budget" value="{{ old('budget') }}" required min="1" step="0.01"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                                <span class="text-[10px] text-ink-400 leading-snug">{{ __('Minimum 1. Charged as a total spend cap.') }}</span>
                            </label>
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Bidding') }}</span>
                                <select name="bidding_type" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="clicks" @selected(old('bidding_type')==='clicks')>{{ __('Clicks') }}</option>
                                    <option value="impressions" @selected(old('bidding_type')==='impressions')>{{ __('Impressions') }}</option>
                                    <option value="conversions" @selected(old('bidding_type')==='conversions')>{{ __('Conversions') }}</option>
                                </select>
                                <span class="text-[10px] text-ink-400 leading-snug">{{ __('What you pay to optimise toward.') }}</span>
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-[12px] font-semibold text-ink-700">{{ __('Conversion event') }} <span class="text-ink-400">({{ __('for Conversions bidding only') }})</span></span>
                            @if (isset($eventSettings) && $eventSettings->isNotEmpty())
                                <select name="conversion_event_setting_id" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('— none —') }}</option>
                                    @foreach ($eventSettings as $e)
                                        <option value="{{ $e->remote_id }}" @selected(old('conversion_event_setting_id')===$e->remote_id)>{{ $e->name }} ({{ $e->remote_id }})</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" name="conversion_event_setting_id" value="{{ old('conversion_event_setting_id') }}" placeholder="ces_..."
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                                <span class="text-[10px] text-ink-400 leading-snug">{{ __('Set up conversions first to pick from a list.') }} <a href="{{ route('user.openai-ads.conversions') }}" class="text-wa-deep hover:underline">{{ __('Conversion setup') }}</a></span>
                            @endif
                        </label>
                    </section>

                    {{-- Ad group --}}
                    <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-3.5">
                        <h2 class="font-serif text-[18px]">{{ __('Ad group') }}</h2>
                        <label class="block">
                            <span class="text-[12px] font-semibold text-ink-700">{{ __('Ad group name') }}</span>
                            <input type="text" name="adgroup_name" value="{{ old('adgroup_name') }}" required minlength="3" maxlength="1000"
                                class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Bill on') }}</span>
                                <select name="billing_event_type" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="click" @selected(old('billing_event_type')==='click')>{{ __('Click') }}</option>
                                    <option value="impression" @selected(old('billing_event_type')==='impression')>{{ __('Impression') }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Max bid') }} @if($cur)<span class="text-ink-400">({{ $cur }})</span>@endif</span>
                                <input type="number" name="max_bid" value="{{ old('max_bid') }}" required min="0.01" step="0.01"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                            </label>
                        </div>
                    </section>

                    {{-- Ad / creative --}}
                    <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card space-y-3.5">
                        <h2 class="font-serif text-[18px]">{{ __('Ad') }}</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Creative type') }}</span>
                                <select name="creative_type" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="chat_card" @selected(old('creative_type')==='chat_card')>{{ __('Chat card (image + link)') }}</option>
                                    <option value="product_ad_template" @selected(old('creative_type')==='product_ad_template')>{{ __('Product ad template') }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Ad name') }}</span>
                                <input type="text" name="ad_name" value="{{ old('ad_name') }}" required minlength="3" maxlength="1000"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            </label>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Headline') }} <span class="text-ink-400">(3–50)</span></span>
                                <input type="text" name="title" value="{{ old('title') }}" required minlength="3" maxlength="50"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            </label>
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Price') }} <span class="text-ink-400">({{ __('optional') }})</span></span>
                                <input type="text" name="price" value="{{ old('price') }}" maxlength="100" placeholder="{{ __('e.g. $29 or a product price token') }}"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-[12px] font-semibold text-ink-700">{{ __('Body') }} <span class="text-ink-400">({{ __('max 100') }})</span></span>
                            <textarea name="body" rows="2" required maxlength="100"
                                class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">{{ old('body') }}</textarea>
                        </label>
                        <div class="rounded-lg bg-paper-50 border border-paper-200 px-3 py-2.5 space-y-3">
                            <div class="text-[11px] text-ink-500">{{ __('Required for the chat card creative:') }}</div>
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Destination URL') }}</span>
                                <input type="url" name="target_url" value="{{ old('target_url') }}" placeholder="https://…"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                            </label>
                            <label class="block">
                                <span class="text-[12px] font-semibold text-ink-700">{{ __('Image URL') }} <span class="text-ink-400">(≥ 640×640)</span></span>
                                <input type="url" name="image_url" value="{{ old('image_url') }}" placeholder="https://…"
                                    class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                            </label>
                        </div>
                    </section>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Create campaign') }}</button>
                        <a href="{{ route('user.openai-ads.index') }}" class="px-4 py-2 rounded-lg border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-50">{{ __('Cancel') }}</a>
                    </div>
                </form>
            </section>
        </div>
    </main>
</x-layouts.user>
