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
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
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

    {{-- Instagram --}}
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
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

    {{-- TikTok --}}
    <div class="border border-paper-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-3.5 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
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

    {{-- Shopify --}}
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
</div>
