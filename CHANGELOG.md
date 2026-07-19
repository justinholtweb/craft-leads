# Changelog

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
