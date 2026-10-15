/*
 * Screens that refresh on their own (live weight, the gate list) send a request every second. When
 * the session's security token changed after the page loaded (signed in again or switched account
 * in another tab, or the session ended), that request gets 419 and Livewire would ask "This page has
 * expired. Refresh?" in front of a screen nobody touched. For such a background refresh, reload the
 * page quietly instead: it comes back with a fresh token, or goes to the sign-in page if the session
 * really ended. A 419 on something the person did (a save, a click) keeps Livewire's own question,
 * so typed input is never thrown away without asking.
 */
(function () {
    let reloading = false;

    function isBackgroundRefresh(payload) {
        try {
            const body = JSON.parse(payload);
            return Array.isArray(body.components) && body.components.every(
                (c) => (!c.calls || c.calls.length === 0) && (!c.updates || Object.keys(c.updates).length === 0),
            );
        } catch (e) {
            return false;
        }
    }

    function register() {
        window.Livewire.hook('request', ({ payload, fail }) => {
            fail(({ status, preventDefault }) => {
                if (status !== 419 || !isBackgroundRefresh(payload)) return;
                preventDefault();
                if (!reloading) {
                    reloading = true;
                    window.location.reload();
                }
            });
        });
    }

    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        register();
    } else {
        document.addEventListener('livewire:init', register, { once: true });
    }
})();
