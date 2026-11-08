/**
 * Leads — Vanilla JS Popup Engine
 * Reads window._leadsConfig JSON array and manages popup display.
 *
 * Targeting that depends on the visitor — device, frequency, page views, new or returning,
 * signed up or closed — is decided here, in the browser, so it holds on pages served from a
 * full-page cache. The server has already left out popups whose page rules don't match.
 *
 * What the visitor has seen is kept in localStorage (`leads`), or in a cookie of the same name
 * where storage is blocked; the current session is a session cookie (`leads_session`), and where
 * this visit came from — UTM parameters and the referring host — another (`leads_source`).
 *
 * Whether the visitor is signed in, their user groups and their cart can't be kept in the browser
 * or read from a cached page. When a popup on the page has such a rule, the script asks the
 * server once (`visitorUrl`, an uncached action) for just that; otherwise it asks nothing.
 */
(function () {
    'use strict';

    // Loaded twice (an asset bundle and auto-injection, say)? Count the page view once.
    if (window.LeadsTargeting) return;

    var LEGACY_COOKIE_PREFIX = 'leads_seen_';
    var STORE_KEY = 'leads';
    var SESSION_COOKIE = 'leads_session';
    var SOURCE_COOKIE = 'leads_source';
    var BANNER_SETTLE = 1500;   // ms after load a cookie banner may take to appear
    var BANNER_POLL = 500;
    var BANNER_GRACE = 1000;    // ms between the banner going and a held popup showing
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
     *   inline (an inline form: no frequency or close rules — it can't be closed), source (where
     *   the visit came from: {u: {utm_*: value}, r: referring host}).
     */
    function allows(t, ctx) {
        t = t || {};
        var seen = ctx.seen || {};

        if (t.start && ctx.now < t.start) return false;
        if (t.end && ctx.now >= t.end) return false;
        if (t.sources && t.sources.length && !fromSource(t.sources, ctx.source)) return false;
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

    /**
     * Whether the audience and cart rules in `t` allow a popup, given what the server said about
     * the visitor (`v`: {user: {guest, groups}, cart: {empty, subtotal, qty, in}}). A rule whose
     * answer is missing — the request failed, or was turned away — doesn't allow it.
     */
    function visitorAllows(t, v) {
        t = t || {};
        v = v || {};
        var needs = t.needs || [];
        var i;

        if (needs.indexOf('user') !== -1) {
            var user = v.user;
            if (!user) return false;
            var groups = t.groups || [];
            if (t.audience === 'guests' && !user.guest) return false;
            if ((t.audience === 'users' || groups.length) && user.guest) return false;
            if (groups.length) {
                var inGroup = false;
                for (i = 0; i < groups.length; i++) if ((user.groups || []).indexOf(groups[i]) !== -1) inGroup = true;
                if (!inGroup) return false;
            }
        }

        if (needs.indexOf('cart') !== -1) {
            var cart = v.cart;
            if (!cart) return false;
            var subtotal = +cart.subtotal || 0;
            if (t.cart === 'empty' && !cart.empty) return false;
            if (t.cart === 'notEmpty' && cart.empty) return false;
            if (typeof t.cartMin === 'number' && subtotal < t.cartMin) return false;
            if (typeof t.cartBelow === 'number' && subtotal >= t.cartBelow) return false;
            if (t.cartIn && t.cartIn.length) {
                var holds = false;
                for (i = 0; i < t.cartIn.length; i++) if ((cart.in || []).indexOf(t.cartIn[i]) !== -1) holds = true;
                if (!holds) return false;
            }
        }

        return true;
    }

    /**
     * What to ask the visitor action, for every popup in `configs` that needs it: the URL to call,
     * or null when none does.
     */
    function visitorQuery(configs) {
        var url = null;
        var need = [];
        var ids = [];

        (configs || []).forEach(function (config) {
            var t = config.targeting || {};
            if (!config.visitorUrl || !t.needs || !t.needs.length) return;
            url = url || config.visitorUrl;
            t.needs.forEach(function (n) { if (need.indexOf(n) === -1) need.push(n); });
            (t.cartIn || []).forEach(function (id) { if (ids.indexOf(id) === -1) ids.push(id); });
        });

        if (!url) return null;
        need.sort();
        ids.sort(function (a, b) { return a - b; });

        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'need=' + need.join(',') + (ids.length ? '&in=' + ids.join(',') : '');
    }

    /**
     * Exit intent where there's no mouse to leave the window: a quick scroll back up, once the
     * visitor has read some way down — on a phone that's the reach for the address bar or back.
     * Returns a function fed each scroll position (px) and time (ms); true when it should fire.
     */
    var EXIT_MIN_DEPTH = 300;   // px read down the page before an upward flick counts
    var EXIT_FLICK = 120;       // px climbed…
    var EXIT_WINDOW = 250;      // …within this many ms

    function exitScroll() {
        var deepest = 0;
        var recent = [];

        return function (y, now) {
            if (y > deepest) deepest = y;
            recent.push({ y: y, t: now });
            while (recent.length && now - recent[0].t > EXIT_WINDOW) recent.shift();
            if (deepest < EXIT_MIN_DEPTH) return false;

            var high = y;
            for (var i = 0; i < recent.length; i++) if (recent[i].y > high) high = recent[i].y;

            return high - y >= EXIT_FLICK;
        };
    }

    /** `*` matches anything; everything else literally, ignoring case. */
    function glob(pattern, value) {
        var re = String(pattern).replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
        return new RegExp('^' + re + '$', 'i').test(String(value));
    }

    /**
     * Whether the visit came in through any of `sources`: `utm_name=value` lines against the UTM
     * parameters it arrived with, anything else against the referring host and its parents
     * (`google.com` covers `www.google.com`).
     */
    function fromSource(sources, src) {
        src = src || {};
        var utm = src.u || {};
        var host = String(src.r || '').toLowerCase();

        for (var i = 0; i < sources.length; i++) {
            var line = sources[i];
            var eq = line.indexOf('=');
            if (eq !== -1) {
                var key = line.slice(0, eq).toLowerCase();
                if (Object.prototype.hasOwnProperty.call(utm, key) && glob(line.slice(eq + 1), utm[key])) return true;
            } else if (host && (glob(line, host) || glob('*.' + line, host))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the page at `search` (location.search) was arrived at from: its UTM parameters, and
     * the referrer's host when that is another site. `own` is this site's host.
     */
    function arrival(search, referrer, own) {
        var utm = {};
        var any = false;
        String(search || '').replace(/^\?/, '').split('&').forEach(function (pair) {
            var eq = pair.indexOf('=');
            var key = eq === -1 ? pair : pair.slice(0, eq);
            try { key = decodeURIComponent(key.replace(/\+/g, ' ')).toLowerCase(); } catch (e) { return; }
            if (!/^utm_[a-z0-9_]{1,40}$/.test(key)) return;
            var value = eq === -1 ? '' : pair.slice(eq + 1);
            try { value = decodeURIComponent(value.replace(/\+/g, ' ')); } catch (e) { return; }
            utm[key] = value.slice(0, 200);
            any = true;
        });

        var host = '';
        var m = /^https?:\/\/([^\/?#:]+)/i.exec(String(referrer || ''));
        if (m && m[1].toLowerCase() !== String(own || '').toLowerCase()) host = m[1].toLowerCase();

        return { u: utm, r: host, tagged: any };
    }

    window.LeadsTargeting = {
        deviceType: deviceType, allows: allows, visitorAllows: visitorAllows, visitorQuery: visitorQuery,
        exitScroll: exitScroll, fromSource: fromSource, arrival: arrival,
    };

    // ---- Visitor state -----------------------------------------------------------------------

    var state = readState();
    var session = readSession();
    var source = readSource();
    var loadedAt = Date.now();
    var visitor = null;        // what the server said about the visitor, once it has
    var visitorAsking = null;  // the request in flight
    var visitorAsked = 0;      // how many requests have been made, to keep only the latest answer
    var refused = [];          // overlays whose trigger fired while the audience or cart rules said no

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
        var fresh = session === null;
        if (fresh) {
            // A new session: another visit.
            state.n++;
            session = [];
            writeSession();
        }
        writeState();
        noteSource(fresh);
    }

    /**
     * The visit's source is set by the page it started on, and replaced by a later page that
     * carries UTM parameters — a payment provider sending the visitor back doesn't count.
     */
    function noteSource(fresh) {
        var here = arrival(window.location.search, document.referrer, window.location.hostname);
        if (fresh || here.tagged) {
            source = { u: here.u, r: here.r };
            setCookie(SOURCE_COOKIE, JSON.stringify(source), 0);
        }
    }

    function readSource() {
        try {
            var raw = getCookie(SOURCE_COOKIE);
            var parsed = raw ? JSON.parse(raw) : null;
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function currentDevice() {
        var coarse = false;
        try {
            coarse = window.matchMedia('(pointer: coarse)').matches;
        } catch (e) { /* old browser: treat as fine */ }
        var short = window.screen ? Math.min(window.screen.width || 0, window.screen.height || 0) : 0;

        return deviceType(window.navigator, short, coarse);
    }

    function needsVisitor(config) {
        return !!(config.targeting && config.targeting.needs && config.targeting.needs.length);
    }

    /** Ask the server about the visitor — once, or again with `fresh`. Resolves with the answer. */
    function askVisitor(fresh) {
        if (visitorAsking && !fresh) return visitorAsking;

        var url = visitorQuery(window._leadsConfig);
        if (!url || typeof window.fetch !== 'function') {
            visitor = {};
            return (visitorAsking = { then: function (fn) { fn(visitor); } });
        }

        // Only the latest request's answer counts: after leads:cart-changed, the page-load request
        // may come back after the fresh one, with the cart as it was.
        var mine = ++visitorAsked;
        visitorAsking = window.fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : {}; })
            .catch(function () { return {}; })
            .then(function (data) {
                if (mine === visitorAsked) visitor = data && typeof data === 'object' ? data : {};
                return visitor;
            });

        return visitorAsking;
    }

    /** The visitor's cart changed (an add to cart by Ajax): ask again, and retry refused overlays. */
    function cartChanged() {
        var wanted = (window._leadsConfig || []).some(function (config) {
            return needsVisitor(config) && config.targeting.needs.indexOf('cart') !== -1;
        });
        if (!wanted) return;

        askVisitor(true).then(function () {
            var retry = refused;
            refused = [];
            retry.forEach(function (r) { showPopup(r.popup, r.config); });
        });
    }

    /** Every rule but the audience and cart ones, which need the server's answer. */
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
            source: source,
        });
    }

    // ---- Popups ------------------------------------------------------------------------------

    function init() {
        countVisit();

        var config = window._leadsConfig;
        if (!config || !config.length) return;

        // One request for the whole page, started now so it's back before most triggers fire.
        if (config.some(needsVisitor)) askVisitor();
        window.addEventListener('leads:cart-changed', cartChanged);

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

        var drop = function () {
            if (wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
        };
        if (!allowed(config)) return drop();

        var reveal = function () {
            var popup = wrapper.firstElementChild;
            if (popup) attachForm(popup, config);
            wrapper.hidden = false;
            trackEvent(config.id, 'impression');
        };
        if (!needsVisitor(config)) return reveal();

        askVisitor().then(function () {
            if (visitorAllows(config.targeting, visitor)) reveal(); else drop();
        });
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
                var exitNow = function () {
                    if (exitTriggered) return;
                    exitTriggered = true;
                    showPopup(popup, config);
                };
                document.addEventListener('mouseleave', function (e) {
                    if (e.clientY <= 0) exitNow();
                });
                // Phones and tablets have no mouse to leave the window.
                if (currentDevice() !== 'desktop') {
                    var flicked = exitScroll();
                    window.addEventListener('scroll', function () {
                        if (!exitTriggered && flicked(window.scrollY, Date.now())) exitNow();
                    }, { passive: true });
                    // A visitor who never scrolls back up — a short page — gets it after the
                    // popup's fallback, when it has one.
                    var fallback = parseInt(config.triggerValue, 10);
                    if (fallback > 0) setTimeout(exitNow, fallback * 1000);
                }
                break;

            case 'idle':
                // No mouse, keys, scrolling or touch for N seconds.
                var idleMs = (parseInt(config.triggerValue, 10) || 30) * 1000;
                var idleTimer = null;
                var idleDone = false;
                var idleEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'];
                var idleReset = function () {
                    if (idleDone) return;
                    if (idleTimer) clearTimeout(idleTimer);
                    idleTimer = setTimeout(function () {
                        idleDone = true;
                        idleEvents.forEach(function (type) { window.removeEventListener(type, idleReset, true); });
                        showPopup(popup, config);
                    }, idleMs);
                };
                idleEvents.forEach(function (type) { window.addEventListener(type, idleReset, { capture: true, passive: true }); });
                idleReset();
                break;

            case 'view':
                // When an element matching the selector scrolls a fifth of the way up the screen.
                var watched = [];
                try {
                    watched = config.triggerValue ? document.querySelectorAll(config.triggerValue) : [];
                } catch (e) { /* not a selector */ }
                if (!watched.length || typeof window.IntersectionObserver !== 'function') break;
                var observer = new window.IntersectionObserver(function (entries) {
                    for (var i = 0; i < entries.length; i++) {
                        if (entries[i].isIntersecting) {
                            observer.disconnect();
                            showPopup(popup, config);
                            return;
                        }
                    }
                }, { rootMargin: '0px 0px -20% 0px' });
                for (var w = 0; w < watched.length; w++) observer.observe(watched[w]);
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

        // Signed in, user groups, cart: wait for the server's answer. A no is kept, so a cart
        // that changes later on this page (see cartChanged) can still bring it up.
        if (needsVisitor(config)) {
            if (visitor === null) {
                askVisitor().then(function () { showPopup(popup, config); });
                return;
            }
            if (!visitorAllows(config.targeting, visitor)) {
                if (!refused.some(function (r) { return r.popup === popup; })) refused.push({ popup: popup, config: config });
                return;
            }
        }

        // Not on top of the cookie banner: wait until it's gone, then show.
        if (popup._leadsHeld) return;
        var wait = bannerWait(config.holdFor, popup);
        if (wait) {
            popup._leadsHeld = true;
            setTimeout(function () {
                popup._leadsHeld = false;
                showPopup(popup, config);
            }, wait);
            return;
        }

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

    /**
     * How long to put a popup off for the site's cookie banner, in ms; 0 to show it now. While a
     * banner is on screen, check again shortly. In the first moments after load, a banner that's
     * on the page but not yet showing may be about to — consent scripts decide asynchronously.
     */
    function bannerWait(selectors, popup) {
        if (!selectors || !selectors.length) return 0;

        var present = false;
        for (var i = 0; i < selectors.length; i++) {
            var found;
            try {
                found = document.querySelectorAll(selectors[i]);
            } catch (e) {
                continue;
            }
            for (var j = 0; j < found.length; j++) {
                present = true;
                if (onScreen(found[j])) {
                    popup._leadsBanner = true;
                    popup._leadsBannerChecked = true;
                    return BANNER_POLL;
                }
            }
        }

        if (popup._leadsBanner) {
            // It's just gone: give the visitor a moment.
            popup._leadsBanner = false;
            return BANNER_GRACE;
        }

        // Only before the first time this popup would have shown.
        var first = !popup._leadsBannerChecked;
        popup._leadsBannerChecked = true;
        var age = Date.now() - loadedAt;
        return first && present && age < BANNER_SETTLE ? BANNER_SETTLE - age : 0;
    }

    function onScreen(el) {
        if (el.hidden || !el.getClientRects || !el.getClientRects().length) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(el) : null;

        return !style || (style.visibility !== 'hidden' && style.display !== 'none' && style.opacity !== '0');
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

        var stale = form.querySelector('.leads-error');
        if (stale) stale.textContent = '';

        // An unticked consent box isn't in the form data at all, which the server reads as "no".
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
                showError(form, result.error);
            }
        })
        .catch(function () {
            if (btn) {
                btn.classList.remove('leads-loading');
                btn.disabled = false;
            }
        });
    }

    /**
     * Says why a submission was turned down — a required consent box left unticked, an address
     * the server didn't accept. Text only: the message is set as textContent, never as HTML.
     */
    function showError(form, message) {
        if (!message) return;

        var error = form.querySelector('.leads-error');
        if (!error) {
            error = document.createElement('p');
            error.className = 'leads-error';
            error.setAttribute('role', 'alert');
            form.appendChild(error);
        }
        error.textContent = String(message);
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
