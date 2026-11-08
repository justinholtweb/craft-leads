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
 *     sources             string[]  traffic sources, any of which will do; none = every visitor.
 *                                   `utm_campaign=spring` (a UTM parameter, `*` wildcards in the
 *                                   value) or a referring host (`google.com`, which covers its
 *                                   subdomains; `*` wildcards allowed)
 *     startDate           ?string   shown from this moment, ISO 8601 UTC (`2026-11-27T05:00:00Z`)
 *     endDate             ?string   …until this one
 *     audience            string    all|guests|users (signed in)
 *     userGroups          string[]  user group handles, any of which will do; implies `users`
 *     cart                string    any|empty|notEmpty (Craft Commerce)
 *     cartMin             ?float    the cart's item subtotal is at least this
 *     cartBelow           ?float    …and below this — a free-shipping nudge
 *     cartCategories      int[]     the cart holds a product related to one of these categories or
 *                                   structure entries, or to one of their descendants
 *
 * The audience and cart rules are about the visitor too, and a cached page can't know them. The
 * page script asks an uncached action (`leads/visitor`) for just what the page's rules `need`.
 */
abstract class Targeting
{
    public const DEVICES = ['desktop', 'tablet', 'mobile'];
    public const FREQUENCIES = ['every', 'session', 'once', 'days'];
    public const VISITORS = ['all', 'new', 'returning'];
    public const AUDIENCES = ['all', 'guests', 'users'];
    public const CARTS = ['any', 'empty', 'notEmpty'];

    /** How many user groups, and how many categories, a popup may name. */
    public const MAX_GROUPS = 50;
    public const MAX_CATEGORIES = 100;

    /** How many patterns a list may hold, and how long each may be. */
    public const MAX_PATTERNS = 100;
    public const MAX_PATTERN_LENGTH = 255;

    /** How many traffic sources a popup may name. */
    public const MAX_SOURCES = 50;

    /** The stored form of a schedule date. */
    public const DATE_FORMAT = 'Y-m-d\\TH:i:s\\Z';

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
        'sources' => [],
        'startDate' => null,
        'endDate' => null,
        'audience' => 'all',
        'userGroups' => [],
        'cart' => 'any',
        'cartMin' => null,
        'cartBelow' => null,
        'cartCategories' => [],
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

        // Hosts and parameter names are case-insensitive; store them the way they're compared.
        $out['sources'] = array_values(array_unique(array_map(static function(string $source): string {
            $eq = strpos($source, '=');
            return $eq === false ? strtolower($source) : strtolower(substr($source, 0, $eq)) . substr($source, $eq);
        }, self::patterns($rules['sources'] ?? []))));

        foreach (['startDate', 'endDate'] as $key) {
            $value = $rules[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                // A date the editor's controller couldn't read is kept, so `problems()` can name it.
                $time = self::time($value);
                $out[$key] = $time === null ? trim($value) : gmdate(self::DATE_FORMAT, $time);
            }
        }

        foreach (['audience', 'cart'] as $key) {
            if (isset($rules[$key]) && is_string($rules[$key]) && $rules[$key] !== '') {
                $out[$key] = $rules[$key];
            }
        }

        // The editor's group checkboxes, ticked or not; handles are compared as Craft stores them.
        $out['userGroups'] = array_values(array_unique(array_filter(
            array_map(static fn($g) => is_string($g) ? trim($g) : '', is_array($rules['userGroups'] ?? null) ? $rules['userGroups'] : []),
            static fn($g) => $g !== '',
        )));
        // Naming a group is naming signed-in visitors.
        if ($out['userGroups'] !== [] && $out['audience'] === 'all') {
            $out['audience'] = 'users';
        }

        foreach (['cartMin', 'cartBelow'] as $key) {
            $value = $rules[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value !== null && $value !== '') {
                // Kept as given when it isn't a number, so `problems()` can say so.
                $out[$key] = is_numeric($value) ? round((float)$value, 2) : $value;
            }
        }

        // An element select posts IDs as strings, with an empty string when nothing is chosen.
        $categories = is_array($rules['cartCategories'] ?? null) ? $rules['cartCategories'] : [];
        $out['cartCategories'] = array_values(array_unique(array_map('intval', array_filter(
            $categories,
            static fn($id) => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0,
        ))));

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

        if (count($rules['sources']) > self::MAX_SOURCES) {
            $problems[] = sprintf('“Traffic source” takes at most %d lines.', self::MAX_SOURCES);
        }
        foreach ($rules['sources'] as $source) {
            if (!self::isSource($source)) {
                $problems[] = sprintf('“%s” isn’t a traffic source. Write a UTM parameter such as `utm_source=newsletter`, or a referring site such as `google.com`.', mb_substr($source, 0, 80));
                break;
            }
        }

        $start = $rules['startDate'] === null ? null : self::time($rules['startDate']);
        $end = $rules['endDate'] === null ? null : self::time($rules['endDate']);
        if ($rules['startDate'] !== null && $start === null) {
            $problems[] = 'The start date isn’t a date.';
        }
        if ($rules['endDate'] !== null && $end === null) {
            $problems[] = 'The end date isn’t a date.';
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $problems[] = 'The end date has to be after the start date.';
        }

        if (!in_array($rules['audience'], self::AUDIENCES, true)) {
            $problems[] = 'Signed in must be anyone, guests or signed-in users.';
        } elseif ($rules['audience'] === 'guests' && $rules['userGroups'] !== []) {
            $problems[] = 'Guests aren’t in any user group. Choose signed-in users, or no groups.';
        }
        if (count($rules['userGroups']) > self::MAX_GROUPS) {
            $problems[] = sprintf('Choose at most %d user groups.', self::MAX_GROUPS);
        }
        foreach ($rules['userGroups'] as $group) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,254}$/', $group)) {
                $problems[] = 'User groups are named by their handles.';
                break;
            }
        }

        if (!in_array($rules['cart'], self::CARTS, true)) {
            $problems[] = 'The cart rule must be any, empty or not empty.';
        }
        foreach (['cartMin' => 'Subtotal at least', 'cartBelow' => 'Subtotal below'] as $key => $label) {
            if ($rules[$key] !== null && (!is_float($rules[$key]) || $rules[$key] < 0 || $rules[$key] > 1e9)) {
                $problems[] = sprintf('“%s” must be an amount of 0 or more.', $label);
            }
        }
        $min = is_float($rules['cartMin']) ? $rules['cartMin'] : null;
        $below = is_float($rules['cartBelow']) ? $rules['cartBelow'] : null;
        if ($min !== null && $below !== null && $below <= $min) {
            $problems[] = '“Subtotal below” has to be more than “Subtotal at least”.';
        }
        if (count($rules['cartCategories']) > self::MAX_CATEGORIES) {
            $problems[] = sprintf('Choose at most %d categories.', self::MAX_CATEGORIES);
        }
        if ($rules['cart'] === 'empty' && ($rules['cartCategories'] !== [] || ($min !== null && $min > 0))) {
            $problems[] = 'An empty cart has no products and a subtotal of 0. Choose “Any” or “Not empty” for the cart.';
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
     * Whether the popup's start and end dates allow it at `$now` (a Unix timestamp). The server
     * leaves out a popup outside its dates; the page script checks again, so a page cached while
     * a popup was running stops showing it when it ends.
     *
     * @param array<string, mixed> $rules
     */
    public static function inSchedule(array $rules, int $now): bool
    {
        $start = $rules['startDate'] === null ? null : self::time($rules['startDate']);
        $end = $rules['endDate'] === null ? null : self::time($rules['endDate']);

        return ($start === null || $now >= $start) && ($end === null || $now < $end);
    }

    /**
     * Whether a popup's schedule is still to come or over — for the popup index.
     *
     * @param array<string, mixed> $rules
     * @return 'scheduled'|'ended'|null
     */
    public static function scheduleState(array $rules, int $now): ?string
    {
        $start = $rules['startDate'] === null ? null : self::time($rules['startDate']);
        $end = $rules['endDate'] === null ? null : self::time($rules['endDate']);

        if ($end !== null && $now >= $end) {
            return 'ended';
        }

        return $start !== null && $now < $start ? 'scheduled' : null;
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
            'sources' => array_values($rules['sources']),
            // Milliseconds, as the page script's clock counts.
            'start' => $rules['startDate'] === null ? null : self::time($rules['startDate']) * 1000,
            'end' => $rules['endDate'] === null ? null : self::time($rules['endDate']) * 1000,
            'audience' => $rules['audience'],
            'groups' => array_values($rules['userGroups']),
            'cart' => $rules['cart'],
            'cartMin' => is_float($rules['cartMin']) ? $rules['cartMin'] : null,
            'cartBelow' => is_float($rules['cartBelow']) ? $rules['cartBelow'] : null,
            'cartIn' => array_values($rules['cartCategories']),
            'needs' => self::needs($rules),
        ];
    }

    /**
     * What the page script has to ask the server about the visitor before these rules can be
     * decided: `user` (signed in, and their groups) and `cart`. Usually nothing, and then the page
     * makes no request at all.
     *
     * @param array<string, mixed> $rules
     * @return list<'user'|'cart'>
     */
    public static function needs(array $rules): array
    {
        $needs = [];
        if ($rules['audience'] !== 'all' || $rules['userGroups'] !== []) {
            $needs[] = 'user';
        }
        if ($rules['cart'] !== 'any' || $rules['cartMin'] !== null || $rules['cartBelow'] !== null || $rules['cartCategories'] !== []) {
            $needs[] = 'cart';
        }

        return $needs;
    }

    /**
     * Whether these rules need the page script on every page, popup or not: to count the
     * visitor's page views and visits, or to note where the visit came from on the page it
     * started on.
     *
     * @param array<string, mixed> $rules
     */
    public static function countsVisits(array $rules): bool
    {
        return $rules['minPageViews'] > 0 || $rules['visitor'] !== 'all' || $rules['sources'] !== [];
    }

    /**
     * `utm_name=value` or a host pattern. Values may hold anything but line breaks; `*` is the
     * only wildcard the page script honours.
     */
    private static function isSource(string $source): bool
    {
        if (str_contains($source, '=')) {
            return (bool)preg_match('/^utm_[a-z0-9_]{1,40}=[^\r\n]{1,200}$/', $source);
        }

        return strlen($source) <= 253 && (bool)preg_match('/^[a-z0-9*-]+(\.[a-z0-9*-]+)+$/', $source);
    }

    /** A Unix timestamp from an ISO 8601 date, or null when it isn't one. */
    private static function time(string $value): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', trim($value))) {
            return null;
        }
        $time = strtotime(trim($value));

        return $time === false ? null : $time;
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
