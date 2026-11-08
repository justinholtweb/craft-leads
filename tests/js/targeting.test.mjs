/**
 * The page script's targeting — device detection, the rules, and the visitor state it keeps
 * between page loads. Runs the shipped leads.js in a VM against a minimal fake browser.
 *
 *     node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../src/web/assets/frontend/dist/js/leads.js', import.meta.url), 'utf8');
const DAY = 24 * 60 * 60 * 1000;

/** One fake "browser" whose storage and cookies outlive page loads. */
function browser({ ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', storage = true } = {}) {
    const store = new Map();
    const cookies = new Map();

    return {
        store,
        cookies,
        endSession() {
            for (const [name, c] of cookies) if (!c.persistent) cookies.delete(name);
        },
        /**
         * Load a page carrying `configs`; returns the popups it put on screen and the tracked events.
         * `page.search`/`page.referrer` say how it was reached; `page.banner` is a cookie banner on
         * it (`{ visible }`, which the test can change).
         */
        load(configs = [], page = {}) {
            const shown = [];
            const events = [];
            const timers = [];
            const listeners = [];
            const element = (tag) => {
                const el = {
                    tag, children: [], parentNode: null, classList: { add() {}, remove() {} }, hidden: false,
                    attrs: {}, set innerHTML(html) { this.children = [Object.assign(element('div'), { html, parentNode: this })]; },
                    get firstElementChild() { return this.children[0] || null; },
                    setAttribute(k, v) { this.attrs[k] = v; }, querySelector() { return null; }, querySelectorAll() { return []; },
                    addEventListener() {}, appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
                    removeChild(child) { child.parentNode = null; this.children = this.children.filter((c) => c !== child); },
                };
                return el;
            };
            const body = element('body');
            body.appendChild = (child) => { child.parentNode = body; if (child.html) shown.push(child.html); return child; };
            const document = {
                readyState: 'complete', body, head: element('head'),
                get cookie() { return [...cookies].map(([n, c]) => `${n}=${c.value}`).join('; '); },
                set cookie(line) {
                    const [pair, ...attrs] = line.split('; ');
                    const [name, value] = pair.split('=');
                    cookies.set(name, { value, persistent: attrs.some((a) => a.startsWith('expires=')) });
                },
                referrer: page.referrer || '',
                createElement: element, addEventListener() {}, removeEventListener() {}, querySelector() { return null; },
                querySelectorAll: (selector) => (page.banner && selector === '#banner'
                    ? [{ hidden: false, getClientRects: () => (page.banner.visible ? [{}] : []) }]
                    : []),
            };
            const window = {
                _leadsConfig: configs,
                navigator: { userAgent: ua, maxTouchPoints: 0, sendBeacon: (url, data) => events.push(JSON.parse(data)) },
                screen: { width: 1440, height: 900 },
                matchMedia: () => ({ matches: false }),
                localStorage: storage
                    ? { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)) }
                    : { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } },
                scrollY: 0,
                addEventListener: (type, fn) => listeners.push({ type, fn }),
                removeEventListener() {},
                getComputedStyle: () => ({ visibility: 'visible', display: 'block', opacity: '1' }),
                location: { href: 'https://example.com/' + (page.search || ''), search: page.search || '', hostname: 'example.com' },
            };
            // `page.visitor`: what the visitor action answers (a function is asked each time), or
            // a status code for a refusal. Without it the page has no fetch at all.
            const requests = [];
            if (page.visitor !== undefined) {
                window.fetch = (url) => {
                    requests.push(url);
                    // A function may return a promise, to answer later than a request after it.
                    const answer = typeof page.visitor === 'function' ? page.visitor() : page.visitor;
                    return Promise.resolve(answer).then((a) => typeof a === 'number'
                        ? { ok: false, status: a, json: async () => ({}) }
                        : { ok: true, status: 200, json: async () => a });
                };
            }
            const context = {
                window, document, navigator: window.navigator, JSON, Date, Math, String, RegExp,
                setTimeout: (fn) => timers.push(fn) , clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1] = () => {}; },
                requestAnimationFrame: () => {},
                encodeURIComponent, decodeURIComponent,
            };
            vm.runInNewContext(source, context);
            timers.splice(0).forEach((fn) => fn());

            /** Scroll the page to `y` and fire the window's scroll listeners. */
            const scrollTo = (y) => {
                window.scrollY = y;
                listeners.filter((l) => l.type === 'scroll').forEach((l) => l.fn());
            };

            /** Run the timers set so far (and only those). */
            const tick = () => timers.splice(0).forEach((fn) => fn());

            /** Fire a window event, as the site's own script would. */
            const dispatch = (type) => listeners.filter((l) => l.type === type).forEach((l) => l.fn());

            return { shown, events, scrollTo, tick, dispatch, requests, targeting: window.LeadsTargeting };
        },
    };
}

const popup = (id, targeting = {}) => ({ id, type: 'modal', trigger: 'time', triggerValue: '1', html: `popup-${id}`, targeting });
const { deviceType, allows, visitorAllows, visitorQuery, exitScroll, fromSource, arrival } = browser().load().targeting;

/** Let the fake fetch's promises settle. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

test('device type', () => {
    const cases = [
        [{ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129' }, 'desktop'],
        [{ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148' }, 'mobile'],
        [{ userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/129 Mobile Safari/537.36' }, 'mobile'],
        [{ userAgent: 'Mozilla/5.0 (Linux; Android 14; SM-X710) Chrome/129 Safari/537.36' }, 'tablet'],
        [{ userAgent: 'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X)' }, 'tablet'],
        [{ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605', maxTouchPoints: 5 }, 'tablet'],
        [{ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605', maxTouchPoints: 0 }, 'desktop'],
        [{ userAgent: 'Mozilla/5.0 (X11; Linux x86_64)', userAgentData: { mobile: true } }, 'mobile'],
    ];
    for (const [nav, expected] of cases) assert.equal(deviceType(nav, 0, false), expected, nav.userAgent);
    assert.equal(deviceType({ userAgent: 'Unknown' }, 390, true), 'mobile');
    assert.equal(deviceType({ userAgent: 'Unknown' }, 800, true), 'tablet');
});

test('the rules', () => {
    const now = 100 * DAY;
    const ctx = (over = {}) => ({ device: 'desktop', views: 1, visits: 1, now, seen: {}, shownThisSession: false, inline: false, ...over });

    assert.equal(allows({}, ctx()), true, 'no rules');
    assert.equal(allows({ devices: ['mobile', 'tablet'] }, ctx()), false, 'wrong device');
    assert.equal(allows({ devices: ['desktop'] }, ctx()), true, 'right device');
    assert.equal(allows({ visitor: 'new' }, ctx({ visits: 2 })), false, 'new only, returning visitor');
    assert.equal(allows({ visitor: 'returning' }, ctx()), false, 'returning only, first visit');
    assert.equal(allows({ visitor: 'returning' }, ctx({ visits: 3 })), true);
    assert.equal(allows({ minPageViews: 3 }, ctx({ views: 2 })), false, 'before the third page view');
    assert.equal(allows({ minPageViews: 3 }, ctx({ views: 3 })), true, 'on it');
    assert.equal(allows({}, ctx({ seen: { x: now - 400 * DAY } })), false, 'converted, ever');
    assert.equal(allows({ hideAfterConversion: false }, ctx({ seen: { x: now - DAY } })), true);
    assert.equal(allows({ dismissDays: 7 }, ctx({ seen: { c: now - 6 * DAY } })), false, 'closed 6 days ago, 7-day pause');
    assert.equal(allows({ dismissDays: 7 }, ctx({ seen: { c: now - 8 * DAY } })), true);
    assert.equal(allows({ dismissDays: 0 }, ctx({ seen: { c: now - 1000 } })), true, 'no pause');
    assert.equal(allows({}, ctx({ seen: { c: now - 1000 } })), false, 'default pause is a day');
    assert.equal(allows({ frequency: 'session' }, ctx({ shownThisSession: true })), false);
    assert.equal(allows({ frequency: 'once' }, ctx({ seen: { s: now - 900 * DAY } })), false);
    assert.equal(allows({ frequency: 'days', frequencyDays: 3 }, ctx({ seen: { s: now - 2 * DAY } })), false);
    assert.equal(allows({ frequency: 'days', frequencyDays: 3 }, ctx({ seen: { s: now - 3 * DAY } })), true);
    assert.equal(allows({ frequency: 'once', dismissDays: 7 }, ctx({ inline: true, seen: { s: now, c: now } })), true, 'inline forms ignore frequency and close');
    assert.equal(allows({ devices: ['mobile'] }, ctx({ inline: true })), false, '…but not device');
});

test('page views and visits are counted across page loads, once per load', () => {
    const b = browser();
    b.load();
    b.load();
    let state = JSON.parse(b.store.get('leads'));
    assert.equal(state.v, 2);
    assert.equal(state.n, 1, 'same session');
    b.endSession();
    b.load();
    state = JSON.parse(b.store.get('leads'));
    assert.deepEqual([state.v, state.n], [3, 2]);
});

test('“once per session” shows once, then again next session', () => {
    const b = browser();
    const p = popup(7, { frequency: 'session', dismissDays: 0 });
    assert.deepEqual(b.load([p]).shown, ['popup-7']);
    assert.deepEqual(b.load([p]).shown, []);
    b.endSession();
    assert.deepEqual(b.load([p]).shown, ['popup-7']);
});

test('“once per visitor” never comes back, even in a new session', () => {
    const b = browser();
    const p = popup(8, { frequency: 'once', dismissDays: 0 });
    assert.deepEqual(b.load([p]).shown, ['popup-8']);
    b.endSession();
    assert.deepEqual(b.load([p]).shown, []);
});

test('the page-view minimum and returning-visitor rule hold on real page loads', () => {
    const b = browser();
    const p = popup(9, { minPageViews: 3 });
    assert.deepEqual(b.load([p]).shown, []);
    b.load([]); // a page without the popup still counts
    assert.deepEqual(b.load([p]).shown, ['popup-9']);

    const r = popup(10, { visitor: 'returning' });
    const c = browser();
    assert.deepEqual(c.load([r]).shown, []);
    c.endSession();
    assert.deepEqual(c.load([r]).shown, ['popup-10']);
});

test('a device rule keeps a desktop popup off a phone', () => {
    const phone = browser({ ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148' });
    assert.deepEqual(phone.load([popup(11, { devices: ['desktop'] }), popup(12, { devices: ['mobile'] })]).shown, ['popup-12']);
});

test('with localStorage blocked, state lives in a cookie', () => {
    const b = browser({ storage: false });
    const p = popup(13, { frequency: 'once' });
    assert.deepEqual(b.load([p]).shown, ['popup-13']);
    assert.ok(b.cookies.get('leads')?.persistent, 'persistent state cookie');
    b.endSession();
    assert.deepEqual(b.load([p]).shown, []);
});

test('an impression is tracked for each showing', () => {
    const { events } = browser().load([popup(14)]);
    assert.deepEqual(events, [{ popupId: 14, type: 'impression' }]);
});

test('exit intent without a mouse: a quick scroll back up, after reading down', () => {
    let flicked = exitScroll();
    assert.equal(flicked(0, 0), false);
    assert.equal(flicked(200, 1000), false);
    assert.equal(flicked(60, 1100), false, 'not read far enough yet');

    flicked = exitScroll();
    assert.equal(flicked(800, 0), false);
    assert.equal(flicked(780, 2000), false, 'drifting up');
    assert.equal(flicked(700, 2400), false, '80px slowly');
    assert.equal(flicked(640, 2500), false, 'still under 120px in the last 250ms');
    assert.equal(flicked(560, 2600), true, '140px in 200ms');

    flicked = exitScroll();
    flicked(900, 0);
    assert.equal(flicked(750, 1000), false, 'a jump between distant samples is not a flick');
});

const exitPopup = (id) => ({ id, type: 'modal', trigger: 'exit', html: `popup-${id}`, targeting: {} });

test('exit intent fires on a phone when the visitor flicks back up', () => {
    const page = browser({ ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148' }).load([exitPopup(15)]);
    page.scrollTo(400);
    page.scrollTo(900);
    assert.deepEqual(page.shown, []);
    page.scrollTo(700);
    assert.deepEqual(page.shown, ['popup-15']);
    page.scrollTo(900);
    page.scrollTo(500);
    assert.deepEqual(page.shown, ['popup-15'], 'once');
});

test('on a desktop, scrolling up is not exit intent', () => {
    const page = browser().load([exitPopup(16)]);
    page.scrollTo(900);
    page.scrollTo(500);
    assert.deepEqual(page.shown, []);
});

test('start and end dates', () => {
    const ctx = (now) => ({ device: 'desktop', views: 1, visits: 1, now, seen: {}, shownThisSession: false, inline: false });
    const t = { start: 10 * DAY, end: 20 * DAY };
    assert.equal(allows(t, ctx(9 * DAY)), false, 'before the start');
    assert.equal(allows(t, ctx(10 * DAY)), true, 'from the start');
    assert.equal(allows(t, ctx(20 * DAY - 1)), true);
    assert.equal(allows(t, ctx(20 * DAY)), false, 'at the end');
    assert.equal(allows({ end: 20 * DAY }, { ...ctx(25 * DAY), inline: true }), false, 'inline forms end too');
    assert.equal(allows({ start: null, end: null }, ctx(0)), true);
});

test('where a visit came from', () => {
    const plain = (v) => JSON.parse(JSON.stringify(v));
    assert.deepEqual(plain(arrival('?utm_source=News%20letter&UTM_Campaign=spring+sale&id=4&utm_bad-key=x', 'https://www.google.com/search?q=x', 'example.com')),
        { u: { utm_source: 'News letter', utm_campaign: 'spring sale' }, r: 'www.google.com', tagged: true });
    assert.deepEqual(plain(arrival('', 'https://example.com/blog', 'example.com')), { u: {}, r: '', tagged: false }, 'own site is no referrer');
    assert.deepEqual(plain(arrival('?utm_source=%E0%A4%A', '', 'example.com')), { u: {}, r: '', tagged: false }, 'broken encoding is skipped');

    const src = { u: { utm_source: 'newsletter', utm_campaign: 'spring-sale-2026' }, r: 'l.facebook.com' };
    assert.equal(fromSource(['utm_source=newsletter'], src), true);
    assert.equal(fromSource(['utm_source=NEWSLETTER'], src), true, 'case-insensitive');
    assert.equal(fromSource(['utm_campaign=spring-*'], src), true, 'wildcard');
    assert.equal(fromSource(['utm_campaign=spring'], src), false, 'whole value');
    assert.equal(fromSource(['utm_medium=email'], src), false, 'parameter absent');
    assert.equal(fromSource(['facebook.com'], src), true, 'subdomain of a host');
    assert.equal(fromSource(['book.com'], src), false, 'not just the end of the name');
    assert.equal(fromSource(['*.facebook.*'], src), true);
    assert.equal(fromSource(['google.com', 'utm_source=newsletter'], src), true, 'any line');
    assert.equal(fromSource(['google.com'], {}), false, 'direct visit');
    assert.equal(fromSource(['a.b'], { r: 'aXb' }), false, 'dots are literal');
});

test('a traffic-source rule follows the visitor past the landing page', () => {
    const b = browser();
    const p = popup(17, { sources: ['utm_campaign=spring'] });
    assert.deepEqual(b.load([], { search: '?utm_campaign=spring', referrer: 'https://news.example.org/' }).shown, []);
    assert.deepEqual(b.load([p], { referrer: 'https://example.com/landing' }).shown, ['popup-17'], 'next page, same visit');
    assert.deepEqual(b.load([p], { referrer: 'https://www.paypal.com/' }).shown, ['popup-17'], 'back from paying: still the same source');
    assert.deepEqual(b.load([p], { search: '?utm_campaign=autumn' }).shown, [], 'a new campaign replaces it');
    b.endSession();
    assert.deepEqual(b.load([p]).shown, [], 'a new visit starts afresh');
});

test('a referrer rule', () => {
    const b = browser();
    const p = popup(18, { sources: ['google.com'] });
    assert.deepEqual(b.load([p], { referrer: 'https://www.google.com/' }).shown, ['popup-18']);
    const c = browser();
    assert.deepEqual(c.load([p], { referrer: 'https://www.bing.com/' }).shown, []);
});

test('idle: shows once the visitor stops', () => {
    const page = browser().load([{ ...popup(19), trigger: 'idle', triggerValue: '20' }]);
    assert.deepEqual(page.shown, ['popup-19']);
});

test('element in view needs the element, and a selector that parses', () => {
    const page = browser().load([{ ...popup(20), trigger: 'view', triggerValue: '#nope' }, { ...popup(21), trigger: 'view', triggerValue: '[[bad' }]);
    assert.deepEqual(page.shown, []);
});

test('a modal waits for the cookie banner, and shows a moment after it goes', () => {
    const banner = { visible: true };
    const page = browser().load([{ ...popup(22), holdFor: ['#banner', '[[not a selector'] }], { banner });
    assert.deepEqual(page.shown, [], 'banner up');
    page.tick();
    assert.deepEqual(page.shown, [], 'still up');
    banner.visible = false;
    page.tick();
    assert.deepEqual(page.shown, [], 'gone, grace period');
    page.tick();
    assert.deepEqual(page.shown, ['popup-22']);
});

test('no banner on the page, no wait', () => {
    assert.deepEqual(browser().load([{ ...popup(23), holdFor: ['#banner'] }]).shown, ['popup-23']);
});

test('a banner still hidden at load (Toss decides asynchronously) gets a moment to appear', () => {
    const banner = { visible: false };
    const page = browser().load([{ ...popup(24), holdFor: ['#banner'] }], { banner });
    assert.deepEqual(page.shown, [], 'on the page, not yet showing: wait');
    banner.visible = true;
    page.tick();
    assert.deepEqual(page.shown, [], 'it appeared');
    banner.visible = false;
    page.tick();
    page.tick();
    assert.deepEqual(page.shown, ['popup-24']);
});

test('exit intent on a phone falls back to the popup’s delay, when it has one', () => {
    const phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';
    const withFallback = browser({ ua: phone }).load([{ ...exitPopup(40), triggerValue: '20' }]);
    assert.deepEqual(withFallback.shown, ['popup-40'], 'never scrolled back up, shown after the fallback');

    const without = browser({ ua: phone }).load([{ ...exitPopup(41), triggerValue: null }]);
    assert.deepEqual(without.shown, [], 'no fallback: only the flick');

    const desktop = browser().load([{ ...exitPopup(42), triggerValue: '20' }]);
    assert.deepEqual(desktop.shown, [], 'a desktop has a mouse to leave with: no timer');
});

test('audience and cart rules', () => {
    const guest = { user: { guest: true, groups: [] } };
    const member = { user: { guest: false, groups: ['wholesale'] } };
    const user = (t) => ({ needs: ['user'], ...t });
    const cart = (t) => ({ needs: ['cart'], cart: 'any', ...t });
    const c = (over) => ({ cart: { empty: false, subtotal: 40, qty: 2, in: [], ...over } });

    assert.equal(visitorAllows({}, undefined), true, 'no such rules, nothing to ask');
    assert.equal(visitorAllows(user({ audience: 'guests' }), guest), true);
    assert.equal(visitorAllows(user({ audience: 'guests' }), member), false);
    assert.equal(visitorAllows(user({ audience: 'users' }), guest), false);
    assert.equal(visitorAllows(user({ audience: 'users', groups: ['wholesale', 'vip'] }), member), true, 'any of the groups');
    assert.equal(visitorAllows(user({ audience: 'users', groups: ['vip'] }), member), false);
    assert.equal(visitorAllows(user({ audience: 'guests' }), {}), false, 'no answer is a no');

    assert.equal(visitorAllows(cart({ cart: 'empty' }), c({ empty: true, subtotal: 0 })), true);
    assert.equal(visitorAllows(cart({ cart: 'empty' }), c()), false);
    assert.equal(visitorAllows(cart({ cart: 'notEmpty' }), c({ empty: true, subtotal: 0 })), false);
    assert.equal(visitorAllows(cart({ cartMin: 40 }), c()), true, 'at least: inclusive');
    assert.equal(visitorAllows(cart({ cartMin: 40.01 }), c()), false);
    assert.equal(visitorAllows(cart({ cartBelow: 50 }), c()), true, 'a free-shipping nudge at 50');
    assert.equal(visitorAllows(cart({ cartBelow: 40 }), c()), false, 'below: exclusive');
    assert.equal(visitorAllows(cart({ cartIn: [7, 9] }), c({ in: [9] })), true);
    assert.equal(visitorAllows(cart({ cartIn: [7] }), c({ in: [] })), false);
    assert.equal(visitorAllows(cart({ cart: 'empty' }), {}), false, 'no answer is a no');
});

test('the visitor query asks once, for what the page needs', () => {
    assert.equal(visitorQuery([popup(1), popup(2)]), null, 'nothing needs it');
    const u = { ...popup(3, { needs: ['user'] }), visitorUrl: '/index.php?p=actions/leads/visitor' };
    const k = { ...popup(4, { needs: ['cart'], cartIn: [9, 3] }), visitorUrl: '/index.php?p=actions/leads/visitor' };
    const k2 = { ...popup(5, { needs: ['cart'], cartIn: [3] }), visitorUrl: '/index.php?p=actions/leads/visitor' };
    assert.equal(visitorQuery([u, k, k2]), '/index.php?p=actions/leads/visitor&need=cart,user&in=3,9');
    assert.equal(visitorQuery([{ ...u, visitorUrl: '/actions/leads/visitor' }]), '/actions/leads/visitor?need=user');
});

const nudge = (id, below) => ({ ...popup(id, { needs: ['cart'], cart: 'notEmpty', cartBelow: below }), visitorUrl: '/actions/leads/visitor' });

test('a page without audience or cart rules asks the server nothing', async () => {
    const page = browser().load([popup(43)], { visitor: { cart: { empty: true } } });
    await settle();
    assert.deepEqual(page.requests, []);
    assert.deepEqual(page.shown, ['popup-43']);
});

test('a cart rule waits for the answer, then shows or not', async () => {
    const yes = browser().load([nudge(44, 50)], { visitor: { cart: { empty: false, subtotal: 30, in: [] } } });
    assert.deepEqual(yes.shown, [], 'not before the answer');
    await settle();
    assert.deepEqual(yes.shown, ['popup-44']);
    assert.equal(yes.requests.length, 1, 'one request');

    const no = browser().load([nudge(45, 50)], { visitor: { cart: { empty: true, subtotal: 0, in: [] } } });
    await settle();
    assert.deepEqual(no.shown, []);

    const refused = browser().load([nudge(46, 50)], { visitor: 429 });
    await settle();
    assert.deepEqual(refused.shown, [], 'turned away: no answer, no popup');
});

test('after leads:cart-changed, a refused popup is checked again', async () => {
    let cart = { empty: true, subtotal: 0, in: [] };
    const page = browser().load([nudge(47, 50)], { visitor: () => ({ cart }) });
    await settle();
    assert.deepEqual(page.shown, [], 'empty cart');

    cart = { empty: false, subtotal: 20, in: [] };
    page.dispatch('leads:cart-changed');
    await settle();
    assert.deepEqual(page.shown, ['popup-47'], 'added to cart by Ajax');
    assert.equal(page.requests.length, 2);
});

test('an answer that comes back after a fresher one is ignored', async () => {
    let late;
    const answers = [
        new Promise((resolve) => { late = resolve; }),        // the page-load request, held back
        { cart: { empty: false, subtotal: 20, in: [] } },     // after leads:cart-changed
    ];
    const page = browser().load([nudge(49, 50)], { visitor: () => answers.shift() });
    page.dispatch('leads:cart-changed');
    await settle();
    late({ cart: { empty: true, subtotal: 0, in: [] } });     // the old cart, arriving last
    await settle();
    assert.deepEqual(page.shown, ['popup-49'], 'the fresh cart decides');
    assert.equal(page.requests.length, 2);
});

test('a guests-only popup stays off a signed-in visitor’s page', async () => {
    const config = { ...popup(48, { needs: ['user'], audience: 'guests' }), visitorUrl: '/actions/leads/visitor' };
    const member = browser().load([config], { visitor: { user: { guest: false, groups: [] } } });
    const guest = browser().load([config], { visitor: { user: { guest: true, groups: [] } } });
    await settle();
    assert.deepEqual(member.shown, []);
    assert.deepEqual(guest.shown, ['popup-48']);
});
