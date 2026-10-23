{{--
 Custom / bring-your-own SMS gateway form. Describes any provider's HTTP API and
 posts to /connect/wa-store/sms-custom. Stored as a provider='sms',
 sms_provider='custom' row → sends via CustomHttpTransport in campaigns + broadcasts.
 Written to be followed step-by-step by a non-technical operator.
--}}
@php $__smsBase = preg_replace('#^http://#i', 'https://', url('/')); @endphp

{{-- What this is + where every value comes from. --}}
<div class="rounded-xl bg-paper-50 border border-paper-200 p-4 mb-5 text-[12px] text-ink-700 leading-relaxed">
    <div class="font-semibold text-ink-800 mb-1">{{ __('What you need') }}</div>
    {{ __('Open your SMS provider’s API documentation (look for “Send SMS API” or “HTTP API”). You only need 4 things from it:') }}
    <ol class="list-decimal pl-5 mt-2 space-y-1">
        <li>{{ __('The send URL (the web address you POST/GET a message to).') }}</li>
        <li>{{ __('Whether it’s GET or POST, and the body shape (JSON, form, or query string).') }}</li>
        <li>{{ __('How your API key is sent (in a header, or inside the body/URL).') }}</li>
        <li>{{ __('Your API key itself.') }}</li>
    </ol>
    <div class="mt-3 rounded-lg bg-paper-0 border border-paper-200 p-3 text-[11.5px]">
        <div class="font-semibold text-ink-800 mb-1">{{ __('Worked example') }}</div>
        {{ __('If the docs say: POST https://api.smsgw.com/send with JSON body {"phone":"...","message":"...","apikey":"..."}, you would enter:') }}
        <ul class="mt-1.5 space-y-0.5 font-mono text-[11px] text-ink-700">
            <li>{{ __('Method = POST · Body type = JSON') }}</li>
            <li>{{ __('Send URL = https://api.smsgw.com/send') }}</li>
            <li>{{ __('Body = {"phone":"{to}","message":"{text}","apikey":"{api_key}"}') }}</li>
            <li>{{ __('API key = (your real key — it replaces {api_key})') }}</li>
        </ul>
    </div>
</div>

<form method="POST" action="{{ url('/connect/wa-store/sms-custom') }}" class="space-y-6">
    @csrf
    <input type="hidden" name="return_to" value="/sms">

    @if (session()->has('sms_test_msg'))
        <div class="px-3 py-2 rounded-lg text-[12px] border {{ session('sms_test_ok') ? 'bg-wa-mint border-wa-green/30 text-wa-deep' : 'bg-accent-coral/10 border-accent-coral/25 text-accent-coral' }}">
            {{ session('sms_test_msg') }}
        </div>
    @endif

    {{-- STEP 1 --}}
    <div>
        <div class="flex items-center gap-2 mb-3">
            <span class="w-6 h-6 rounded-full grid place-items-center text-[11px] font-mono bg-wa-deep text-paper-0 shrink-0">1</span>
            <h3 class="font-serif text-[15px]">{{ __('Your sending number') }}</h3>
        </div>
        <div class="grid md:grid-cols-3 gap-4">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('From number / Sender ID') }} <span class="text-accent-coral">*</span></span>
                <input name="from_number" maxlength="64" required placeholder="+15551234567" value="{{ old('from_number') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('The number (or brand name) messages are sent FROM.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Sender ID') }} <span class="font-mono text-[10px] text-ink-500">{{ __('optional') }}</span></span>
                <input name="sender_id" maxlength="32" placeholder="BRAND" value="{{ old('sender_id') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Used in the {sender_id} placeholder if your API needs it separately.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Name') }}</span>
                <input name="display_label" maxlength="40" placeholder="My SMS gateway" value="{{ old('display_label') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('A label so you recognise it in the list.') }}</span>
            </label>
        </div>
    </div>

    {{-- STEP 2 --}}
    <div>
        <div class="flex items-center gap-2 mb-3">
            <span class="w-6 h-6 rounded-full grid place-items-center text-[11px] font-mono bg-wa-deep text-paper-0 shrink-0">2</span>
            <h3 class="font-serif text-[15px]">{{ __('How to send (from the provider’s docs)') }}</h3>
        </div>
        {{-- Build method: simple Template, or the detailed Parameter mapping ("Create your own server"). --}}
        <label class="block max-w-sm mb-4">
            <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Build method') }}</span>
            <select name="mode" id="csms-mode" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] focus:outline-none focus:border-wa-deep">
                <option value="template" @selected(old('mode','template')==='template')>{{ __('Template — write the URL/body with placeholders') }}</option>
                <option value="params" @selected(old('mode')==='params')>{{ __('Parameter mapping — map each field (like a bulk-SMS server)') }}</option>
            </select>
        </label>

        {{-- Shared: method + URL --}}
        <div class="grid md:grid-cols-3 gap-4">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('HTTP method') }}</span>
                <select name="method" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] focus:outline-none focus:border-wa-deep">
                    <option value="POST" @selected(old('method','POST')==='POST')>POST</option>
                    <option value="GET" @selected(old('method')==='GET')>GET</option>
                </select>
            </label>
            <label class="block md:col-span-2">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Base / Send URL') }} <span class="text-accent-coral">*</span></span>
                <input name="url" maxlength="1000" required placeholder="https://api.yourprovider.com/send" value="{{ old('url') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
            </label>
        </div>

        {{-- ========== TEMPLATE MODE ========== --}}
        <div data-pbm="template" class="mt-4 space-y-4">
            <label class="block max-w-xs">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Body type (for POST)') }}</span>
                <select name="content_type" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] focus:outline-none focus:border-wa-deep">
                    <option value="json" @selected(old('content_type','json')==='json')>{{ __('JSON — {"to":"..."}') }}</option>
                    <option value="form" @selected(old('content_type')==='form')>{{ __('Form — to=...&text=...') }}</option>
                    <option value="query" @selected(old('content_type')==='query')>{{ __('Query string in the URL') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Body template') }}</span>
                <textarea name="body" rows="3" placeholder='{"to":"{to}","message":"{text}","apikey":"{api_key}"}' class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep">{{ old('body') }}</textarea>
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Replace the recipient with {to}, the message with {text}, your key with {api_key}.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Headers') }} <span class="font-mono text-[10px] text-ink-500">{{ __('one per line') }}</span></span>
                <textarea name="headers" rows="2" placeholder="Authorization: Bearer {api_key}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep">{{ old('headers') }}</textarea>
            </label>
            <div class="rounded-lg bg-paper-50 border border-paper-200 px-3 py-2 text-[10.5px] text-ink-600 leading-relaxed">
                <span class="font-semibold">{{ __('Placeholders:') }}</span>
                <code>{to}</code> <code>{to_e164}</code> · <code>{text}</code> <code>{text_url}</code> · <code>{from}</code>/<code>{sender_id}</code> · <code>{api_key}</code>/<code>{api_secret}</code> · <code>{segments}</code>.
            </div>
        </div>

        {{-- ========== PARAMETER-MAPPING MODE ========== --}}
        <div data-pbm="params" class="mt-4 space-y-4 hidden">
            <div class="grid md:grid-cols-3 gap-3">
                <label class="flex items-center gap-2 text-[12px] font-semibold text-ink-700"><input type="checkbox" name="json_post" value="1" @checked(old('json_post'))> {{ __('JSON-encoded POST') }}</label>
                <label class="flex items-center gap-2 text-[12px] font-semibold text-ink-700"><input type="checkbox" name="ignore_ssl" value="1" @checked(old('ignore_ssl'))> {{ __('Ignore SSL verification') }}</label>
                <label class="block">
                    <span class="text-[11px] font-semibold text-ink-700 mb-1 block">{{ __('Authorization') }}</span>
                    <select name="auth" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px]">
                        <option value="params" @selected(old('auth','params')==='params')>{{ __('On parameters') }}</option>
                        <option value="basic" @selected(old('auth')==='basic')>{{ __('Basic auth (key:secret)') }}</option>
                        <option value="bearer" @selected(old('auth')==='bearer')>{{ __('Bearer token (api key)') }}</option>
                        <option value="none" @selected(old('auth')==='none')>{{ __('None') }}</option>
                    </select>
                </label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700 mb-1 block">{{ __('Content-Type header') }}</span><input name="content_type_header" value="{{ old('content_type_header') }}" placeholder="application/json" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700 mb-1 block">{{ __('Accept header') }}</span><input name="accept" value="{{ old('accept') }}" placeholder="application/json" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700 mb-1 block">{{ __('Character encoding') }}</span><input name="encoding" value="{{ old('encoding') }}" placeholder="UTF-8" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono"></label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-[12px] border border-paper-200 rounded-xl overflow-hidden">
                    <thead class="bg-paper-50 text-ink-600">
                        <tr>
                            <th class="text-left font-semibold px-3 py-2 w-48">{{ __('Field') }}</th>
                            <th class="text-left font-semibold px-3 py-2">{{ __('Parameter name') }}</th>
                            <th class="text-left font-semibold px-3 py-2">{{ __('Value') }}</th>
                            <th class="text-center font-semibold px-3 py-2 w-24">{{ __('On URL') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-100">
                        @php
                            $rows = [
                                'apikey'   => ['Username / API Key', false],
                                'password' => ['Password', false],
                                'action'   => ['Action', false],
                                'source'   => ['Source / Sender', false],
                                'dest'     => ['Destination (recipient)', true],
                                'message'  => ['Message', true],
                                'unicode'  => ['Unicode', false],
                                'route'    => ['Type / Route', false],
                                'language' => ['Language', false],
                                'custom1'  => ['Custom Value 1', false],
                                'custom2'  => ['Custom Value 2', false],
                                'custom3'  => ['Custom Value 3', false],
                            ];
                        @endphp
                        @foreach ($rows as $k => [$label, $auto])
                            <tr>
                                <td class="px-3 py-1.5 text-ink-700">{{ __($label) }}</td>
                                <td class="px-2 py-1.5"><input name="params[{{ $k }}][name]" value="{{ old('params.'.$k.'.name') }}" placeholder="{{ __('e.g.') }} {{ $k === 'dest' ? 'to' : ($k === 'message' ? 'text' : $k) }}" class="w-full px-2 py-1.5 rounded-lg border border-paper-200 text-[12px] font-mono"></td>
                                <td class="px-2 py-1.5">
                                    @if ($auto)
                                        <input disabled value="{{ $k === 'dest' ? __('(recipient number)') : __('(message text)') }}" class="w-full px-2 py-1.5 rounded-lg border border-paper-100 bg-paper-50 text-[12px] text-ink-400">
                                    @else
                                        <input name="params[{{ $k }}][value]" value="{{ old('params.'.$k.'.value') }}" placeholder="{{ $k === 'apikey' ? '{api_key} '.__('or literal') : '' }}" class="w-full px-2 py-1.5 rounded-lg border border-paper-200 text-[12px] font-mono">
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-center"><input type="checkbox" name="params[{{ $k }}][on_url]" value="1" @checked(old('params.'.$k.'.on_url'))></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-[10.5px] text-ink-500">{{ __('Set the provider’s parameter name for each field you need. Destination = recipient, Message = text (filled automatically). Values accept {api_key}/{api_secret}/{sender_id} placeholders. “On URL” forces a field into the query string; GET always uses the URL.') }}</p>
        </div>

        <script>
        (function(){
            var sel=document.getElementById('csms-mode'); if(!sel) return;
            function sync(){ document.querySelectorAll('[data-pbm]').forEach(function(el){ el.classList.toggle('hidden', el.getAttribute('data-pbm')!==sel.value); }); }
            sel.addEventListener('change', sync); sync();
        })();
        </script>
    </div>

    {{-- STEP 3 --}}
    <div>
        <div class="flex items-center gap-2 mb-3">
            <span class="w-6 h-6 rounded-full grid place-items-center text-[11px] font-mono bg-wa-deep text-paper-0 shrink-0">3</span>
            <h3 class="font-serif text-[15px]">{{ __('Your API key') }}</h3>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('API key') }} <span class="font-mono text-[10px] text-ink-500">{{ __('fills {api_key}') }}</span></span>
                <input name="api_key" type="password" autocomplete="new-password" maxlength="255" placeholder="{{ __('paste your key — leave blank to keep') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Stored encrypted. Put the placeholder {api_key} in the body/header/URL above — this real key replaces it at send time.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('API secret') }} <span class="font-mono text-[10px] text-ink-500">{{ __('fills {api_secret}, optional') }}</span></span>
                <input name="api_secret" type="password" autocomplete="new-password" maxlength="255" placeholder="{{ __('only if your API needs a second key') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Most providers need only one key — leave this blank.') }}</span>
            </label>
        </div>
    </div>

    {{-- STEP 4 (optional) --}}
    <details class="rounded-xl border border-paper-200 px-4 py-3">
        <summary class="text-[13px] font-semibold text-ink-800 cursor-pointer">{{ __('Step 4 (optional) — tell us how to read the response') }}</summary>
        <p class="text-[10.5px] text-ink-500 mt-2">{{ __('By default any “200 OK” reply counts as sent. Fill these only if you want to be stricter or to save the provider’s message id.') }}</p>
        <div class="grid md:grid-cols-2 gap-4 mt-3">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Success contains') }}</span>
                <input name="success_contains" maxlength="120" placeholder='"status":"queued"' value="{{ old('success_contains') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Text that must appear in the reply to count as success.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Message-ID path') }}</span>
                <input name="message_id_path" maxlength="120" placeholder="data.id" value="{{ old('message_id_path') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Where the reply holds the message id, e.g. data.id — used to match delivery reports.') }}</span>
            </label>
        </div>
    </details>

    {{-- STEP 5 (optional) — receive --}}
    <details class="rounded-xl border border-paper-200 px-4 py-3">
        <summary class="text-[13px] font-semibold text-ink-800 cursor-pointer">{{ __('Step 5 (optional) — receive replies & delivery reports') }}</summary>
        <p class="text-[10.5px] text-ink-500 mt-2">{{ __('After you Save, you’ll get an Inbound URL and a DLR URL to paste into your provider’s panel. Here you tell us which field in their webhook carries each value.') }}</p>
        <div class="text-[11px] font-semibold text-ink-700 mt-3 mb-1">{{ __('Incoming reply — field names in the provider’s webhook') }}</div>
        <div class="grid md:grid-cols-3 gap-3">
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('Sender field') }}</span>
                <input name="in_from_field" maxlength="64" placeholder="from" value="{{ old('in_from_field') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('Message-text field') }}</span>
                <input name="in_text_field" maxlength="64" placeholder="text" value="{{ old('in_text_field') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('Our-number field') }}</span>
                <input name="in_to_field" maxlength="64" placeholder="to" value="{{ old('in_to_field') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
        </div>
        <div class="text-[11px] font-semibold text-ink-700 mt-4 mb-1">{{ __('Delivery report — field names + which values mean delivered / failed') }}</div>
        <div class="grid md:grid-cols-2 gap-3">
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('Message-id field') }}</span>
                <input name="dlr_id_field" maxlength="64" placeholder="message_id" value="{{ old('dlr_id_field') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('Status field') }}</span>
                <input name="dlr_status_field" maxlength="64" placeholder="status" value="{{ old('dlr_status_field') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('“Delivered” values') }}</span>
                <input name="dlr_delivered" maxlength="255" placeholder="delivered,DELIVRD,1" value="{{ old('dlr_delivered') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
            <label class="block"><span class="text-[11px] text-ink-600 mb-1 block">{{ __('“Failed” values') }}</span>
                <input name="dlr_failed" maxlength="255" placeholder="failed,UNDELIV,2" value="{{ old('dlr_failed') }}" class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep" /></label>
        </div>
    </details>

    {{-- STEP 6 (optional) — cost --}}
    <details class="rounded-xl border border-paper-200 px-4 py-3">
        <summary class="text-[13px] font-semibold text-ink-800 cursor-pointer">{{ __('Cost reporting (optional)') }}</summary>
        <div class="grid md:grid-cols-2 gap-4 mt-3">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Rate per segment') }}</span>
                <input name="rate_per_segment" type="number" step="0.0001" min="0" placeholder="0.005" value="{{ old('rate_per_segment') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep" />
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Currency') }}</span>
                <input name="currency" maxlength="8" placeholder="USD" value="{{ old('currency', 'USD') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep" />
            </label>
        </div>
    </details>

    @error('url')
        <div class="px-3 py-2 rounded-lg bg-accent-coral/10 border border-accent-coral/25 text-[12px] text-accent-coral">{{ $message }}</div>
    @enderror

    {{-- STEP 6 — test + save --}}
    <div class="flex flex-wrap items-end justify-between gap-3 pt-4 border-t border-paper-200">
        <div class="flex items-end gap-2">
            <label class="block">
                <span class="text-[11px] font-semibold text-ink-700 mb-1 block">{{ __('Test it first — send to') }}</span>
                <input name="test_number" maxlength="32" placeholder="+15551234567" value="{{ old('test_number') }}"
                    class="px-3 py-2 rounded-xl border border-paper-200 bg-white text-[12.5px] font-mono focus:outline-none focus:border-wa-deep" />
            </label>
            <button type="submit" formaction="{{ route('user.connect.wa-store.sms-custom.test') }}"
                class="px-4 py-2 rounded-full border border-paper-300 text-ink-700 text-[12px] font-semibold hover:border-wa-deep transition">{{ __('Send test') }}</button>
        </div>
        <button type="submit" class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal transition">{{ __('Save custom gateway') }}</button>
    </div>
</form>
