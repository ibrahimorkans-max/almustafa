/*
 * Keeps the menu open on a computer after a menu item is clicked.
 *
 * Filament closes the sidebar on every item click when the window is 1024 px wide or less, and
 * remembers it closed. A laptop at 1280 px with Windows scaling at 125 % is exactly 1024 px, so
 * there the docked menu folded away after each click and stayed folded. On phones (narrower
 * than 1024 px, where the menu covers the page) it still closes after a click, as it should.
 *
 * A menu the person folded with the button stays folded; one folded only by that click is
 * opened again.
 */
(function () {
    var KEY = 'mn.sidebarClosedByUser';
    var docked = function () { return window.matchMedia('(min-width: 1024px)').matches; };
    var remember = function (on) { try { on ? localStorage.setItem(KEY, '1') : localStorage.removeItem(KEY); } catch (e) {} };
    var closedByUser = function () { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } };

    function setUp() {
        var store = window.Alpine && window.Alpine.store('sidebar');
        if (!store || store.__keepOpen) {
            return;
        }
        store.__keepOpen = true;

        var itemClick = false;
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('.fi-sidebar-item')) {
                itemClick = true;
                setTimeout(function () { itemClick = false; }, 0);
            }
        }, true);

        var close = store.close, open = store.open;
        store.close = function () {
            if (itemClick && docked()) {
                return;
            }
            if (!itemClick) {
                remember(true);
            }
            close.call(this);
        };
        store.open = function () {
            remember(false);
            open.call(this);
        };

        if (docked() && !store.isOpen && !closedByUser()) {
            open.call(store);
        }
    }

    if (window.Alpine && window.Alpine.store && window.Alpine.store('sidebar')) {
        setUp();
    } else {
        document.addEventListener('alpine:initialized', setUp);
    }
})();
