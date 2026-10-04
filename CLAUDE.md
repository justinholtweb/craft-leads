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
- Queue-based sync: SyncSubmissionJob pushed after form submission
- Vanilla JS frontend: no jQuery, reads `window._leadsConfig` JSON
- Auto-inject option via `EVENT_AFTER_RENDER_PAGE_TEMPLATE` or manual `{{ leadsPopups() }}`. Skips action requests and sandbox-CSP responses; spliced with `strripos`, never `preg_replace` (a `$10` in popup text is a back-reference)
- JSON written into a `<script>` goes through `Renderer::scriptJson()` (JSON_HEX_*)

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
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/security.php   # 27, over HTTP; flips CRAFT_ALLOW_ADMIN_CHANGES briefly, self-cleaning
```

## Database Tables
- `leads_popups` — Element table (PK → elements.id)
- `leads_submissions` — Form submissions with sync status
- `leads_stats` — Daily aggregated impressions/conversions/closes

## CP Navigation
Leads → Dashboard | Popups | Submissions | Settings

## Permissions
- `leads:accessPlugin`, `leads:managePopups`, `leads:viewSubmissions`, `leads:exportSubmissions`, `leads:deleteSubmissions`, `leads:viewDashboard`, `leads:manageSettings`

## Reference Plugins
Follow patterns from `craft-dispatch` and `craft-wink` in the same workspace.
