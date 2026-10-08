@php
    $cs = $channelSetup ?? [
        'whatsapp' => ['platform' => true, 'connected' => false, 'accounts' => [], 'connect_url' => url('/devices'), 'hint' => ''],
        'facebook' => ['platform' => true, 'connected' => false, 'accounts' => [], 'connect_url' => url('/facebook/connect'), 'manual_url' => url('/facebook/connect/manual'), 'hint' => ''],
        'instagram' => ['platform' => false, 'connected' => false, 'accounts' => [], 'connect_url' => '', 'hint' => ''],
        'tiktok' => ['platform' => false, 'connected' => false, 'accounts' => [], 'connect_url' => url('/tiktok/connect'), 'hint' => ''],
        'shopify' => ['platform' => true, 'connected' => false, 'accounts' => [], 'connect_url' => url('/shopify/connect'), 'hint' => ''],
    ];
    $toggle = function (string $field) {
        return '<span class="relative inline-block w-[34px] h-5 shrink-0 mt-0.5">
            <input data-field="'.$field.'" class="peer opacity-0 w-0 h-0" type="checkbox">
            <span class="absolute cursor-pointer inset-0 bg-paper-200 rounded-full transition before:content-[\'\'] before:absolute before:h-4 before:w-4 before:left-0.5 before:bottom-0.5 before:bg-paper-0 before:rounded-full before:transition peer-checked:bg-wa-deep peer-checked:before:translate-x-[14px]"></span>
        </span>';
    };
@endphp

<div class="space-y-3">
    {{-- WhatsApp --}}
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="w-5 h-5 rounded-md wa-brand grid place-items-center shrink-0">
                        <svg viewBox="0 0 24 24" class="w-3 h-3" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.96.57 3.79 1.55 5.34L2 22l4.78-1.5A9.93 9.93 0 0 0 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2Zm5.07 14.07c-.21.6-1.22 1.14-1.7 1.21-.45.07-1.02.1-1.65-.1-.38-.12-.87-.28-1.49-.55-2.62-1.13-4.33-3.77-4.46-3.94-.13-.18-1.07-1.42-1.07-2.71 0-1.29.68-1.92.92-2.18.24-.27.52-.34.7-.34h.5c.16 0 .38-.06.59.45.21.51.71 1.76.77 1.89.06.13.1.28.02.45-.08.18-.12.28-.24.43-.12.15-.26.34-.37.46-.12.12-.25.26-.11.51.14.26.62 1.02 1.33 1.65.91.81 1.68 1.06 1.94 1.18.26.13.41.11.56-.06.15-.18.65-.76.83-1.02.18-.26.36-.21.6-.13.24.09 1.55.73 1.81.86.27.13.45.2.51.31.07.12.07.69-.14 1.29Z"/></svg>
                    </span>
                    <span class="text-[13.5px] font-semibold">{{ __('WhatsApp') }}</span>
                    @if ($cs['whatsapp']['connected'])
                        <span class="font-mono text-[10px] text-wa-deep">{{ trans_choice(':n number|:n numbers', count($cs['whatsapp']['accounts']), ['n' => count($cs['whatsapp']['accounts'])]) }}</span>
                    @else
                        <span class="font-mono text-[10px] text-ink-500">{{ __('not connected') }}</span>
                    @endif
                </div>
                <p class="text-[11.5px] text-ink-500 mt-0.5">{{ $cs['whatsapp']['hint'] }}</p>
            </div>
            {!! $toggle('channel_whatsapp') !!}
        </div>
        @include('user.ai-training._channel-control', ['chKey' => 'whatsapp'])
        @if ($cs['whatsapp']['accounts'])
            <ul class="border-t border-paper-100 px-4 py-2 space-y-1 bg-paper-50/50">
                @foreach ($cs['whatsapp']['accounts'] as $row)
                    <li class="text-[12px] text-ink-800 flex items-center justify-between gap-2">
                        <span class="truncate">{{ $row['label'] }}</span>
                        <span class="font-mono text-[10px] text-ink-500">{{ $row['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="px-4 pb-3">
            <a data-channel-connect="whatsapp" href="{{ $cs['whatsapp']['connect_url'] }}"
                class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-wa-deep hover:underline">
                {{ $cs['whatsapp']['connected'] ? __('Add another number') : __('Connect WhatsApp') }}
            </a>
        </div>
    </div>

    {{-- Facebook --}}
    @if (! empty($cs['facebook']['platform']))
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="w-5 h-5 rounded-md fb-brand grid place-items-center shrink-0">
                        <svg viewBox="0 0 24 24" class="w-3 h-3" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                    </span>
                    <span class="text-[13.5px] font-semibold">{{ __('Facebook Messenger') }}</span>
                    @if ($cs['facebook']['connected'])
                        <span class="font-mono text-[10px] text-wa-deep">{{ trans_choice(':n page|:n pages', count($cs['facebook']['accounts']), ['n' => count($cs['facebook']['accounts'])]) }}</span>
                    @elseif (! $cs['facebook']['platform'])
                        <span class="font-mono text-[10px] text-ink-500">{{ __('admin must enable') }}</span>
                    @else
                        <span class="font-mono text-[10px] text-ink-500">{{ __('not connected') }}</span>
                    @endif
                </div>
                <p class="text-[11.5px] text-ink-500 mt-0.5">{{ $cs['facebook']['hint'] }}</p>
            </div>
            {!! $toggle('channel_facebook') !!}
        </div>
        @include('user.ai-training._channel-control', ['chKey' => 'facebook'])
        @if ($cs['facebook']['accounts'])
            <ul class="border-t border-paper-100 px-4 py-2 space-y-1 bg-paper-50/50">
                @foreach ($cs['facebook']['accounts'] as $row)
                    <li class="text-[12px] text-ink-800 truncate">{{ $row['label'] }}</li>
                @endforeach
            </ul>
        @endif
        <div class="px-4 pb-3 space-y-2">
            @if ($cs['facebook']['platform'])
                <a data-channel-connect="facebook" href="{{ $cs['facebook']['connect_url'] }}"
                    class="inline-flex items-center justify-center gap-2 w-full sm:w-auto px-4 py-2 rounded-full text-[12.5px] font-semibold text-white" style="background:#1877F2">
                    {{ __('Continue with Facebook') }}
                </a>
                <details class="text-[12px]">
                    <summary class="cursor-pointer text-ink-600">{{ __('Or paste a Page access token') }}</summary>
                    <form method="POST" action="{{ $cs['facebook']['manual_url'] }}" class="mt-2 space-y-2" data-channel-form="facebook-manual">
                        @csrf
                        <input type="hidden" name="return" value="">
                        <textarea name="page_access_token" rows="2" required placeholder="EAAG…"
                            class="w-full rounded-xl border border-paper-200 px-3 py-2 text-[12px] font-mono"></textarea>
                        <button type="submit" class="px-4 py-1.5 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold">{{ __('Connect Page') }}</button>
                    </form>
                </details>
            @endif
        </div>
    </div>
    @endif

    {{-- Instagram --}}
    @if (! empty($cs['instagram']['platform']))
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="w-5 h-5 rounded-md ig-grad-soft text-white grid place-items-center shrink-0">
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.2" y="2.2" width="11.6" height="11.6" rx="3.4"/><circle cx="8" cy="8" r="2.9"/><circle cx="11.3" cy="4.7" r="0.7" fill="currentColor" stroke="none"/></svg>
                    </span>
                    <span class="text-[13.5px] font-semibold">{{ __('Instagram') }}</span>
                    @if ($cs['instagram']['connected'])
                        <span class="font-mono text-[10px] text-wa-deep">{{ count($cs['instagram']['accounts']) }} {{ __('linked') }}</span>
                    @else
                        <span class="font-mono text-[10px] text-ink-500">{{ $cs['instagram']['platform'] ? __('not connected') : __('admin must enable') }}</span>
                    @endif
                </div>
                <p class="text-[11.5px] text-ink-500 mt-0.5">{{ $cs['instagram']['hint'] }}</p>
            </div>
            {!! $toggle('channel_instagram') !!}
        </div>
        @include('user.ai-training._channel-control', ['chKey' => 'instagram'])
        @if ($cs['instagram']['accounts'])
            <ul class="border-t border-paper-100 px-4 py-2 space-y-1 bg-paper-50/50">
                @foreach ($cs['instagram']['accounts'] as $row)
                    <li class="text-[12px] text-ink-800 truncate">{{ $row['label'] }}</li>
                @endforeach
            </ul>
        @endif
        @if ($cs['instagram']['platform'])
            <div class="px-4 pb-3">
                <button type="button" data-channel-connect="instagram"
                    class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-wa-deep hover:underline">
                    {{ $cs['instagram']['connected'] ? __('Connect another account') : __('Connect Instagram') }}
                </button>
            </div>
        @endif
    </div>
    @endif

    {{-- TikTok --}}
    @if (! empty($cs['tiktok']['platform']))
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="w-5 h-5 rounded-md tt-grad grid place-items-center shrink-0">
                        <svg viewBox="0 0 24 24" class="w-3 h-3" fill="currentColor"><path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/></svg>
                    </span>
                    <span class="text-[13.5px] font-semibold">{{ __('TikTok') }}</span>
                    @if ($cs['tiktok']['connected'])
                        <span class="font-mono text-[10px] text-wa-deep">{{ count($cs['tiktok']['accounts']) }} {{ __('linked') }}</span>
                    @else
                        <span class="font-mono text-[10px] text-ink-500">{{ $cs['tiktok']['platform'] ? __('not connected') : __('admin must enable') }}</span>
                    @endif
                </div>
                <p class="text-[11.5px] text-ink-500 mt-0.5">{{ $cs['tiktok']['hint'] }}</p>
            </div>
            {!! $toggle('channel_tiktok') !!}
        </div>
        @include('user.ai-training._channel-control', ['chKey' => 'tiktok'])
        @if ($cs['tiktok']['accounts'])
            <ul class="border-t border-paper-100 px-4 py-2 space-y-1 bg-paper-50/50">
                @foreach ($cs['tiktok']['accounts'] as $row)
                    <li class="text-[12px] text-ink-800 truncate">{{ $row['label'] }}</li>
                @endforeach
            </ul>
        @endif
        @if ($cs['tiktok']['platform'])
            <div class="px-4 pb-3">
                <a data-channel-connect="tiktok" href="{{ $cs['tiktok']['connect_url'] }}"
                    class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-wa-deep hover:underline">
                    {{ __('Connect TikTok') }}
                </a>
            </div>
        @endif
    </div>
    @endif

    {{-- Shopify --}}
    @if (! empty($cs['shopify']['platform']))
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-[13.5px] font-semibold">{{ __('Shopify') }}</span>
                    @if ($cs['shopify']['connected'])
                        <span class="font-mono text-[10px] text-wa-deep">{{ $cs['shopify']['accounts'][0]['label'] ?? __('connected') }}</span>
                    @else
                        <span class="font-mono text-[10px] text-ink-500">{{ $cs['shopify']['platform'] ? __('not connected') : __('admin must enable') }}</span>
                    @endif
                </div>
                <p class="text-[11.5px] text-ink-500 mt-0.5">{{ $cs['shopify']['hint'] }}</p>
            </div>
            {!! $toggle('shopify_tools') !!}
        </div>
        @if ($cs['shopify']['accounts'])
            <ul class="border-t border-paper-100 px-4 py-2 space-y-1 bg-paper-50/50">
                @foreach ($cs['shopify']['accounts'] as $row)
                    <li class="text-[12px] text-ink-800 truncate">{{ $row['label'] }} <span class="font-mono text-[10px] text-ink-500">{{ $row['detail'] }}</span></li>
                @endforeach
            </ul>
        @endif
        @if ($cs['shopify']['platform'] && ! $cs['shopify']['connected'])
            <form method="POST" action="{{ $cs['shopify']['connect_url'] }}" class="px-4 pb-3 space-y-2" data-channel-form="shopify">
                @csrf
                <input type="hidden" name="return" value="">
                <label class="block text-[11.5px] font-semibold text-ink-700">{{ __('Store domain') }}</label>
                <div class="flex items-stretch border border-paper-200 rounded-lg overflow-hidden bg-white">
                    <input type="text" name="shop" required placeholder="{{ __('my-store') }}"
                        class="flex-1 px-3 py-2 text-[12.5px] font-mono focus:outline-none">
                    <span class="px-3 py-2 bg-paper-50 border-l border-paper-200 text-[11.5px] font-mono text-ink-500">.myshopify.com</span>
                </div>
                <button type="submit" class="px-4 py-1.5 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold">{{ __('Continue to Shopify') }}</button>
            </form>
        @elseif ($cs['shopify']['connected'])
            <div class="px-4 pb-3">
                <a href="{{ url('/shopify') }}" class="text-[12px] font-semibold text-wa-deep hover:underline">{{ __('Open Shopify dashboard') }}</a>
            </div>
        @endif
    </div>
    @endif
</div>
