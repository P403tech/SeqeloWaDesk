import QRCode from 'qrcode';

/**
 * Render a scannable QR for each element carrying a `data-qr-url`. Used on the
 * Refer & Earn cards (account tab + /affiliate-history) so a customer can point
 * a friend's phone at the screen instead of copying a link. Rendered locally
 * from the bundled `qrcode` library — no external service, so referral URLs
 * never leave the page.
 */
export function initReferralQr() {
    document.querySelectorAll('[data-qr-url]').forEach((el) => {
        if (el.dataset.qrDone === '1') return;
        const url = (el.getAttribute('data-qr-url') || '').trim();
        if (url === '') return;

        QRCode.toString(
            url,
            { type: 'svg', margin: 1, color: { dark: '#0b3a2e', light: '#00000000' } },
            (err, svg) => {
                if (err) return;
                el.innerHTML = svg;
                // qrcode emits an SVG with a fixed width/height that ignores the
                // container and overflows onto neighbouring text. Strip those and
                // let it scale to the box (the viewBox keeps it crisp).
                const s = el.querySelector('svg');
                if (s) {
                    s.removeAttribute('width');
                    s.removeAttribute('height');
                    s.style.width = '100%';
                    s.style.height = '100%';
                    s.style.display = 'block';
                }
                el.dataset.qrDone = '1';
            }
        );
    });
}

export default initReferralQr;
