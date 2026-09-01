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

    // Heartbeat, like Google Analytics' engagement pings: without this, a
    // visitor who lands on a page and just reads it (no click, no route
    // change) sends exactly one event and silently drops out of "online now"
    // a few minutes later even though they're still on the page. Only ticks
    // while the tab is actually visible, matching GA's own behaviour of not
    // counting backgrounded time as engagement.
    var HEARTBEAT_INTERVAL_MS = 20000;
    var heartbeatTimer = null;

    function heartbeat() {
        send('heartbeat');
    }

    function startHeartbeat() {
        if (heartbeatTimer) return;
        heartbeatTimer = setInterval(heartbeat, HEARTBEAT_INTERVAL_MS);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    if (document.visibilityState === 'visible') {
        startHeartbeat();
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            startHeartbeat();
        } else {
            stopHeartbeat();
        }
    });

    // Public API for custom events: sitetrack.track('signup', {plan: 'pro'})
    window.sitetrack = {
        track: function (name, props) {
            if (name) {
                send('event', name, props || {});
            }
        }
    };
})();
