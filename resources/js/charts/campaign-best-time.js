// Best-time-to-send — fetches the engagement heatmap (pure stats, no AI) and
// renders it on the campaign create wizard's Schedule step, with a one-click
// "Use best time" that fills the schedule fields. No-op if the card is absent.
import ApexCharts from 'apexcharts';
import { themeColor } from '../theme-colors.js';

export function initBestTime() {
    const card   = document.getElementById('best-time-card');
    const heatEl = document.getElementById('best-time-heatmap');
    const sub    = document.getElementById('best-time-sub');
    const useBtn = document.getElementById('best-time-use');
    if (!card || !heatEl) return;

    const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; // grid dow 0=Mon..6=Sun
    let data = null;
    let rendered = false;

    // ApexCharts needs a laid-out (non-display:none) container, so only draw the
    // heatmap once Step 4 (Schedule) is actually visible.
    const pane = document.querySelector('.step-pane[data-step="4"]');
    function tryRender() {
        if (rendered || !data || !data.has_data) return;
        if (pane && pane.classList.contains('hidden')) return;
        rendered = true;

        let max = 0;
        data.grid.forEach((row) => row.forEach((v) => { if (v > max) max = v; }));
        const norm = (v) => (max > 0 ? Math.round((v / max) * 100) : 0);
        // Mon on top → reverse so the y-axis reads Mon…Sun top-to-bottom.
        const series = DAYS.map((name, d) => ({
            name,
            data: Array.from({ length: 24 }, (_, h) => ({ x: String(h), y: norm((data.grid[d] || [])[h] || 0) })),
        })).reverse();
        const label = { colors: '#7A8B86', fontSize: '10px' };

        new ApexCharts(heatEl, {
            chart: { type: 'heatmap', height: 230, toolbar: { show: false }, fontFamily: 'inherit' },
            series,
            dataLabels: { enabled: false },
            xaxis: { labels: { style: label }, tickAmount: 12 },
            yaxis: { labels: { style: label } },
            grid: { padding: { top: 0, right: 0, bottom: 0, left: 0 } },
            legend: { show: false },
            tooltip: { y: { formatter: (v) => v + '% of peak' } },
            plotOptions: { heatmap: { shadeIntensity: 0.5, radius: 4, colorScale: { ranges: [
                { from: 0, to: 25, color: themeColor('paper-200') },
                { from: 26, to: 60, color: themeColor('wa-teal') },
                { from: 61, to: 100, color: themeColor('wa-deep') },
            ] } } },
        }).render();
    }
    if (pane) new MutationObserver(tryRender).observe(pane, { attributes: true, attributeFilter: ['class'] });

    function apply() {
        if (!data) return;
        card.classList.remove('hidden');

        if (!data.has_data) {
            if (sub) sub.textContent = 'Not enough history yet — once a few campaigns have engagement, the best time to send shows here.';
            return;
        }

        if (data.best && data.best_label) {
            if (sub) sub.textContent = 'Your audience engages most on ' + data.best_label + ' (' + data.tz + '), from ' + data.samples + ' past engagements.';
            if (useBtn) {
                useBtn.classList.remove('hidden');
                useBtn.addEventListener('click', () => {
                    const dEl = document.getElementById('send-date');
                    const tEl = document.getElementById('send-time');
                    if (dEl && data.next_date) dEl.value = data.next_date;
                    if (tEl && data.next_time) tEl.value = data.next_time;
                    const sched = document.querySelector('input[name="schedule_type"][value="scheduled"]');
                    if (sched) { sched.checked = true; sched.dispatchEvent(new Event('change', { bubbles: true })); }
                    useBtn.textContent = 'Applied ✓';
                    setTimeout(() => { useBtn.textContent = 'Use best time'; }, 2000);
                });
            }
        } else if (sub) {
            sub.textContent = 'Showing when your audience is active — not enough concentrated data for one best time yet (' + data.samples + ' engagements).';
        }
    }

    fetch(card.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => { data = d; apply(); tryRender(); })
        .catch(() => {});
}
