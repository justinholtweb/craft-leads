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
        /** Load a page carrying `configs`; returns the popups it put on screen and the tracked events. */
        load(configs = []) {
            const shown = [];
            const events = [];
            const timers = [];
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
                createElement: element, addEventListener() {}, removeEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; },
            };
            const window = {
                _leadsConfig: configs,
                navigator: { userAgent: ua, maxTouchPoints: 0, sendBeacon: (url, data) => events.push(JSON.parse(data)) },
                screen: { width: 1440, height: 900 },
                matchMedia: () => ({ matches: false }),
                localStorage: storage
                    ? { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)) }
                    : { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } },
                addEventListener() {},
                location: { href: 'https://example.com/' },
            };
            const context = {
                window, document, navigator: window.navigator, JSON, Date, Math, String, RegExp,
                setTimeout: (fn) => timers.push(fn), requestAnimationFrame: () => {},
                encodeURIComponent, decodeURIComponent,
            };
            vm.runInNewContext(source, context);
            timers.splice(0).forEach((fn) => fn());

            return { shown, events, targeting: window.LeadsTargeting };
        },
    };
}

const popup = (id, targeting = {}) => ({ id, type: 'modal', trigger: 'time', triggerValue: '1', html: `popup-${id}`, targeting });
const { deviceType, allows } = browser().load().targeting;

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
