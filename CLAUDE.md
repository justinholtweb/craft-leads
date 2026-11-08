# Craft Leads

## Plugin Overview
Popup and lead generation plugin for Craft CMS 5. Creates modals, slide-ins, notification bars, and inline forms with targeting rules, email service integrations (Mailchimp, ConvertKit, webhook), and an analytics dashboard.

## Architecture
- **Package:** `justinholtweb/craft-leads`
- **Namespace:** `justinholtweb\leads`
- **Handle:** `leads`
- **Edition:** Single paid (no free/lite/pro split)
- **PHP:** ^8.2 | **Craft:** ^5.3.0

## Key Patterns
- Popup is a Craft Element type — full element index with search, statuses, bulk actions
- Server-side popup HTML rendering via Twig templates, passed as JSON to frontend JS
- Daily aggregated stats table (one row per popup per day) using atomic upserts
- Integration abstraction: AbstractIntegration base class with provider implementations
- Queue-based sync: SyncSubmissionJob pushed after form submission (or after double opt-in confirmation)
- Vanilla JS frontend: no jQuery, reads `window._leadsConfig` JSON
- Auto-inject option via `EVENT_AFTER_RENDER_PAGE_TEMPLATE` or manual `{{ leadsPopups() }}`. Skips action requests, sandbox-CSP responses and non-HTML `Content-Type`s; spliced with `strripos`, never `preg_replace` (a `$10` in popup text is a back-reference)
- JSON written into a `<script>` goes through `Renderer::scriptJson()` (JSON_HEX_*)
- Targeting (5.1.0): `helpers\Targeting` (pure, unit-tested) normalizes/validates the `targetingRules` JSON. Server applies only page patterns (`matchesPage`, include/exclude, path and path+query); device/frequency/visitor rules go to the browser via `forClient()` and are decided in `leads.js` (`window.LeadsTargeting`), state in localStorage `leads` (cookie fallback) + `leads_session` session cookie — cache-safe. `Popups::countsVisits()` makes the script load on every page when a page-view/visitor/source rule is live
- 5.3.0: `startDate`/`endDate` (ISO UTC in the targeting JSON) are checked by the server (`Targeting::inSchedule`) *and* the browser (`start`/`end` ms). Traffic `sources` are client-only: captured on a session's first page or any `utm_*` page into the `leads_source` session cookie. Triggers `idle`, `view`. Each modal/slide-in config carries `holdFor` (Settings `consentBannerSelectors`); `showPopup` waits while one is on screen
- 5.4.0 (#6): audience (`audience` all|guests|users, `userGroups` handles) and Commerce cart rules (`cart` any|empty|notEmpty, `cartMin`, `cartBelow` on item subtotal, `cartCategories` element IDs incl. structure descendants). `Targeting::needs()` → `forClient()['needs']`; the config then carries `visitorUrl` (root-relative action URL — actions are never Blitz-cached) and `leads.js` makes ONE `GET actions/leads/visitor?need=user,cart&in=ids` (`VisitorController`, `no-store, private`, `visitor` rate bucket = `trackingPerMinute`). `visitorAllows()` is separate from `allows()`; no answer = no. `helpers\CartBridge` reaches Commerce via `getPlugin('commerce')` and only `peekCart()`s (never creates a cart/cookie/session). `leads:cart-changed` on window re-asks (only the latest answer is kept: `visitorAsked`) and retries overlays the cart rule refused. `Popup::$triggerValue` defaults to null (the editor seeds 3 for a new popup)
- Designs: `templateKey` is a `Renderer::BUILT_IN` key or `site:<name>` for a template directly in Settings `designsFolder` (`_leads`; `_`-prefixed = partials, not listed). Site designs render in SITE mode with `popup`, `form` (from `_popup-templates/_form`), `fields`, `consent`, `success`; must have one root element. `Popup::validateDesign` test-renders on save. Never build a template path from an unchecked key
- Exit trigger: `triggerValue` = fallback seconds on touch devices (null = none); the editor posts it as `exitFallback`. `m261103_000000_exit_intent_fallback` nulled it on existing exit popups

## Consent & double opt-in (5.2.0)
- `helpers\Optin` (pure, unit-tested): the `consentSettings` JSON (`checkbox`, `required`, `text`, `doubleOptIn` = `off|email|provider`), wording → HTML (escaped; only `[label](http(s)://…|/path)` links), wording version (sha256), tokens (32 random bytes base64url, 43 chars) and their sha256 hash
- Submission consent columns (`consentGiven` null = not asked, `consentText`, `consentVersion`, `consentedAt`, `consentEvidence` {toss: snapshot}, `confirmTokenHash`, `confirmExpiresAt`, `confirmedAt`) — dates bare UTC via `Db::prepareDateForDb`. These are what a future Lock collector should read
- **Nothing reaches an integration unconfirmed**: `Submissions::queueSync()` and `SyncSubmissionJob` both check `Submissions::isReleasable()`. Before this, the sync job was never pushed at all
- Confirm: `GET leads/confirm?code=` only displays (scanner-safe); the CSRF'd POST to `leads/confirm/index` spends the token with one conditional UPDATE (hash + unconfirmed + unexpired, affected rows must be 1). `code`, never `token` (Craft's preview param). Token shape is checked before any query; exact match only, never `Db::parseParam`. Rate-limited (`confirm`, 20/min), `no-store`, `no-referrer`, `noindex`
- `helpers\ConsentBridge` reaches Toss (`consent->isActive()/snapshot()`) and Lock (`consent->record()`) through `getPlugin()->get()`, never by class name, after `isPluginEnabled()`. Toss is read only in the submit request. Lock gets a grant only on confirmation with the box ticked (Lock refuses anonymous grants by design)

## Security rules (5.0.6)
- Settings are project config: `SettingsController::canSave()` = admin && allowAdminChanges; the save merges only `EDITABLE` over the current settings
- Public endpoints (`leads/submit`, `leads/tracking/track`) budget through `helpers\RateLimit::allow()` — connecting address (forwarded only with real `trustedHosts`), IPv6 /64, under a lock, 20× global ceiling. Tracking ignores popups that aren't `active`
- Webhooks: `Integrations::webhookTarget()` (pure, no Craft — unit tests run without it) + `helpers\Ip`; delivery is pinned with `CURLOPT_RESOLVE`, no redirects. `allowPrivateWebhookHosts` is config-only
- Integration settings may be `$ENV` refs, resolved in `Integrations::getIntegration()`; `Popup::validateIntegrationSettings` reports unset vars and refused targets. The editor stores only `Integrations::FIELDS[provider]`
- CSV export cells go through `SubmissionsController::csvCell()`
- Never nest a `<form>` in a CP template — secondary actions use `Craft.sendActionRequest`

## Testing
```sh
docker exec -w /sites/craft-leads ddev-phpstan-runner-web bash -c 'vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/security.php   # 28, over HTTP; flips CRAFT_ALLOW_ADMIN_CHANGES briefly, self-cleaning
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/targeting.php  # editor → page, over HTTP, self-cleaning
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/optin.php      # consent + double opt-in, over HTTP, self-cleaning
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/visitor.php    # audience/cart rules (needs Commerce), site designs, exit fallback
node --test tests/js/*.test.mjs   # the page script's targeting, in a VM with a fake browser
```

## Database Tables
- `leads_popups` — Element table (PK → elements.id)
- `leads_submissions` — Form submissions with sync status (`pending|synced|failed|unconfirmed|none`) and consent record
- `leads_stats` — Daily aggregated impressions/conversions/closes

## CP Navigation
Leads → Dashboard | Popups | Submissions | Settings

## Permissions
- `leads:accessPlugin`, `leads:managePopups`, `leads:viewSubmissions`, `leads:exportSubmissions`, `leads:deleteSubmissions`, `leads:viewDashboard`, `leads:manageSettings`

## Reference Plugins
Follow patterns from `craft-dispatch` and `craft-wink` in the same workspace.
