// Admin > System Health — live Laravel / Node log viewer.
// "Live log" opens the panel and polls the tail endpoint every 2s, asking only
// for bytes after the last offset. Pause keeps the panel, Stop closes it and
// ends polling. Lines are coloured by level and can be filtered client-side.
const POLL_MS = 2000;
const MAX_LINES = 5000; // kept in the browser; older lines drop off the top

function lineClass(line) {
    if (/\b(ERROR|CRITICAL|ALERT|EMERGENCY|FATAL)\b|\.ERROR:/.test(line)) return 'text-red-300';
    if (/\b(WARNING|WARN)\b|\.WARNING:/.test(line)) return 'text-amber-300';
    if (/^\[\d{4}-\d{2}-\d{2}/.test(line)) return '';
    return 'text-white/55'; // stack-trace / continuation lines
}

function setupRow(row) {
    const url = row.dataset.tailUrl;
    const panel = row.querySelector('[data-log-panel]');
    const out = row.querySelector('[data-log-out]');
    const meta = row.querySelector('[data-log-meta]');
    const state = row.querySelector('[data-log-state]');
    const follow = row.querySelector('[data-log-follow]');
    const filter = row.querySelector('[data-log-filter]');
    const pauseBtn = row.querySelector('[data-log-pause]');
    let offset = null, timer = null, paused = false, running = false, busy = false, carry = '';

    const setState = (txt, live) => {
        state.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${live ? 'bg-emerald-400 animate-pulse' : 'bg-white/40'}"></span>${txt}`;
        state.classList.toggle('text-emerald-300', live);
        state.classList.toggle('text-white/60', !live);
    };

    const applyFilter = (el) => {
        const q = filter.value.trim().toLowerCase();
        el.hidden = q !== '' && !el.textContent.toLowerCase().includes(q);
    };

    const append = (text) => {
        if (!text) return;
        const parts = (carry + text).split('\n');
        carry = parts.pop(); // keep an unfinished last line for the next poll
        if (!parts.length) return;
        const frag = document.createDocumentFragment();
        parts.forEach((l) => {
            const div = document.createElement('div');
            div.textContent = l;
            const c = lineClass(l);
            if (c) div.className = c;
            applyFilter(div);
            frag.appendChild(div);
        });
        out.appendChild(frag);
        while (out.childElementCount > MAX_LINES) out.firstElementChild.remove();
        if (follow.checked) out.scrollTop = out.scrollHeight;
    };

    const poll = async () => {
        if (!running || paused || busy) return;
        busy = true;
        try {
            const q = offset === null ? '' : `?offset=${offset}`;
            const r = await fetch(url + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) {
                setState('UNAVAILABLE', false);
                out.textContent = j.error || 'Log not available.';
                stopTimer();
                return;
            }
            if (j.rotated) append('\n--- log was rotated or cleared ---\n');
            append(j.text);
            offset = j.offset;
            meta.textContent = `${j.file} · ${(j.size / 1048576).toFixed(2)} MB`;
            // Far behind (huge burst)? fetch the rest right away instead of waiting.
            if (j.behind > 0 && running && !paused) { busy = false; return poll(); }
        } catch (e) {
            setState('RECONNECTING', false);
        } finally {
            busy = false;
        }
        if (running && !paused) setState('LIVE', true);
    };

    const stopTimer = () => { clearInterval(timer); timer = null; };
    const start = () => {
        running = true; paused = false;
        pauseBtn.textContent = 'Pause';
        setState('LIVE', true);
        poll();
        stopTimer();
        timer = setInterval(poll, POLL_MS);
    };

    row.querySelector('[data-log-open]').addEventListener('click', () => {
        if (running) { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); return; }
        panel.hidden = false;
        out.textContent = ''; carry = ''; offset = null;
        start();
    });

    pauseBtn.addEventListener('click', () => {
        if (!running) return;
        paused = !paused;
        pauseBtn.textContent = paused ? 'Resume' : 'Pause';
        setState(paused ? 'PAUSED' : 'LIVE', !paused);
        if (!paused) poll();
    });

    row.querySelector('[data-log-clear]').addEventListener('click', () => { out.textContent = ''; });

    row.querySelector('[data-log-stop]').addEventListener('click', () => {
        running = false; paused = false;
        stopTimer();
        panel.hidden = true;
        out.textContent = ''; carry = ''; offset = null;
    });

    filter.addEventListener('input', () => out.querySelectorAll('div').forEach(applyFilter));

    // Scrolling up to read turns "Follow" off; reaching the bottom turns it back on.
    out.addEventListener('scroll', () => {
        follow.checked = out.scrollHeight - out.scrollTop - out.clientHeight < 30;
    });
}

export default function init() {
    document.querySelectorAll('[data-log-row]').forEach(setupRow);
}
