# Leads — Popup & Lead Generation for Craft CMS

Popup and lead generation plugin for Craft CMS 5. Create modals, slide-ins, notification bars, and inline forms with targeting rules, email service integrations, and analytics — all natively within your control panel.

## Requirements

- Craft CMS 5.3.0 or later
- PHP 8.2 or later

## Installation

```bash
composer require justinholtweb/craft-leads
php craft plugin/install leads
```

## Features

| Feature | Description |
|---|---|
| Popup types | Modal, slide-in, notification bar, inline form |
| Triggers | Time delay, scroll percentage, exit intent, click |
| Templates | 8 built-in designs (clean, bold, minimal) |
| Integrations | Mailchimp, ConvertKit, webhook |
| Analytics | Impressions, conversions, conversion rates |
| Targeting | Page URLs, device type, visitor frequency, new/returning visitors, page-view minimums |
| Spam protection | Honeypot field, rate limiting |
| Queue sync | Background sync to email providers via Craft queue |

## Configuration

All settings are available in the control panel under **Leads → Settings**, or via `config/leads.php`:

```php
<?php

return [
    'autoInjectScript' => true,
    'defaultButtonColor' => '#3b82f6',
    'defaultBackgroundColor' => '#ffffff',
    'dataRetentionDays' => 365,
    'enableHoneypot' => true,
    'rateLimitPerMinute' => 5,
    'trackingPerMinute' => 60,
    // Config only: let webhooks reach private/loopback addresses (an internal CRM, say).
    'allowPrivateWebhookHosts' => false,
];
```

Settings are project config, so the Settings screen saves only for an admin on an environment where `allowAdminChanges` is on; everywhere else it is read-only.

`rateLimitPerMinute` (submissions) and `trackingPerMinute` (impression/conversion/close events) are per visitor address, each under a site-wide ceiling of 20× the value. The address is the connecting one: `X-Forwarded-For` is only believed when you have set Craft's `trustedHosts` to your proxies — if your site sits behind a load balancer or CDN, set it, or every visitor shares one budget.

## Usage

### Auto-Inject (Default)

When `autoInjectScript` is enabled, active popups are automatically injected on all frontend pages. No template changes needed.

### Manual Twig Functions

Disable auto-inject and add to your template manually:

```twig
{# Render all active popups for the current page #}
{{ leadsPopups() }}
```

```twig
{# Render a specific inline form by slug #}
{{ leadsInline('newsletter-signup') }}
```

### Template Variable

Access popup data via the `craft.leads` variable:

```twig
{# Query popups #}
{% set activePopups = craft.leads.popups.popupStatus('active').all() %}

{# Get total submissions #}
{{ craft.leads.totalSubmissions() }}

{# Get analytics overview #}
{% set stats = craft.leads.overviewStats('2025-01-01', '2025-12-31') %}
{{ stats.conversionRate }}%
```

### Popup Types

| Type | Description | Position Options |
|---|---|---|
| `modal` | Centered overlay with backdrop | — |
| `slidein` | Corner panel | `bottom-right`, `bottom-left` |
| `bar` | Full-width notification strip | `top`, `bottom` |
| `inline` | Embedded in page content | — |

### Trigger Types

| Trigger | Value | Description |
|---|---|---|
| `time` | Seconds (e.g., `3`) | Show after N seconds |
| `scroll` | Percentage (e.g., `50`) | Show when user scrolls past N% |
| `exit` | — | Show on exit intent (mouse leaves viewport) |
| `click` | CSS selector (e.g., `.cta-btn`) | Show when element is clicked |

### Targeting

Each popup's **Targeting Rules** are set in its editor:

| Rule | Options |
|---|---|
| Show on / Don't show on | URL patterns, one per line — `*` matches anything (`/blog/*`). Blank "Show on" means every page; "Don't show on" wins. A pattern is matched against the path and against the path with its query string. |
| Devices | Desktop, tablet, mobile |
| How often | On every page view, once per session, once per visitor, at most every N days |
| Visitors | All, new (first visit), returning |
| After page views | Show only from the visitor's Nth page view on the site (`0` = straight away) |
| Days hidden after closing | How long a visitor who closes it goes without seeing it (default 1; `0` = no pause) |
| Hide after signing up | Never show it again once the visitor has submitted it (default on) |

Page rules are applied on the server — the URL is part of every full-page cache key. Everything about the visitor is decided by the page script in their browser: the device from User-Agent Client Hints or the user agent, and what they've seen from `localStorage` (key `leads`, or a `leads` cookie where storage is blocked) plus a `leads_session` session cookie. So a page served from Blitz, a CDN or any other full-page cache shows each visitor the right popups. While a live popup uses a page-view or visitor rule, the script loads on every front-end page so those pages count.

Inline forms (`leadsInline()`) follow the page, device, visitor and page-view rules and are hidden after a sign-up; "How often" and closing don't apply to them.

Rules can be set in code too, as the `targetingRules` attribute:

```php
$popup->targetingRules = [
    'pages' => ['/blog/*'],
    'excludePages' => ['/blog/archive/*'],
    'devices' => ['desktop', 'tablet'],  // empty = every device
    'frequency' => 'days',               // every | session | once | days
    'frequencyDays' => 7,
    'minPageViews' => 2,
    'visitor' => 'returning',            // all | new | returning
    'dismissDays' => 1,
    'hideAfterConversion' => true,
];
```

### Built-in Templates

8 templates across 3 style families:

- **Clean** — Light backgrounds, subtle styling: `clean-modal`, `clean-slidein`, `clean-bar`
- **Bold** — Dark backgrounds, high contrast: `bold-modal`, `bold-slidein`, `bold-bar`
- **Minimal** — Stripped down, content-first: `minimal-modal`, `minimal-inline`

## Email Integrations

Each popup can sync submissions to an email service provider. Syncing happens in the background via the Craft queue.

### Mailchimp

Set `integrationProvider` to `mailchimp` and provide:
- `apiKey` — Your Mailchimp API key (ends with `-usX`; the data centre is checked before any request is made)
- `listId` — The audience/list ID to add subscribers to

### ConvertKit

Set `integrationProvider` to `convertkit` and provide:
- `apiSecret` — Your ConvertKit API secret
- `formId` — The form ID to subscribe to

### Webhook

Set `integrationProvider` to `webhook` and provide:
- `webhookUrl` — URL to receive a POST with `{ email, name, custom_fields, timestamp }`

A webhook URL must be `http`/`https`, carry no credentials, and resolve only to public addresses; the request is pinned to the address that was checked and doesn't follow redirects. Set `allowPrivateWebhookHosts` in `config/leads.php` to send to an internal host.

Every integration setting can be an environment variable (`$MAILCHIMP_API_KEY`) so keys stay out of the database. A popup won't save while a referenced variable is unset.

## Analytics

Leads tracks three daily metrics per popup:

- **Impressions** — How many times the popup was displayed
- **Conversions** — How many form submissions were made
- **Closes** — How many times the popup was dismissed

Stats are aggregated daily (one row per popup per day) for efficient querying. View them on the **Leads → Dashboard** page with date range filtering.

## Submissions

All form submissions are stored in the `leads_submissions` table and viewable under **Leads → Submissions**. Export as CSV for use in other tools; cells a spreadsheet would run as a formula (starting `=`, `+`, `-`, `@`) are prefixed with `'` so they open as text.

## Permissions

| Permission | Description |
|---|---|
| `leads:accessPlugin` | Access the Leads CP section |
| `leads:managePopups` | Create, edit, delete popups |
| `leads:viewSubmissions` | View submissions list |
| `leads:exportSubmissions` | Export submissions as CSV |
| `leads:deleteSubmissions` | Delete individual submissions |
| `leads:viewDashboard` | View the analytics dashboard |
| `leads:manageSettings` | See the Settings screen (saving needs an admin where admin changes are allowed) |

## Events

Listen to events in a custom module or plugin:

```php
use justinholtweb\leads\events\PopupEvent;
use justinholtweb\leads\events\SubmissionEvent;

// After a popup is saved
Event::on(Popup::class, Popup::EVENT_AFTER_SAVE, function (PopupEvent $event) {
    // $event->popup, $event->isNew
});

// After a submission is created
Event::on(Submissions::class, 'afterSubmit', function (SubmissionEvent $event) {
    // $event->submission
});
```

## Programmatic Usage

```php
use justinholtweb\leads\Plugin;
use justinholtweb\leads\elements\Popup;

// Get a popup by ID
$popup = Plugin::getInstance()->popups->getById(123);

// Query active popups for a URL
$popups = Plugin::getInstance()->popups->getActivePopupsForPage('/blog');

// Record a submission
Plugin::getInstance()->submissions->submit(
    popupId: 123,
    email: 'user@example.com',
    name: 'Jane Doe',
);

// Get analytics
$stats = Plugin::getInstance()->analytics->getOverviewStats('2025-01-01', '2025-12-31');
```

## License

This plugin is proprietary software. A valid license is required for each installation. Licenses can be purchased at [plugins.craftcms.com](https://plugins.craftcms.com/leads).
