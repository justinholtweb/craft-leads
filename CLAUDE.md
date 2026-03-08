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
- Auto-inject option via `EVENT_AFTER_RENDER_PAGE_TEMPLATE` or manual `{{ leadsPopups() }}`

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
