/*
 * The weighbridge dial on the Live weight screen, kept current without reloading anything.
 *
 * Twice a second the reading is fetched as plain JSON (GET /admin/gate/live-weight/{scale}) and the dial's
 * needle, figure, maund and status are changed in place. The next read starts half a second after the last
 * one started (never two at once), so a new weight shows within about half a second of reaching the server. No Livewire request is made, so typing in the
 * weighing form and "Record weight" are never interrupted, and a refused or failed read only shows
 * "NO SIGNAL" until the next one succeeds: it never reloads the page.
 */
(function () {
    const EVERY = 500;
    const TONES = { none: '--gray-400', stable: '--success-500', moving: '--warning-500' };

    function fmt(n, digits) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }

    // The needle moves to its new angle in about a third of a second, through the SVG rotate attribute
    // (which every browser turns; some ignore a CSS transform on an SVG group).
    function turn(needle, to) {
        const from = parseFloat(needle.getAttribute('data-angle'));
        needle.setAttribute('data-angle', to);
        if (needle.lwFrame) cancelAnimationFrame(needle.lwFrame);
        const start = isNaN(from) ? to : (needle.lwAt !== undefined ? needle.lwAt : from);
        const t0 = performance.now();
        const step = (now) => {
            const k = Math.min(1, (now - t0) / 350);
            const at = start + (to - start) * (1 - Math.pow(1 - k, 3));
            needle.lwAt = at;
            needle.setAttribute('transform', 'rotate(' + at.toFixed(2) + ' 120 120)');
            needle.lwFrame = k < 1 ? requestAnimationFrame(step) : null;
        };
        if (document.hidden || typeof requestAnimationFrame !== 'function') {
            needle.lwAt = to;
            needle.setAttribute('transform', 'rotate(' + to + ' 120 120)');
        } else {
            needle.lwFrame = requestAnimationFrame(step);
        }
    }

    function paint(dial, r) {
        const cap = Math.max(1, parseFloat(dial.dataset.capacity) || 60000);
        const c = 120;
        const live = !!(r && r.live);
        const kg = r && r.kg !== null && r.kg !== undefined ? Number(r.kg) : null;
        const stable = live && !!r.stable;
        const tone = !live ? TONES.none : (stable ? TONES.stable : TONES.moving);
        const status = !live ? 'NO SIGNAL' + (r && r.age !== null && r.age !== undefined ? ' · ' + r.age + ' s' : '') : (stable ? 'STABLE' : 'MOVING');
        const angle = kg === null || !live ? -120 : -120 + 240 * Math.max(0, Math.min(kg, cap)) / cap;
        const q = (name) => dial.querySelector('[data-lw="' + name + '"]');

        q('bezel').style.fill = 'rgb(var(' + tone + '))';
        q('lamp').style.fill = 'rgb(var(' + tone + '))';
        const text = q('status');
        text.textContent = status;
        text.style.fill = 'rgb(var(' + (tone === TONES.none ? '--gray-500' : tone) + '))';
        const start = c - (status.length * 7.0 + 13) / 2 + 13;
        text.setAttribute('x', start);
        q('lamp').setAttribute('cx', start - 9);
        const figure = q('figure');
        figure.textContent = kg === null ? '— — — —' : fmt(kg, 0);
        figure.style.fill = live ? '#4ade80' : '#6b7280';
        q('maund').textContent = kg !== null ? fmt(kg / 40, 2) + ' maund' : 'no reading yet';
        turn(q('needle'), angle);
        const svg = dial.querySelector('svg');
        if (svg) svg.setAttribute('aria-label', (dial.dataset.name || '') + ': ' + (kg === null ? 'no reading' : fmt(kg, 0) + ' kg') + ', ' + status.toLowerCase());
    }

    function read(dial) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 8000);
        return fetch(dial.dataset.liveWeight, {
            credentials: 'same-origin', cache: 'no-store', redirect: 'manual', signal: ctrl.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => (res.ok ? res.json() : null))
            .catch(() => null)
            .then((r) => {
                clearTimeout(timer);
                if (!dial.isConnected) return;
                // One missed read (a network blip) keeps the last reading; three in a row show no signal.
                dial.lwFails = r ? 0 : (dial.lwFails || 0) + 1;
                if (r) {
                    dial.lwLast = r;
                    paint(dial, r);
                } else if (dial.lwFails >= 3) {
                    paint(dial, { kg: dial.lwLast ? dial.lwLast.kg : null, live: false, stable: false, age: null });
                }
            });
    }

    function tick() {
        const started = Date.now();
        const dials = document.hidden ? [] : Array.from(document.querySelectorAll('[data-live-weight]'));
        Promise.all(dials.map(read)).finally(() => setTimeout(tick, Math.max(0, EVERY - (Date.now() - started))));
    }

    tick();
})();
