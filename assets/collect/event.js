(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var siteId = script.getAttribute('data-site');
    if (!siteId) {
        return;
    }

    // Same origin the script itself was loaded from, so this works regardless
    // of which domain embeds it.
    var collectUrl = script.src.replace(/\/collect\/event\.js.*$/, '/api/event');

    function utmParams() {
        var params = new URLSearchParams(location.search);
        var utm = {};
        if (params.get('utm_source')) utm.utm_source = params.get('utm_source');
        if (params.get('utm_medium')) utm.utm_medium = params.get('utm_medium');
        if (params.get('utm_campaign')) utm.utm_campaign = params.get('utm_campaign');
        return utm;
    }

    function send(eventType, eventName, props) {
        var payload = JSON.stringify(Object.assign({
            site_id: siteId,
            path: location.pathname + location.search,
            referrer: document.referrer || null,
            event_type: eventType || 'pageview',
            event_name: eventName || null,
            props: props || null
        }, utmParams()));

        // text/plain keeps this a CORS-"simple" request (no preflight), and
        // sendBeacon/no-cors fetch never need to read the response anyway.
        if (navigator.sendBeacon) {
            navigator.sendBeacon(collectUrl, new Blob([payload], { type: 'text/plain' }));
            return;
        }

        fetch(collectUrl, {
            method: 'POST',
            mode: 'no-cors',
            keepalive: true,
            headers: { 'Content-Type': 'text/plain' },
            body: payload
        }).catch(function () {});
    }

    function pageview() {
        send('pageview');
    }

    pageview();

    // Minimal SPA support: re-fire on client-side route changes.
    var pushState = history.pushState;
    history.pushState = function () {
        pushState.apply(history, arguments);
        pageview();
    };
    window.addEventListener('popstate', pageview);

    // Public API for custom events: sitetrack.track('signup', {plan: 'pro'})
    window.sitetrack = {
        track: function (name, props) {
            if (name) {
                send('event', name, props || {});
            }
        }
    };
})();
