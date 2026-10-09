<?php

namespace justinholtweb\leads\helpers;

/**
 * A popup's targeting rules — which pages, which devices, how often, and for which visitors.
 *
 * Only the page rules are decided on the server: the URL is part of every full-page cache key, so
 * leaving a popup out of a page's HTML is safe. Everything about the visitor — their device, how
 * often they've seen the popup, whether they've signed up — is decided by the page script from
 * `forClient()`, in their browser, so a cached page serves every visitor correctly.
 *
 * Pure PHP, no Craft, so the unit tests run without it.
 *
 * Stored shape (the `targetingRules` JSON column):
 *
 *     pages               string[]  include patterns; none = every page
 *     excludePages        string[]  exclude patterns, which win over includes
 *     devices             string[]  desktop/tablet/mobile; none = every device
 *     frequency           string    every|session|once|days
 *     frequencyDays       int       for `days`
 *     minPageViews        int       show only from the visitor's Nth page view; 0 = no minimum
 *     visitor             string    all|new|returning
 *     hideAfterConversion bool      never show again once the visitor has signed up
 *     dismissDays         int       days hidden after the visitor closes it; 0 = no pause
 */
abstract class Targeting
{
    public const DEVICES = ['desktop', 'tablet', 'mobile'];
    public const FREQUENCIES = ['every', 'session', 'once', 'days'];
    public const VISITORS = ['all', 'new', 'returning'];

    /** How many patterns a list may hold, and how long each may be. */
    public const MAX_PATTERNS = 100;
    public const MAX_PATTERN_LENGTH = 255;

    /** The behaviour a popup with no rules has — and every popup had before 5.1.0, bar conversions. */
    public const DEFAULTS = [
        'pages' => [],
        'excludePages' => [],
        'devices' => [],
        'frequency' => 'every',
        'frequencyDays' => 7,
        'minPageViews' => 0,
        'visitor' => 'all',
        'hideAfterConversion' => true,
        'dismissDays' => 1,
    ];

    /**
     * Rules as stored or as posted by the popup editor, in the stored shape with every key set.
     *
     * Pattern lists may arrive as an array or as the editor's one-per-line text; devices as the
     * checkbox list (all three ticked means "any", stored as none). Values that can't be used are
     * kept as given where `problems()` can name them, and otherwise fall back to the default.
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $rules): array
    {
        $rules = is_array($rules) ? $rules : [];
        $out = self::DEFAULTS;

        $out['pages'] = self::patterns($rules['pages'] ?? []);
        $out['excludePages'] = self::patterns($rules['excludePages'] ?? []);

        if (array_key_exists('devices', $rules)) {
            $devices = $rules['devices'];
            if (is_string($devices) && trim($devices) === '') {
                // The editor's checkboxes posted with none ticked send only their empty hidden
                // input. Remember that, so validation can say so; an empty array means "any".
                $out['devices'] = ['none'];
            } else {
                $devices = is_array($devices) ? $devices : ($devices === '*' ? self::DEVICES : [$devices]);
                $devices = array_values(array_unique(array_filter(array_map(static fn($d) => is_string($d) ? strtolower(trim($d)) : '', $devices), static fn($d) => $d !== '')));
                // Every device is the same as no device rule; store it the short way.
                $out['devices'] = array_diff(self::DEVICES, $devices) === [] ? [] : $devices;
            }
        }

        foreach (['frequency', 'visitor'] as $key) {
            if (isset($rules[$key]) && is_string($rules[$key]) && $rules[$key] !== '') {
                $out[$key] = $rules[$key];
            }
        }

        foreach (['frequencyDays', 'minPageViews', 'dismissDays'] as $key) {
            if (isset($rules[$key]) && $rules[$key] !== '') {
                $out[$key] = is_numeric($rules[$key]) && (int)$rules[$key] == $rules[$key] ? (int)$rules[$key] : $rules[$key];
            }
        }

        if (array_key_exists('hideAfterConversion', $rules)) {
            $out['hideAfterConversion'] = filter_var($rules['hideAfterConversion'], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /**
     * What's wrong with a set of (normalized) rules, as messages for the editor.
     *
     * @param array<string, mixed> $rules
     * @return string[]
     */
    public static function problems(array $rules): array
    {
        $problems = [];

        foreach (['pages' => 'Show on', 'excludePages' => 'Don’t show on'] as $key => $label) {
            if (count($rules[$key]) > self::MAX_PATTERNS) {
                $problems[] = sprintf('“%s” takes at most %d patterns.', $label, self::MAX_PATTERNS);
            }
            foreach ($rules[$key] as $pattern) {
                if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
                    $problems[] = sprintf('“%s” patterns can be at most %d characters long.', $label, self::MAX_PATTERN_LENGTH);
                    break;
                }
            }
        }

        if ($rules['devices'] === ['none']) {
            $problems[] = 'Choose at least one device.';
        } elseif (array_diff($rules['devices'], self::DEVICES) !== []) {
            $problems[] = 'Devices must be desktop, tablet or mobile.';
        }

        if (!in_array($rules['frequency'], self::FREQUENCIES, true)) {
            $problems[] = 'Choose how often the popup may show.';
        } elseif ($rules['frequency'] === 'days' && (!is_int($rules['frequencyDays']) || $rules['frequencyDays'] < 1 || $rules['frequencyDays'] > 3650)) {
            $problems[] = 'The number of days between showings must be a whole number from 1 to 3650.';
        }

        if (!in_array($rules['visitor'], self::VISITORS, true)) {
            $problems[] = 'Visitors must be all, new or returning.';
        }

        if (!is_int($rules['minPageViews']) || $rules['minPageViews'] < 0 || $rules['minPageViews'] > 1000) {
            $problems[] = 'The page-view minimum must be a whole number from 0 to 1000.';
        }

        if (!is_int($rules['dismissDays']) || $rules['dismissDays'] < 0 || $rules['dismissDays'] > 3650) {
            $problems[] = 'The days hidden after closing must be a whole number from 0 to 3650.';
        }

        return $problems;
    }

    /**
     * Whether a popup with these rules belongs on the page at `$url` (a request URI such as
     * `/blog/post?utm_source=x`).
     *
     * Patterns are shell wildcards (`*`, `?`, `[…]`), matched against the path and, so that a
     * pattern can name a query string, against the path with its query string too. An exclude
     * always wins over an include.
     *
     * @param array<string, mixed> $rules
     */
    public static function matchesPage(array $rules, string $url): bool
    {
        $candidates = array_unique([(string)(parse_url($url, PHP_URL_PATH) ?: '/'), $url]);

        $matches = static function(array $patterns) use ($candidates): bool {
            foreach ($patterns as $pattern) {
                foreach ($candidates as $candidate) {
                    if (fnmatch($pattern, $candidate)) {
                        return true;
                    }
                }
            }

            return false;
        };

        if (!empty($rules['excludePages']) && $matches($rules['excludePages'])) {
            return false;
        }

        return empty($rules['pages']) || $matches($rules['pages']);
    }

    /**
     * The rules the page script needs — everything but the page patterns, which the server has
     * already applied.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    public static function forClient(array $rules): array
    {
        return [
            'devices' => array_values($rules['devices']),
            'frequency' => $rules['frequency'],
            'frequencyDays' => (int)$rules['frequencyDays'],
            'minPageViews' => (int)$rules['minPageViews'],
            'visitor' => $rules['visitor'],
            'hideAfterConversion' => (bool)$rules['hideAfterConversion'],
            'dismissDays' => (int)$rules['dismissDays'],
        ];
    }

    /**
     * Whether these rules need the visitor's page views and visits counted — which happens on
     * every page that loads the script, popup or not.
     *
     * @param array<string, mixed> $rules
     */
    public static function countsVisits(array $rules): bool
    {
        return $rules['minPageViews'] > 0 || $rules['visitor'] !== 'all';
    }

    /**
     * @return string[]
     */
    private static function patterns(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $patterns = [];
        foreach ($value as $pattern) {
            if (is_string($pattern) && ($pattern = trim($pattern)) !== '') {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }
}
