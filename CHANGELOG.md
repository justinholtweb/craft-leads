# Changelog

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
