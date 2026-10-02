/**
 * One shared polling primitive for the whole user panel.
 *
 * Before this, 28 separate `setInterval(…fetch…)` sites each reinvented the
 * rules — and mostly got them wrong in the same three ways:
 *
 *   1. NO IN-FLIGHT GUARD. The timer fires again while the previous request is
 *      still running, so on a slow page requests stack on top of each other.
 *      That is what made a 208-device workspace unusable: a 25-second sweep on
 *      a 10-second timer.
 *   2. NO HIDDEN-TAB PAUSE. A tab left open in the background polls forever.
 *      Three idle tabs cost exactly as much as three working operators.
 *   3. NO BACKOFF. An idle page polls at the same rate as a busy one, so a
 *      workspace where nothing is happening generates the same load as one
 *      handling live conversations.
 *
 * Usage:
 *
 *   const p = createPoller(async () => {
 *       const changed = await refresh();
 *       return changed;        // false/undefined => idle, widen the interval
 *   }, { interval: 5000, maxInterval: 60000 });
 *   p.start();
 *
 * The callback's return value drives the backoff: return truthy when something
 * actually changed. Returning nothing is treated as "idle", which is the safe
 * default — the worst case is that the poller slows down.
 */

/**
 * @param {() => (Promise<boolean|void>|boolean|void)} fn
 * @param {object}  opts
 * @param {number}  opts.interval     base delay in ms (the busy cadence)
 * @param {number} [opts.maxInterval] ceiling when idle. Defaults to 6x interval.
 * @param {number} [opts.idleAfter=3] consecutive idle runs before widening
 * @param {boolean}[opts.pauseHidden=true] skip while the tab is in the background
 * @param {() => boolean} [opts.shouldSkip] extra guard, e.g. "a modal is open"
 */
export function createPoller(fn, opts = {}) {
    let base = Math.max(1000, Number(opts.interval) || 5000);
    let ceiling = Math.max(base, Number(opts.maxInterval) || base * 6);
    const idleAfter = Number.isFinite(opts.idleAfter) ? opts.idleAfter : 3;
    const pauseHidden = opts.pauseHidden !== false;
    const shouldSkip = typeof opts.shouldSkip === 'function' ? opts.shouldSkip : null;

    let timer = null;
    let current = base;
    let idleRuns = 0;
    let inFlight = false;
    let stopped = true;

    function schedule() {
        if (stopped) return;
        clearTimeout(timer);
        // setTimeout, not setInterval: the next delay is only decided AFTER the
        // previous run finishes, so a slow response can never queue up behind
        // itself the way a fixed interval does.
        timer = setTimeout(run, current);
    }

    async function run() {
        if (stopped) return;
        if (inFlight) return schedule();                      // never overlap
        if (pauseHidden && document.hidden) return schedule(); // tab in background
        if (shouldSkip && shouldSkip()) return schedule();     // caller says not now

        inFlight = true;
        try {
            const changed = await fn();
            if (changed) {
                // Something moved — snap back to the busy cadence.
                idleRuns = 0;
                current = base;
            } else if (++idleRuns >= idleAfter && current < ceiling) {
                // Quiet for a while — double the gap, up to the ceiling.
                current = Math.min(ceiling, current * 2);
                idleRuns = 0;
            }
        } catch (e) {
            // A failing poll must never kill the loop; just try again later.
            // Widen too, so a page left open against a dead backend stops
            // hammering it.
            if (current < ceiling) current = Math.min(ceiling, current * 2);
        } finally {
            inFlight = false;
            schedule();
        }
    }

    function onVisible() {
        if (!document.hidden) kick();
    }

    /** Run immediately and return to the busy cadence. */
    function kick() {
        if (stopped) return;
        idleRuns = 0;
        current = base;
        clearTimeout(timer);
        run();
    }

    function start({ immediate = true } = {}) {
        if (!stopped) return api;
        stopped = false;
        current = base;
        idleRuns = 0;
        if (pauseHidden) document.addEventListener('visibilitychange', onVisible);
        if (immediate) run();
        else schedule();
        return api;
    }

    function stop() {
        stopped = true;
        clearTimeout(timer);
        timer = null;
        if (pauseHidden) document.removeEventListener('visibilitychange', onVisible);
        return api;
    }

    /**
     * Re-tune the busy cadence at runtime.
     *
     * Some loops only learn the right rate from the server — the devices page
     * cannot know whether the workspace has 5 numbers or 208 until the first
     * response comes back, and 6 sweeps a minute is right for one and absurd
     * for the other. Idempotent: passing the same value twice does nothing.
     *
     * @param {number} ms        new base interval
     * @param {number} [maxMs]   new ceiling; defaults to 6x the base
     */
    function setBase(ms, maxMs) {
        const next = Math.max(1000, Number(ms) || base);
        if (next === base && !maxMs) return api;
        base = next;
        ceiling = Math.max(base, Number(maxMs) || base * 6);
        // Never leave the live delay below the new floor or above the new
        // ceiling — otherwise a widened loop would keep its old long gap after
        // being re-tuned to something faster.
        current = Math.min(Math.max(current, base), ceiling);
        return api;
    }

    const api = {
        start,
        stop,
        kick,
        setBase,
        /** Current delay in ms — exposed for tests and debugging. */
        get delay() { return current; },
        get base() { return base; },
        get running() { return !stopped; },
    };
    return api;
}

export default createPoller;
