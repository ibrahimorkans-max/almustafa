/*
 * "This page has expired" without losing what was typed.
 *
 * A request is refused with 419 when the session's security token changed after the page loaded: the
 * person signed in again, or switched firm or account, in another tab (or after the idle sign-out).
 * Instead of Livewire's "This page has expired. Refresh?" — or reloading the page and throwing away
 * what was typed — fetch the current token from the server, put it on the page, and send again what
 * was being done: the typed values go with the next request by themselves (they were never accepted),
 * and a button pressed (a method call) is pressed again once. If the session really ended, the token
 * address answers with the sign-in page and the page reloads there.
 */
(function () {
    const script = document.currentScript;
    const tokenUrl = script && script.dataset.tokenUrl;
    let refreshing = null;
    let recent = [];   // times of recent refusals: a loop of them means something else is wrong

    function setToken(token) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
        document.querySelectorAll('[data-csrf]').forEach((el) => el.setAttribute('data-csrf', token));
        if (window.livewireScriptConfig) window.livewireScriptConfig.csrf = token;
    }

    // One refresh at a time; true when the page now has a fresh token.
    function refreshToken() {
        if (!tokenUrl) return Promise.resolve(false);
        if (!refreshing) {
            refreshing = fetch(tokenUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, redirect: 'manual' })
                .then((r) => (r.ok ? r.json() : null))
                .then((body) => {
                    if (body && body.token) {
                        setToken(body.token);
                        return true;
                    }
                    return false;
                })
                .catch(() => false)
                .finally(() => setTimeout(() => { refreshing = null; }, 2000));
        }
        return refreshing;
    }

    // What the refused request was doing: [{ id, calls: [{ method, params }] }].
    function parse(payload) {
        try {
            const body = JSON.parse(payload);
            return (body.components || []).map((c) => {
                let id = null;
                try { id = JSON.parse(c.snapshot).memo.id; } catch (e) { /* no id */ }
                return { id, calls: c.calls || [], updates: c.updates || {} };
            });
        } catch (e) {
            return [];
        }
    }

    function resend(components) {
        components.forEach(({ id, calls, updates }) => {
            const component = id && window.Livewire.find(id);
            if (!component) return;
            if (calls.length > 0) {
                // A button pressed: press it again (the typed values go with it).
                calls.forEach((call) => component.call(call.method, ...(call.params || [])));
            } else if (Object.keys(updates).length > 0) {
                component.$commit();
            }
            // A background refresh (no calls, no updates) simply happens again at its next tick.
        });
    }

    function register() {
        window.Livewire.hook('request', ({ payload, fail }) => {
            fail(({ status, preventDefault }) => {
                if (status !== 419) return;
                preventDefault();
                const now = Date.now();
                recent = recent.filter((t) => now - t < 15000).concat(now);
                if (recent.length > 3) {
                    window.location.reload();
                    return;
                }
                const components = parse(payload);
                refreshToken().then((ok) => {
                    if (ok) {
                        resend(components);
                    } else {
                        window.location.reload();   // the session ended: back to sign-in
                    }
                });
            });
        });
    }

    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        register();
    } else {
        document.addEventListener('livewire:init', register, { once: true });
    }
})();
