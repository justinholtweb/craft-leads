# Leads — AI Agent Guide

## What This Is

Leads is a commercial popup and lead generation plugin for **Craft CMS 5**. It lets site owners create modals, slide-ins, notification bars, and inline forms — with targeting rules, email integrations (Mailchimp, ConvertKit, webhook), and an analytics dashboard — all from the Craft control panel.

- **Namespace**: `justinholtweb\leads`
- **Package**: `justinholtweb/craft-leads`
- **PHP**: 8.2+
- **Framework**: Craft CMS 5.3+ (built on Yii 2)
- **Edition**: Single paid (no free/lite/pro split)

## Architecture Overview

```
src/
├── Plugin.php                    # Entry point. Registers services, routes, element types, Twig extensions, variables
├── icon.svg / icon-mask.svg      # CP icons (mask = fill-only for nav)
├── controllers/
│   ├── DashboardController.php   # CP — analytics overview with date filtering
│   ├── IntegrationsController.php # CP AJAX — test connection, fetch lists
│   ├── PopupsController.php      # CP — CRUD (index, edit, save, duplicate)
│   ├── SettingsController.php    # CP — global settings
│   ├── SubmissionsController.php # CP — list, export CSV, delete submissions
│   ├── SubmitController.php      # Site — anonymous form POST (CSRF-exempt)
│   └── TrackingController.php    # Site — anonymous impression/conversion/close (CSRF-exempt)
├── elements/
│   ├── Popup.php                 # Custom Craft element type — the core domain object
│   └── db/PopupQuery.php         # Element query with popupType/status/trigger/provider filters
├── enums/                        # PHP 8.2 backed string enums
│   ├── IntegrationProvider.php   # mailchimp, convertkit, webhook
│   ├── PopupPosition.php         # top, bottom, bottom-right, bottom-left
│   ├── PopupStatus.php           # draft, active, paused, archived (with label + color)
│   ├── PopupType.php             # modal, slidein, bar, inline
│   ├── SyncStatus.php            # pending, synced, failed (with label + color)
│   └── TriggerType.php           # time, scroll, exit, click
├── events/
│   ├── PopupEvent.php            # Fired on popup lifecycle events
│   └── SubmissionEvent.php       # Fired on form submission
├── integrations/
│   ├── AbstractIntegration.php   # Base class: sendSubscriber(), testConnection(), getLists()
│   ├── ConvertKitIntegration.php # ConvertKit API v3 implementation
│   ├── MailchimpIntegration.php  # Mailchimp API v3 implementation
│   └── WebhookIntegration.php    # Generic POST webhook implementation
├── migrations/
│   └── Install.php               # Creates 3 tables with FKs and indexes
├── models/
│   └── Settings.php              # Plugin settings with validation rules
├── queue/jobs/
│   └── SyncSubmissionJob.php     # Background sync submission to ESP via queue
├── records/                      # ActiveRecord classes (1:1 with DB tables)
│   ├── PopupRecord.php           # {{%leads_popups}}
│   ├── StatsRecord.php           # {{%leads_stats}}
│   └── SubmissionRecord.php      # {{%leads_submissions}}
├── services/
│   ├── Analytics.php             # Record & query daily impressions/conversions/closes
│   ├── Integrations.php          # Factory + connection testing + list fetching
│   ├── Popups.php                # CRUD, page matching, targeting rules
│   ├── Renderer.php              # Server-side popup HTML from Twig templates
│   └── Submissions.php           # Submit, list, export, delete
├── templates/
│   ├── _layouts/plugin.twig      # Base CP layout extending _layouts/cp
│   ├── _popup-templates/         # 8 built-in popup designs
│   │   ├── clean-modal.twig      # Light modal with form
│   │   ├── clean-slidein.twig    # Light slide-in panel
│   │   ├── clean-bar.twig        # Light notification bar
│   │   ├── bold-modal.twig       # Dark modal with bold styling
│   │   ├── bold-slidein.twig     # Dark slide-in panel
│   │   ├── bold-bar.twig         # Dark notification bar
│   │   ├── minimal-modal.twig    # Stripped-down modal
│   │   └── minimal-inline.twig   # Embedded inline form
│   ├── dashboard/index.twig      # Stats cards + date range filter
│   ├── popups/
│   │   ├── index.twig            # Element index with sources and status menu
│   │   └── edit.twig             # Full popup editor form
│   ├── settings/index.twig       # Plugin settings form
│   └── submissions/index.twig    # Submissions table with pagination + export
├── translations/en/leads.php     # English translation strings
├── twig/
│   ├── LeadsTwigExtension.php    # leadsPopups(), leadsInline() functions
│   └── LeadsVariable.php         # craft.leads.popups(), totalSubmissions(), overviewStats()
└── web/assets/
    ├── cp/
    │   ├── CpAsset.php           # CP asset bundle (depends on Craft CpAsset)
    │   └── dist/
    │       ├── css/leads-cp.css  # CP styles
    │       └── js/leads-cp.js    # Live preview, template switching
    └── frontend/
        ├── FrontendAsset.php     # Frontend asset bundle
        └── dist/
            ├── css/leads.css     # .leads-* namespaced popup styles (responsive)
            └── js/leads.js       # Vanilla JS popup engine (~5KB)
```

## Key Design Decisions

### Popups Are Craft Elements
`Popup` extends `craft\base\Element`. This provides the standard element index, search, custom statuses (`draft`, `active`, `paused`, `archived`), bulk actions, and query API. The content table is `{{%leads_popups}}` joined to `elements` via `id`.

### Server-Side Popup Rendering
Popup HTML is rendered server-side via Twig templates, then passed as pre-rendered strings in a JSON config (`window._leadsConfig`) to the frontend JS. This keeps the frontend engine simple and allows full Twig power in popup templates.

### Daily Aggregated Stats
The `{{%leads_stats}}` table stores one row per popup per day. Stats are incremented atomically (UPDATE then INSERT if no existing row). No per-visitor tracking rows — keeps the table small even at scale.

### Integration Abstraction
`AbstractIntegration` defines the contract: `sendSubscriber()`, `testConnection()`, `getLists()`. Three implementations: `MailchimpIntegration`, `ConvertKitIntegration`, `WebhookIntegration`. The `Integrations` service acts as a factory.

### Queue-Based Sync
`SyncSubmissionJob` is pushed to the Craft queue after a form submission. This prevents slow API calls from blocking the user's form submit response.

### Vanilla JS Frontend
`leads.js` is a self-contained IIFE with no dependencies. It reads `window._leadsConfig`, manages trigger handlers (time/scroll/exit/click), cookie-based frequency capping, DOM injection, overlay management, form submission via `fetch`, and tracking via `navigator.sendBeacon`.

## Database Schema

Three tables — see `src/migrations/Install.php` for full DDL:

| Table | Type | Notes |
|---|---|---|
| `{{%leads_popups}}` | Element | PK is FK to `elements.id` CASCADE. Stores all popup config. |
| `{{%leads_submissions}}` | Standard | Form submissions with email, name, sync status. FK to popups. |
| `{{%leads_stats}}` | Standard | Daily aggregated stats. Unique index on `(popupId, date)`. FK to popups. |

## Service Access Pattern

Services are Yii components on the Plugin instance:

```php
Plugin::getInstance()->popups        // Popups (CRUD, targeting)
Plugin::getInstance()->submissions   // Submissions (submit, list, export)
Plugin::getInstance()->analytics     // Analytics (record, query stats)
Plugin::getInstance()->integrations  // Integrations (factory, test, lists)
Plugin::getInstance()->renderer      // Renderer (popup HTML, config JSON)
```

## Conventions to Follow

- **Craft CMS 5 patterns**: element types, ActiveRecords, services as components, CP templates extending `_layouts/cp.twig`
- **PHP 8.2 features**: use enums, typed properties, named arguments, `match` expressions, union types
- **Translations**: wrap all user-facing strings with `Craft::t('leads', '...')` in PHP or `'...'|t('leads')` in Twig
- **New translations**: add to `src/translations/en/leads.php`
- **Schema changes**: create a new migration in `src/migrations/`, bump `Plugin::$schemaVersion`
- **No external PHP dependencies**: everything uses Craft/Yii built-ins + curl for integrations
- **Frontend JS**: vanilla JS only (no frameworks), keep `leads.js` lightweight
- **CSS namespacing**: all frontend styles use `.leads-*` prefix to avoid conflicts

## Common Tasks

### Adding a new popup template
1. Create Twig file in `src/templates/_popup-templates/{name}.twig`
2. Follow the existing pattern: `data-leads-id`, `data-leads-close`, `data-leads-form` attributes
3. Include `leads-form`, `leads-input`, `leads-btn`, `leads-success` classes
4. Add option to `PopupsController::actionEdit()` `$templateOptions` array

### Adding a new integration provider
1. Add case to `src/enums/IntegrationProvider.php`
2. Create class extending `AbstractIntegration` in `src/integrations/`
3. Implement `sendSubscriber()`, `testConnection()`, `getLists()`
4. Add factory case in `Integrations::getIntegration()`
5. Add translation string

### Adding a new trigger type
1. Add case to `src/enums/TriggerType.php`
2. Add handler in `leads.js` `setupPopup()` switch statement
3. Add to controller's `$triggerTypeOptions`
4. Add translation string

### Adding a new setting
1. Add property + default to `src/models/Settings.php`
2. Add validation rule in `defineRules()`
3. Add form field in `src/templates/settings/index.twig`
4. Add translation string

### Adding a database column
1. Create `src/migrations/m{YYMMDD}_{HHMMSS}_{description}.php`
2. Update the relevant ActiveRecord in `src/records/`
3. Update `PopupQuery::beforePrepare()` if it's on the popups table
4. Update `Popup.php` element class if it's a popup property
5. Bump `Plugin::$schemaVersion`

### Adding a new CP route
1. Add rule in `Plugin::registerCpRoutes()`
2. Create controller action
3. Create template in `src/templates/`

### Adding a new site-facing route
1. Add rule in `Plugin::registerSiteRoutes()`
2. Set `$allowAnonymous` in the controller (use action IDs, not method names)
3. Disable CSRF in `beforeAction()` if needed

## Things to Watch Out For

- **Never rename Plugin.php or change its class/namespace** after publishing. Craft stores the FQCN in project config.
- **Element queries use `joinElementTable()`** — the table name passed must match the unprefixed table name without `{{%}}` wrapping (i.e., `leads_popups` not `{{%leads_popups}}`).
- **JSON columns** (`formFields`, `targetingRules`, `integrationSettings`) may arrive as strings from the database. Always use the `get*Array()` helper methods on the Popup element.
- **Anonymous controllers** (`SubmitController`, `TrackingController`) disable CSRF and allow anonymous access — be careful with changes to these.
- **Stats atomicity** — `Analytics::incrementStat()` uses UPDATE-then-INSERT to avoid race conditions. Don't replace this with a simple INSERT.
- **Cookie-based frequency capping** in `leads.js` uses `leads_seen_{id}` cookies. The default is 24 hours for close, 30 days for conversion.
- **Integration API keys** are stored in `integrationSettings` JSON on the popup record. These are sensitive — never expose in frontend config.

## Reference Plugins

This plugin follows patterns established in sibling plugins in the same workspace:
- `craft-dispatch` — Email marketing plugin (elements, services, queue jobs, CP templates)
- `craft-wink` — A/B testing plugin (Twig extensions, tracking controllers, asset bundles)
