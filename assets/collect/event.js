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

    // Headless/automated browsers shouldn't inflate real traffic — checked
    // once at load, not on every event, since these markers don't change
    // mid-session.
    function isBot() {
        return navigator.webdriver === true
            || !!window.callPhantom
            || !!window._phantom
            || !!window.__nightmare;
    }
    if (isBot()) {
        return;
    }

    // A site owner testing their own site locally shouldn't pollute their
    // real analytics — opt back in with data-allow-localhost if that's
    // actually wanted (e.g. verifying the integration before going live).
    function isLocalHostname(hostname) {
        var h = (hostname || '').toLowerCase();
        return h === 'localhost'
            || h === '127.0.0.1'
            || h === '::1'
            || /^127(\.[0-9]+){0,3}$/.test(h)
            || h.slice(-6) === '.local'
            || h.slice(-10) === '.localhost';
    }
    if (isLocalHostname(location.hostname) && !script.hasAttribute('data-allow-localhost')) {
        return;
    }

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
            props: props || null,
            screen_width: screen.width || null,
            screen_height: screen.height || null
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

    // Minimal SPA support: re-fire on client-side route changes. Some
    // routers use replaceState instead of pushState (redirects, hash-based
    // routing), so both need patching — popstate alone only covers
    // back/forward navigation.
    var pushState = history.pushState;
    history.pushState = function () {
        pushState.apply(history, arguments);
        pageview();
    };
    var replaceState = history.replaceState;
    history.replaceState = function () {
        replaceState.apply(history, arguments);
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

    // Reads every attribute on el starting with prefix into a plain props
    // object (key = the part of the attribute name after the prefix),
    // skipping any suffix listed in reservedSuffixes (the feature's own
    // configuration attributes, not visitor-supplied data).
    function collectPrefixedProps(el, prefix, reservedSuffixes) {
        var props = {};
        for (var i = 0; i < el.attributes.length; i++) {
            var attr = el.attributes[i];
            if (attr.name.indexOf(prefix) !== 0) continue;
            var key = attr.name.slice(prefix.length);
            if (reservedSuffixes && reservedSuffixes.indexOf(key) !== -1) continue;
            props[key] = attr.value;
        }
        return props;
    }

    // Elements that already dispatch a native 'click' event when activated
    // from the keyboard (Enter and/or Space) — the keydown listener below
    // must skip these or a single activation would fire the goal twice.
    function isNativelyActivatable(el) {
        var tag = el.tagName;
        if (tag === 'BUTTON') return true;
        if (tag === 'A') return el.hasAttribute('href');
        if (tag === 'INPUT') {
            var type = (el.getAttribute('type') || '').toLowerCase();
            return type === 'button' || type === 'submit' || type === 'reset';
        }
        return false;
    }

    // Declarative conversion tracking: <button data-sitetrack-goal="signup"
    // data-sitetrack-goal-plan="pro">, no JS required. Extra
    // data-sitetrack-goal-* attributes become event props.
    var GOAL_SELECTOR = '[data-sitetrack-goal]';
    var GOAL_PREFIX = 'data-sitetrack-goal-';

    function fireGoal(el) {
        var name = el.getAttribute('data-sitetrack-goal');
        if (!name) return;
        send('event', name, collectPrefixedProps(el, GOAL_PREFIX, null));
    }

    document.addEventListener('click', function (event) {
        var el = event.target.closest && event.target.closest(GOAL_SELECTOR);
        if (el) fireGoal(el);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        var el = event.target.closest && event.target.closest(GOAL_SELECTOR);
        if (el && !isNativelyActivatable(el)) fireGoal(el);
    });

    // Declarative scroll-depth tracking: <div data-sitetrack-scroll
    // data-sitetrack-scroll-threshold="0.75" data-sitetrack-scroll-delay="1000">
    // fires once (event name = the attribute's value, defaulting to
    // "scroll") the first time that much of the element has stayed visible
    // for the given delay — a quick scroll-past doesn't count.
    if (typeof IntersectionObserver !== 'undefined') {
        var scrollElements = document.querySelectorAll('[data-sitetrack-scroll]');
        for (var s = 0; s < scrollElements.length; s++) {
            (function (el) {
                var thresholdAttr = parseFloat(el.getAttribute('data-sitetrack-scroll-threshold'));
                var threshold = isNaN(thresholdAttr) ? 0.5 : Math.min(Math.max(thresholdAttr, 0), 1);
                var delay = parseInt(el.getAttribute('data-sitetrack-scroll-delay'), 10) || 0;
                var timer = null;

                var observer = new IntersectionObserver(function (entries) {
                    var entry = entries[0];
                    if (entry.isIntersecting) {
                        timer = setTimeout(function () {
                            var name = el.getAttribute('data-sitetrack-scroll') || 'scroll';
                            send('event', name, collectPrefixedProps(el, 'data-sitetrack-scroll-', ['threshold', 'delay']));
                            observer.disconnect();
                        }, delay);
                    } else if (timer) {
                        clearTimeout(timer);
                        timer = null;
                    }
                }, { threshold: threshold });

                observer.observe(el);
            })(scrollElements[s]);
        }
    }
})();
