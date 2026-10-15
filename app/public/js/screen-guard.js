/*
 * Discourages screenshots and screen recordings of the ERP. A web page cannot stop them outright
 * (the operating system, other programs and a phone camera are outside its reach), so this makes
 * them hard and traceable:
 *  - every screen carries a faint watermark with the user's name, IP and the time, so a leaked
 *    picture shows who took it and when;
 *  - the page is blanked whenever the browser window loses focus, which is what the Snipping Tool
 *    (Win+Shift+S), the Game Bar recorder and most capture programs cause when they start;
 *  - the Print Screen key wipes the clipboard and blanks the page for a moment;
 *  - printing and "Save as PDF" print an empty page.
 * Screens marked data-screen-guard="display" (the live weight board on a gate monitor) keep the
 * watermark but are not blanked, because they are watched without being clicked.
 */
(function () {
    const me = document.currentScript;
    if (!me) return;
    const label = me.dataset.label || '';

    const style = document.createElement('style');
    style.textContent = `
        #rm-watermark { position: fixed; inset: 0; z-index: 2147483000; pointer-events: none; opacity: .07;
            background-repeat: repeat; }
        #rm-shield { position: fixed; inset: 0; z-index: 2147483647; display: none; align-items: center;
            justify-content: center; background: #0b0f19; color: #e5e7eb; font: 600 18px system-ui, sans-serif;
            text-align: center; padding: 24px; }
        html.rm-shielded #rm-shield { display: flex; }
        html.rm-shielded body > *:not(#rm-shield) { filter: blur(24px); }
        @media print { body * { visibility: hidden !important; } body::after { content: 'Printing is disabled.';
            visibility: visible; } }
        body { -webkit-user-select: none; user-select: none; }
        input, textarea, [contenteditable="true"] { -webkit-user-select: text; user-select: text; }
    `;
    document.head.appendChild(style);

    function watermark() {
        const old = document.getElementById('rm-watermark');
        if (old) old.remove();
        const text = label + ' · ' + new Date().toLocaleString('en-PK', { dateStyle: 'medium', timeStyle: 'short' });
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="200">'
            + '<text x="10" y="110" transform="rotate(-20 210 100)" font-family="system-ui,sans-serif" font-size="15" fill="#808080">'
            + text.replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c])) + '</text></svg>';
        const div = document.createElement('div');
        div.id = 'rm-watermark';
        div.setAttribute('aria-hidden', 'true');
        div.style.backgroundImage = 'url("data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg) + '")';
        document.body.appendChild(div);
    }

    const shield = document.createElement('div');
    shield.id = 'rm-shield';
    shield.setAttribute('aria-hidden', 'true');
    shield.textContent = 'Hidden for privacy. Click here to continue.';

    function isDisplayScreen() {
        return document.querySelector('[data-screen-guard="display"]') !== null;
    }
    function hide() {
        if (!isDisplayScreen()) document.documentElement.classList.add('rm-shielded');
    }
    function show() {
        document.documentElement.classList.remove('rm-shielded');
    }

    function start() {
        document.body.appendChild(shield);
        watermark();
        setInterval(watermark, 60000);
        // A watermark removed in the developer tools comes back.
        new MutationObserver(() => { if (!document.getElementById('rm-watermark')) watermark(); })
            .observe(document.body, { childList: true });

        window.addEventListener('blur', hide);
        document.addEventListener('visibilitychange', () => { if (document.hidden) hide(); });
        window.addEventListener('focus', show);
        shield.addEventListener('click', show);

        document.addEventListener('keyup', (e) => {
            if (e.key === 'PrintScreen') {
                hide();
                try { navigator.clipboard.writeText(''); } catch (err) { /* not allowed: nothing to do */ }
                setTimeout(show, 1500);
            }
        });
        document.addEventListener('keydown', (e) => {
            const k = (e.key || '').toLowerCase();
            // Ctrl/Cmd+P (print), Ctrl/Cmd+Shift+S (some capture add-ons), Cmd+Shift+3/4/5 on a Mac.
            if ((e.ctrlKey || e.metaKey) && (k === 'p' || (e.shiftKey && ['s', '3', '4', '5'].includes(k)))) {
                e.preventDefault();
                hide();
                setTimeout(show, 1500);
            }
        });
        window.addEventListener('beforeprint', hide);
        window.addEventListener('afterprint', show);
    }

    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start, { once: true });
})();
