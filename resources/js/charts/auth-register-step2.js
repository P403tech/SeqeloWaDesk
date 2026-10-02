import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.css';
import intlTelInput from 'intl-tel-input/intlTelInputWithUtils';
import 'intl-tel-input/styles';

/*
 * Register / step 2 — workspace creation.
 *
 * Hydrates the timezone <select> with every IANA timezone the
 * browser knows about (via Intl.supportedValuesOf), then upgrades
 * it to a Tom Select searchable combobox so the user can type
 * "kolkata", "london", "berlin", etc. instead of scrolling.
 */
export default function init() {
    // Phone field — same flag / country-code picker as registration, so the
    // workspace-create form (and register step 2) get the dynamic country code
    // instead of a plain text box. Runs before the timezone early-return so it
    // works even on a page without the timezone select.
    initPhone();

    const sel = document.getElementById('ws-timezone');
    if (!sel) return;

    let zones = [];
    try {
        if (typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function') {
            zones = Intl.supportedValuesOf('timeZone') || [];
        }
    } catch (_) { /* ignore */ }

    if (!zones.length) {
        // Older browsers — fall back to a hand-curated short list.
        zones = [
            'UTC',
            'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Shanghai',
            'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Madrid', 'Europe/Moscow',
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Sao_Paulo', 'America/Toronto', 'America/Mexico_City',
            'Africa/Cairo', 'Africa/Johannesburg', 'Australia/Sydney', 'Pacific/Auckland',
        ];
    }

    // Pre-seed the existing option so it doesn't get wiped.
    const preselected = sel.value || sel.querySelector('option[selected]')?.value || 'Asia/Kolkata';
    sel.innerHTML = '';
    for (const tz of zones) {
        const opt = document.createElement('option');
        opt.value = tz;
        opt.textContent = tz;
        if (tz === preselected) opt.selected = true;
        sel.appendChild(opt);
    }
    if (!zones.includes(preselected)) {
        const opt = document.createElement('option');
        opt.value = preselected;
        opt.textContent = preselected;
        opt.selected = true;
        sel.prepend(opt);
    }

    new TomSelect(sel, {
        maxOptions: 1000,
        searchField: ['text'],
        sortField: { field: 'text', direction: 'asc' },
        placeholder: 'Search timezone...',
    });
}

/**
 * intl-tel-input flag picker on #reg-phone, syncing the dial code into the
 * hidden #reg-country-code. Mirrors auth-register.js. Guarded so it can never
 * double-initialise if two bundles run on the same page.
 */
function initPhone() {
    const phone = document.getElementById('reg-phone');
    const cc    = document.getElementById('reg-country-code');
    if (!phone || phone.dataset.itiDone) return;
    phone.dataset.itiDone = '1';

    const defIso  = (document.querySelector('meta[name="default-country-iso"]')?.content || 'in').toLowerCase();
    const defCode = (document.querySelector('meta[name="default-country-code"]')?.content || '+91');
    const initial = (cc?.value || defCode).replace(/[^\d]/g, '');
    const dialMap = { '1':'us','44':'gb','971':'ae','65':'sg','91':'in','62':'id','60':'my','66':'th','63':'ph','92':'pk','880':'bd' };
    const iso = dialMap[initial] || defIso;
    const preferred = Array.from(new Set([defIso, 'in', 'us', 'gb', 'ae', 'sg']));

    const iti = intlTelInput(phone, {
        initialCountry:     iso,
        preferredCountries: preferred,
        separateDialCode:   true,
        nationalMode:       false,
        dropdownContainer:  document.body,
    });
    const sync = () => { if (cc) cc.value = '+' + iti.getSelectedCountryData().dialCode; };
    sync();
    phone.addEventListener('countrychange', sync);
}
