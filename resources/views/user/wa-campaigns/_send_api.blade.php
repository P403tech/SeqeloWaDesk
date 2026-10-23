{{-- Send through: standard Cloud API (/messages) or Meta's Marketing Messages
     API (/marketing_messages). Only meaningful for a WABA sender, so
     campaign-send-api.js reveals it when the picked sender is a WABA number and
     asks Meta whether that number is onboarded. Utility / authentication
     templates always use the standard API whatever is chosen here. --}}
@php $sendApi = old('send_api', $value ?? null) === 'marketing' ? 'marketing' : 'cloud'; @endphp
<div data-send-api data-status-url="{{ route('user.wa-campaigns.api.mm-status') }}"
    data-msg-checking="{{ __('Checking this number with Meta...') }}"
    data-msg-onboarded="{{ __('This number is ready for the Marketing Messages API.') }}"
    data-msg-eligible="{{ __('Eligible, but not switched on yet. The business owner must open WhatsApp Manager > Overview and click Accept terms for the Marketing Messages API. Until then, messages go out through the standard API automatically.') }}"
    data-msg-other="{{ __('Meta has not enabled the Marketing Messages API for this number. Messages go out through the standard API automatically.') }}"
    class="hidden mt-3 rounded-lg border border-paper-200 p-3">
    <div class="text-[11.5px] font-semibold text-ink-700 mb-1.5">{{ __('Send marketing templates through') }}</div>
    <div class="grid sm:grid-cols-2 gap-2">
        <label class="flex items-start gap-2 rounded-lg border border-paper-200 px-3 py-2 cursor-pointer has-[:checked]:border-wa-deep has-[:checked]:bg-wa-bubble/40">
            <input type="radio" name="send_api" value="cloud" class="mt-0.5 accent-wa-deep" @checked($sendApi === 'cloud')>
            <span>
                <span class="block text-[12.5px] font-semibold text-ink-900">{{ __('Standard API') }}</span>
                <span class="block text-[10.5px] text-ink-500 mt-0.5">{{ __('The current method (Cloud API). Works for every template.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 rounded-lg border border-paper-200 px-3 py-2 cursor-pointer has-[:checked]:border-wa-deep has-[:checked]:bg-wa-bubble/40">
            <input type="radio" name="send_api" value="marketing" class="mt-0.5 accent-wa-deep" @checked($sendApi === 'marketing')>
            <span>
                <span class="block text-[12.5px] font-semibold text-ink-900">{{ __('Marketing Messages API') }}</span>
                <span class="block text-[10.5px] text-ink-500 mt-0.5">{{ __("Meta's own route for marketing templates, with delivery optimised by Meta.") }}</span>
            </span>
        </label>
    </div>
    <div data-send-api-status class="text-[10.5px] text-ink-500 mt-2"></div>
    <div class="text-[10.5px] text-ink-400 mt-1">{{ __('Only marketing templates use this route. Utility and authentication templates always go through the standard API.') }}</div>
</div>
