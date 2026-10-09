/**
 * Leads — Vanilla JS Popup Engine
 * Reads window._leadsConfig JSON array and manages popup display.
 *
 * Targeting that depends on the visitor — device, frequency, page views, new or returning,
 * signed up or closed — is decided here, in the browser, so it holds on pages served from a
 * full-page cache. The server has already left out popups whose page rules don't match.
 *
 * What the visitor has seen is kept in localStorage (`leads`), or in a cookie of the same name
 * where storage is blocked; the current session is a session cookie (`leads_session`).
 */
(function () {
    'use strict';

    // Loaded twice (an asset bundle and auto-injection, say)? Count the page view once.
    if (window.LeadsTargeting) return;

    var LEGACY_COOKIE_PREFIX = 'leads_seen_';
    var STORE_KEY = 'leads';
    var SESSION_COOKIE = 'leads_session';
    var DAY = 24 * 60 * 60 * 1000;
    var SUBMIT_URL = '/leads/submit';
    var TRACK_URL = '/leads/track';

    // ---- Targeting (pure; exposed as window.LeadsTargeting for tests) ------------------------

    /**
     * desktop, tablet or mobile. User-Agent Client Hints first, then the UA string, then — for a
     * browser that hides both — a coarse pointer and the screen's short side.
     */
    function deviceType(nav, screenSize, coarsePointer) {
        var ua = (nav && nav.userAgent) || '';

        if (nav && nav.userAgentData && nav.userAgentData.mobile === true) return 'mobile';
        if (/iPad|Tablet|PlayBook|Silk|Kindle|Nexus (7|9|10)/i.test(ua)) return 'tablet';
        if (/Android/i.test(ua) && !/Mobile/i.test(ua)) return 'tablet';
        // iPadOS asks for the desktop site and says it's a Mac; a Mac has no touch points.
        if (/Macintosh/i.test(ua) && nav && nav.maxTouchPoints > 1) return 'tablet';
        if (/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini|IEMobile/i.test(ua)) return 'mobile';
        if (coarsePointer && screenSize) return screenSize < 600 ? 'mobile' : 'tablet';

        return 'desktop';
    }

    /**
     * Whether a popup with targeting `t` may show now. `ctx`:
     *   device, views (this visitor's page views, this one included), visits (sessions, this one
     *   included), now (ms), seen ({s: last shown, c: closed, x: converted}, ms), shownThisSession,
     *   inline (an inline form: no frequency or close rules — it can't be closed).
     */
    function allows(t, ctx) {
        t = t || {};
        var seen = ctx.seen || {};

        if (t.devices && t.devices.length && t.devices.indexOf(ctx.device) === -1) return false;
        if (t.visitor === 'new' && ctx.visits > 1) return false;
        if (t.visitor === 'returning' && ctx.visits < 2) return false;
        if (t.minPageViews > 0 && ctx.views < t.minPageViews) return false;
        if (seen.x && t.hideAfterConversion !== false) return false;

        if (ctx.inline) return true;

        var dismissDays = typeof t.dismissDays === 'number' ? t.dismissDays : 1;
        if (seen.c && dismissDays > 0 && ctx.now - seen.c < dismissDays * DAY) return false;

        switch (t.frequency) {
            case 'session':
                return !ctx.shownThisSession;
            case 'once':
                return !seen.s;
            case 'days':
                return !seen.s || ctx.now - seen.s >= Math.max(1, t.frequencyDays || 1) * DAY;
            default:
                return true;
        }
    }

    window.LeadsTargeting = { deviceType: deviceType, allows: allows };

    // ---- Visitor state -----------------------------------------------------------------------

    var state = readState();
    var session = readSession();

    function readState() {
        var raw = null;
        try {
            raw = window.localStorage.getItem(STORE_KEY);
        } catch (e) {
            raw = getCookie(STORE_KEY);
        }
        try {
            var parsed = raw ? JSON.parse(raw) : null;
            if (parsed && typeof parsed === 'object') {
                return { v: +parsed.v || 0, n: +parsed.n || 0, p: parsed.p && typeof parsed.p === 'object' ? parsed.p : {} };
            }
        } catch (e) { /* unreadable: start again */ }

        return { v: 0, n: 0, p: {} };
    }

    function writeState() {
        var raw = JSON.stringify(state);
        try {
            window.localStorage.setItem(STORE_KEY, raw);
        } catch (e) {
            setCookie(STORE_KEY, raw, 365);
        }
    }

    function readSession() {
        var raw = getCookie(SESSION_COOKIE);
        return raw === null ? null : raw.split('.').filter(Boolean);
    }

    function writeSession() {
        // No expiry: the browser drops it when the session ends.
        setCookie(SESSION_COOKIE, session.join('.') || '-', 0);
    }

    function seenFor(id) {
        return state.p[id] || (state.p[id] = {});
    }

    function mark(id, key) {
        seenFor(id)[key] = Date.now();
        writeState();
    }

    function countVisit() {
        state.v++;
        if (session === null) {
            // A new session: another visit.
            state.n++;
            session = [];
            writeSession();
        }
        writeState();
    }

    function currentDevice() {
        var coarse = false;
        try {
            coarse = window.matchMedia('(pointer: coarse)').matches;
        } catch (e) { /* old browser: treat as fine */ }
        var short = window.screen ? Math.min(window.screen.width || 0, window.screen.height || 0) : 0;

        return deviceType(window.navigator, short, coarse);
    }

    function allowed(config) {
        if (config.type !== 'inline' && getCookie(LEGACY_COOKIE_PREFIX + config.id)) return false;

        return allows(config.targeting, {
            device: currentDevice(),
            views: state.v,
            visits: state.n,
            now: Date.now(),
            seen: state.p[config.id],
            shownThisSession: !!session && session.indexOf(String(config.id)) !== -1,
            inline: config.type === 'inline',
        });
    }

    // ---- Popups ------------------------------------------------------------------------------

    function init() {
        countVisit();

        var config = window._leadsConfig;
        if (!config || !config.length) return;

        config.forEach(function (popup) {
            if (popup.type === 'inline') {
                setupInline(popup);
            } else {
                setupPopup(popup);
            }
        });
    }

    function setupInline(config) {
        var wrapper = document.querySelector('[data-leads-inline="' + config.id + '"]');
        if (!wrapper) return;

        if (!allowed(config)) {
            wrapper.parentNode.removeChild(wrapper);
            return;
        }

        var popup = wrapper.firstElementChild;
        if (popup) attachForm(popup, config);
        wrapper.hidden = false;
        trackEvent(config.id, 'impression');
    }

    function setupPopup(config) {
        if (!allowed(config)) return;

        // Inject custom CSS if provided
        if (config.customCss) {
            var style = document.createElement('style');
            style.textContent = config.customCss;
            document.head.appendChild(style);
        }

        // Create container
        var container = document.createElement('div');
        container.innerHTML = config.html;
        var popup = container.firstElementChild;

        if (!popup) return;
        // Out of its temporary container, so `parentNode` says whether it's on the page.
        container.removeChild(popup);

        // Set position data attribute
        if (config.position) {
            popup.setAttribute('data-leads-position', config.position);
        }

        attachForm(popup, config);

        // Attach close handler
        var closeButtons = popup.querySelectorAll('[data-leads-close]');
        closeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                closePopup(popup, config);
            });
        });

        // Set up trigger
        switch (config.trigger) {
            case 'time':
                var delay = (parseInt(config.triggerValue, 10) || 3) * 1000;
                setTimeout(function () { showPopup(popup, config); }, delay);
                break;

            case 'scroll':
                var scrollPercent = parseInt(config.triggerValue, 10) || 50;
                var scrollTriggered = false;
                window.addEventListener('scroll', function () {
                    if (scrollTriggered) return;
                    var scrolled = (window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100;
                    if (scrolled >= scrollPercent) {
                        scrollTriggered = true;
                        showPopup(popup, config);
                    }
                });
                break;

            case 'exit':
                var exitTriggered = false;
                document.addEventListener('mouseleave', function (e) {
                    if (exitTriggered) return;
                    if (e.clientY <= 0) {
                        exitTriggered = true;
                        showPopup(popup, config);
                    }
                });
                break;

            case 'click':
                var selector = config.triggerValue;
                if (selector) {
                    var triggers = document.querySelectorAll(selector);
                    triggers.forEach(function (trigger) {
                        trigger.addEventListener('click', function (e) {
                            e.preventDefault();
                            showPopup(popup, config);
                        });
                    });
                }
                break;
        }
    }

    function attachForm(popup, config) {
        var form = popup.querySelector('[data-leads-form]');
        if (!form) return;

        // Add honeypot field
        var hp = document.createElement('input');
        hp.type = 'text';
        hp.name = 'leads_hp';
        hp.className = 'leads-hp';
        hp.tabIndex = -1;
        hp.autocomplete = 'off';
        form.appendChild(hp);

        // Add page URL
        var pageInput = document.createElement('input');
        pageInput.type = 'hidden';
        pageInput.name = 'pageUrl';
        pageInput.value = window.location.href;
        form.appendChild(pageInput);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            handleSubmit(form, popup, config);
        });
    }

    function showPopup(popup, config) {
        // Already open, or the rules changed their mind since the page loaded (closed in
        // another tab, a click trigger pressed twice).
        if (popup.parentNode || !allowed(config)) return;

        document.body.appendChild(popup);

        mark(config.id, 's');
        if (session.indexOf(String(config.id)) === -1) {
            session.push(String(config.id));
            writeSession();
        }

        // Add overlay for modals
        var overlay = null;
        if (config.type === 'modal') {
            overlay = document.createElement('div');
            overlay.className = 'leads-overlay';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function () {
                closePopup(popup, config);
            });
            // Trigger animation
            requestAnimationFrame(function () {
                overlay.classList.add('leads-visible');
            });
        }

        popup._leadsOverlay = overlay;

        // Trigger animation
        requestAnimationFrame(function () {
            popup.classList.add('leads-visible');
        });

        // Track impression
        trackEvent(config.id, 'impression');

        // Close on Escape
        popup._leadsEscHandler = function (e) {
            if (e.key === 'Escape') {
                closePopup(popup, config);
            }
        };
        document.addEventListener('keydown', popup._leadsEscHandler);
    }

    function closePopup(popup, config) {
        mark(config.id, 'c');
        hidePopup(popup);
        trackEvent(config.id, 'close');
    }

    function hidePopup(popup) {
        popup.classList.remove('leads-visible');

        if (popup._leadsOverlay) {
            var overlay = popup._leadsOverlay;
            popup._leadsOverlay = null;
            overlay.classList.remove('leads-visible');
            setTimeout(function () {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }, 300);
        }

        if (popup._leadsEscHandler) {
            document.removeEventListener('keydown', popup._leadsEscHandler);
        }

        setTimeout(function () {
            if (popup.parentNode) {
                popup.parentNode.removeChild(popup);
            }
        }, 400);
    }

    function handleSubmit(form, popup, config) {
        var btn = form.querySelector('.leads-btn');
        if (btn) {
            btn.classList.add('leads-loading');
            btn.disabled = true;
        }

        var formData = new FormData(form);
        var data = {};
        formData.forEach(function (value, key) {
            data[key] = value;
        });

        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(data),
        })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                // Show success message
                form.style.display = 'none';
                var success = popup.querySelector('.leads-success');
                if (success) {
                    success.style.display = 'block';
                }

                // Converted: never again (or, with that rule off, treated as a close).
                seenFor(config.id).c = Date.now();
                mark(config.id, 'x');

                // Auto-close after 3 seconds
                if (config.type !== 'inline') {
                    setTimeout(function () {
                        hidePopup(popup);
                    }, 3000);
                }
            } else {
                if (btn) {
                    btn.classList.remove('leads-loading');
                    btn.disabled = false;
                }
            }
        })
        .catch(function () {
            if (btn) {
                btn.classList.remove('leads-loading');
                btn.disabled = false;
            }
        });
    }

    function trackEvent(popupId, type) {
        var data = { popupId: popupId, type: type };

        if (navigator.sendBeacon) {
            navigator.sendBeacon(TRACK_URL, JSON.stringify(data));
        } else {
            fetch(TRACK_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
                keepalive: true,
            });
        }
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^|; ?)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
