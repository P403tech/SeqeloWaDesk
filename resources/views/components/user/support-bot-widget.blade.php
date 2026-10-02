@php $sbCfg = \App\Support\SupportBotSettings::publicConfig(); @endphp
@if (!empty($sbCfg['enabled']))
    {{-- Root only. The launcher + panel are built in support-bot-widget.js so
         markup stays in one place and the blade carries just config + routes. --}}
    <div id="support-bot-widget"
         data-config='@json($sbCfg)'
         data-ask-url="{{ route('support-bot.ask') }}"
         data-history-url="{{ route('support-bot.history') }}"
         data-sessions-url="{{ route('support-bot.sessions') }}"
         data-rate-url="{{ route('support-bot.rate') }}"
         data-clear-url="{{ route('support-bot.clear') }}"
         data-articles-url="{{ route('support-bot.articles') }}"
         data-csrf="{{ csrf_token() }}"></div>
@endif
