# Changelog

## 5.0.6 - 2026-10-04

> {warning} Leads settings can now only be changed by an admin, on an environment where `allowAdminChanges` is on — "Manage settings" alone shows them read-only. Webhook integrations now refuse private, loopback and link-local addresses; set `allowPrivateWebhookHosts` in `config/leads.php` if yours posts to an internal host. If the site is behind a proxy or CDN, set Craft's `trustedHosts` so the rate limits see visitors' addresses rather than the proxy's.

### Security

- **A webhook could point anywhere.** Each anonymous submission was POSTed to the popup's webhook URL with no check, so anyone who could manage popups could aim the server at the cloud metadata service or the private network — or, through cURL, at another scheme entirely. A webhook must now be `http`/`https`, carry no credentials and resolve only to public addresses; the request is pinned to the addresses that were checked and doesn't follow redirects. A popup with a refused URL won't save, and one already stored is refused at send time.
- **"Manage settings" could change project-config settings on the live site**, where the next deploy silently undid them. Saving now needs an admin where admin changes are allowed, as Craft's own settings do; everyone else sees the screen read-only. The save also only takes the fields the form has, merged over the current settings.
- **The CSV export handed spreadsheets formulas.** Names and page URLs come from anonymous visitors, and a cell starting `=`, `+`, `-`, `@`, a tab or a carriage return runs as a formula when staff open the file (`=HYPERLINK(…)`). Such cells are now prefixed with `'`.
- **The submission rate limit could be reset by any client** — it keyed on `getUserIP()`, which reads `X-Forwarded-For` unasked, and its read-then-write let parallel requests through. It now keys on the connecting address (the forwarded one only when `trustedHosts` names your proxies), counts under a lock, and sits under a site-wide ceiling.
- **The tracking endpoint counted anything.** Impressions, conversions and closes were recorded for any popup ID, live or not, as fast as they were sent — the dashboard and the A/B numbers were whatever anyone posted. Events now count only for active popups, within the new `trackingPerMinute` budget (60 per address, under a site-wide ceiling); over it, the endpoint answers 429.
- **A `</script>` in a popup's custom CSS or text closed the injected config script.** The JSON written into the page is now hex-escaped.
- A Mailchimp API key whose data-centre suffix isn't one (`-us6`) was used to build the request host. It's now checked first, and the list ID is URL-encoded.

### Added

- Integration settings (API keys, list and form IDs, the webhook URL) can be environment variables, so keys stay out of the database; a popup won't save while one it references is unset. The popup editor shows only the chosen provider's fields and stores only those.
- `trackingPerMinute` and `allowPrivateWebhookHosts` settings.

### Fixed

- **"Save $10" lost its "$10".** Auto-injection spliced the popup in with `preg_replace()`, where `$10` in the popup's text is a back-reference. It's now a plain splice before the last `</body>`.
- Auto-injection no longer touches action responses or documents served with a sandbox Content Security Policy.
- The popup editor's Duplicate button was a form nested inside the edit form; it now posts on its own.
- A webhook answering with a 3xx is no longer counted as delivered.

## 5.0.5 - 2026-09-17

### Fixed

- **Clicking Leads in the control panel sidebar 404'd.** `getCpNavItem()` inherited its top-level URL from `BasePlugin`, which points at the bare plugin handle — and `leads` had no CP route of its own, only its four children. The nav item now points at the first subnav entry the signed-in user can actually reach, and `leads` is routed to the dashboard so bookmarks and typed URLs resolve too. ([#2](https://github.com/justinholtweb/craft-leads/issues/2))
- The Dashboard and Submissions subnav entries were shown to anyone holding `leads:accessPlugin`, but their controllers require `leads:viewDashboard` and `leads:viewSubmissions` — so those users were offered links that answered 403. Each subnav gate now mirrors the permission its controller enforces, and the nav item is hidden entirely when none of them are reachable.

## 5.0.4 - 2026-09-07

### Fixed

- **The popup editor was unusable.** Control panel → Leads → Popups → New (and the edit screen for any existing popup) died with `Twig\Error\RuntimeError: Variable "forms" does not exist` before any field rendered. `popups/edit.twig` used `forms.textField()` etc. without importing Craft's form macros — the 5.0.2 fix for this same bug in the settings template never made it to the popup editor. ([#1](https://github.com/justinholtweb/craft-leads/issues/1))

## 5.0.3 - 2026-08-26

### Fixed

- **The Popups screen was unusable.** It rendered `_elements/indexcontainer` by hand and got three things wrong at once: no `|raw`, so the entire element-index markup was printed on the page as escaped HTML; `sources: null`, which makes Craft's index controller return no rows by design; and no source list, so Modals, Slide-ins, Drafts and the rest were unreachable. It now extends `_layouts/elementindex` like every other screen.
- **The popups index returned HTTP 500 whenever the status column was shown.** Craft 5 expects `statuses()` to return `craft\enums\Color` cases rather than colour strings.

## 5.0.2 - 2026-08-19

### Fixed
- Settings → Plugins → Leads now redirects to the plugin's own settings screen instead of embedding it. The embedded copy nested a full control panel page (with its own `fullPageForm` and action input) inside Craft's settings form, producing a form-in-a-form with two `action` inputs that could post to the wrong handler.
- The settings template now imports Craft's `forms` macros, which it relies on for every field it renders.

## 5.0.1 - 2026-07-19

### Fixed
- Custom popup index columns (status badge, type and trigger labels) now render on the element index — updated the element to Craft 5's `attributeHtml()` hook (the old `tableAttributeHtml()` was silently ignored and could fatal on its fallback).
- Submission custom fields are no longer double-encoded when saved — the value is stored as JSON once instead of a JSON-encoded string.

### Added
- PHPStan (level 5) and ECS configuration, plus `composer phpstan`, `composer ecs`, and `composer check` scripts.
- Regression tests pinning the Craft 5 element index-column contract.

## 5.0.0 - 2026-06-12

### Added
- Initial release
- Popup types: modal, slide-in, notification bar, inline
- Trigger types: time delay, scroll percentage, exit intent, click
- 8 built-in popup templates (clean, bold, minimal styles)
- Form submissions with email, name, and custom fields
- Daily aggregated analytics (impressions, conversions, closes)
- Email integrations: Mailchimp, ConvertKit, webhook
- Queue-based background sync to email service providers
- Page URL targeting rules with wildcard matching
- Cookie-based frequency capping (frontend)
- Analytics dashboard with date filtering and per-popup stats
- Full Craft element index for popups with search and bulk actions
- Auto-inject script option or manual Twig functions (`leadsPopups()`, `leadsInline()`)
- Honeypot spam protection and per-IP rate limiting
