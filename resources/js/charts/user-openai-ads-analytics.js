import ApexCharts from 'apexcharts';
import { themeColor } from '../theme-colors.js';

/**
 * OpenAI Ads analytics charts. Reads the JSON payload emitted by
 * analytics.blade.php (#oaiads-chart-data) and renders a daily trend line +
 * a spend-by-campaign bar. Degrades cleanly to an "empty" note when there is
 * no data (e.g. a brand-new account or a range with no delivery).
 */
export default function init() {
    const el = document.getElementById('oaiads-chart-data');
    if (!el) return;

    let data;
    try { data = JSON.parse(el.textContent || '{}'); } catch (e) { return; }

    const font  = { fontFamily: 'Plus Jakarta Sans, system-ui, sans-serif' };
    const grid  = { borderColor: themeColor('paper-100'), strokeDashArray: 4 };
    const label = { colors: themeColor('ink-500'), fontSize: '11px' };
    const cur   = data.currency || '';

    // ── Daily trend (spend + clicks) ─────────────────────────────────
    const trend = data.trend || {};
    const labels = trend.labels || [];
    const trendEl = document.querySelector('#oaiads-trend');
    const trendEmpty = document.querySelector('#oaiads-trend-empty');
    if (trendEl && labels.length) {
        new ApexCharts(trendEl, {
            chart: { type: 'line', height: 300, toolbar: { show: false }, ...font },
            series: [
                { name: 'Spend', data: trend.spend || [] },
                { name: 'Clicks', data: trend.clicks || [] },
            ],
            colors: [themeColor('wa-deep'), themeColor('wa-teal')],
            stroke: { curve: 'smooth', width: 3 },
            markers: { size: 0 },
            grid,
            xaxis: { categories: labels, labels: { style: label, rotate: -45, hideOverlappingLabels: true } },
            yaxis: [
                { labels: { style: label, formatter: (v) => cur + ' ' + Math.round(v) } },
                { opposite: true, labels: { style: label, formatter: (v) => Math.round(v) } },
            ],
            legend: { show: true, labels: { colors: themeColor('ink-600') }, fontSize: '12px' },
            tooltip: { theme: 'light' },
        }).render();
    } else if (trendEl) {
        trendEl.classList.add('hidden');
        if (trendEmpty) trendEmpty.classList.remove('hidden');
    }

    // ── Spend by campaign (horizontal bar) ───────────────────────────
    const camps = data.campaigns || [];
    const barEl = document.querySelector('#oaiads-by-campaign');
    const barEmpty = document.querySelector('#oaiads-by-campaign-empty');
    if (barEl && camps.length) {
        new ApexCharts(barEl, {
            chart: { type: 'bar', height: 280, toolbar: { show: false }, ...font },
            series: [{ name: 'Spend', data: camps.map((c) => c.spend || 0) }],
            colors: [themeColor('wa-deep')],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
            dataLabels: { enabled: false },
            grid,
            xaxis: { categories: camps.map((c) => c.name), labels: { style: label, formatter: (v) => cur + ' ' + Math.round(v) } },
            yaxis: { labels: { style: label } },
            tooltip: { theme: 'light' },
        }).render();
    } else if (barEl) {
        barEl.classList.add('hidden');
        if (barEmpty) barEmpty.classList.remove('hidden');
    }
}
