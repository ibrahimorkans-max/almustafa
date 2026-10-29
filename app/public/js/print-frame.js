/*
 * Direct printing: "Print" buttons send `rm-print` with the address of a PDF (PrintDelivery). On a computer
 * the PDF loads in a hidden frame and the browser's print dialog opens on it; nothing is downloaded. On a
 * phone (no printing from frames) it opens in a new tab, where the share / print menu prints it.
 * Links and buttons marked data-rm-print="<url>" do the same.
 */
(function () {
    'use strict';

    var phone = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);

    function rmPrint(url) {
        if (!url) {
            return;
        }
        if (phone) {
            window.open(url, '_blank', 'noopener');
            return;
        }
        var old = document.getElementById('rm-print-frame');
        if (old) {
            old.remove();
        }
        var frame = document.createElement('iframe');
        frame.id = 'rm-print-frame';
        frame.title = 'Print';
        frame.setAttribute('aria-hidden', 'true');
        // Off screen but rendered: a frame that is hidden does not load the PDF viewer in every browser.
        frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:1200px;border:0;';
        frame.onload = function () {
            setTimeout(function () {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (e) {
                    window.open(url, '_blank', 'noopener');
                }
            }, 400);
        };
        frame.src = url;
        document.body.appendChild(frame);
    }

    window.rmPrint = rmPrint;
    window.addEventListener('rm-print', function (e) {
        var d = e.detail || {};
        rmPrint(d.url || (Array.isArray(d) && d[0] && d[0].url));
    });
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('[data-rm-print]');
        if (el) {
            e.preventDefault();
            rmPrint(el.getAttribute('data-rm-print') || el.getAttribute('href'));
        }
    });
})();
