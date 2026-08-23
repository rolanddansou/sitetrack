(function() {
    const currentScript = document.currentScript;
    if (!currentScript) return;

    const siteId = currentScript.getAttribute('data-site-id');
    const endpoint = currentScript.getAttribute('data-endpoint') || '/api/event';
    if (!siteId) return;

    function track() {
        const payload = {
            site_id: siteId,
            path: window.location.pathname,
            referrer: document.referrer || null
        };

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(() => {});
    }

    if (document.readyState === 'complete') {
        track();
    } else {
        window.addEventListener('load', track);
    }

    let lastPath = window.location.pathname;
    const observer = new MutationObserver(() => {
        if (window.location.pathname !== lastPath) {
            lastPath = window.location.pathname;
            track();
        }
    });
    observer.observe(document.querySelector('body'), { childList: true, subtree: true });
})();
