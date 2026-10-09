<?php

namespace justinholtweb\leads\helpers;

use Craft;
use yii\base\Module;

/**
 * Leads' side of the family's consent contract: reading Toss, writing to Lock. Both optional.
 *
 * Neither plugin's classes are named here. Each is reached through Craft's plugin registry after
 * `isPluginEnabled()` has said it is there, so a site without them never loads a class that
 * doesn't exist — the rule in Toss's `docs/consent-api.md`.
 *
 * - **Toss** is the site's cookie consent manager. When it is installed with its consent kit on,
 *   a submission records the visitor's Toss answer alongside the checkbox, read in the submit
 *   request itself — a form post belongs to one visitor, which is where Toss says a server read
 *   is safe. Without Toss, nothing changes.
 * - **Lock** keeps a ledger of consent tied to a person. A sign-up is only written there once it
 *   is confirmed by email: Lock ignores grants from anonymous forms, because anybody can type
 *   anybody's address, and a confirmed double opt-in is exactly the "confirm by email through
 *   your own flow" its docs ask for.
 */
abstract class ConsentBridge
{
    /** Whether Toss is installed, enabled and managing consent. */
    public static function tossIsActive(): bool
    {
        $consent = self::service('toss', 'consent');

        try {
            return $consent !== null && $consent->isActive() === true;
        } catch (\Throwable $e) {
            Craft::warning('Leads could not ask Toss whether it manages consent: ' . $e->getMessage(), 'leads');

            return false;
        }
    }

    /**
     * The visitor's Toss consent — `categories`, `granted`, `decided`, `gpc` — or null without Toss.
     * Call it only inside the visitor's own request: a queue job or console command has no cookie
     * and would read "undecided".
     *
     * @return array<string, mixed>|null
     */
    public static function tossSnapshot(): ?array
    {
        if (!self::tossIsActive()) {
            return null;
        }

        try {
            $snapshot = self::service('toss', 'consent')?->snapshot();
        } catch (\Throwable $e) {
            Craft::warning('Leads could not read the visitor’s Toss consent: ' . $e->getMessage(), 'leads');

            return null;
        }

        return is_array($snapshot)
            ? array_intersect_key($snapshot, array_flip(['categories', 'granted', 'decided', 'gpc']))
            : null;
    }

    /** Whether Lock is installed and enabled. */
    public static function lockIsActive(): bool
    {
        return self::service('lock', 'consent') !== null;
    }

    /**
     * Records a confirmed sign-up's consent in Lock's ledger, under `$purpose`. Returns whether it
     * was written; a failure is logged and never stops the confirmation.
     *
     * Evidence uses Lock's own keys (`text`, `url`, `ip`, `userAgent`), so Lock's anonymisation
     * clears the identifying ones when it should.
     *
     * @param array<string, mixed> $evidence
     */
    public static function recordInLock(string $email, string $purpose, array $evidence): bool
    {
        $consent = self::service('lock', 'consent');

        if ($consent === null || $purpose === '') {
            return false;
        }

        try {
            return $consent->record($email, $purpose, 'granted', 'form', $evidence) !== null;
        } catch (\Throwable $e) {
            Craft::error('Leads could not record consent in Lock: ' . $e->getMessage(), 'leads');

            return false;
        }
    }

    /** A plugin's component, or null when the plugin isn't installed and enabled. */
    private static function service(string $plugin, string $component): ?object
    {
        $plugins = Craft::$app->getPlugins();

        if (!$plugins->isPluginEnabled($plugin)) {
            return null;
        }

        $instance = $plugins->getPlugin($plugin);

        if (!$instance instanceof Module || !$instance->has($component)) {
            return null;
        }

        try {
            return $instance->get($component);
        } catch (\Throwable) {
            return null;
        }
    }
}
