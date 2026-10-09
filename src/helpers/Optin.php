<?php

namespace justinholtweb\leads\helpers;

/**
 * A popup's consent checkbox and double opt-in, and the confirmation tokens they hand out.
 *
 * Pure (no Craft), so it is unit-tested on its own. Stored shape, the `consentSettings` JSON
 * column:
 *
 *     checkbox     bool    show a consent checkbox under the fields
 *     required     bool    the form won't submit without it ticked (only with `checkbox`)
 *     text         string  the checkbox's wording; `[label](https://…)` becomes a link
 *     doubleOptIn  string  `off`, `email` (Leads sends a confirmation link) or `provider`
 *                          (Mailchimp's `pending` status, or the ConvertKit form's own email)
 *
 * The wording is stored on each submission as it was at the moment of submitting, with its
 * {@see version()}, so "what did they agree to" is answerable after the popup has been reworded.
 */
abstract class Optin
{
    public const MODE_OFF = 'off';
    public const MODE_EMAIL = 'email';
    public const MODE_PROVIDER = 'provider';

    public const MODES = [self::MODE_OFF, self::MODE_EMAIL, self::MODE_PROVIDER];

    /** Providers that confirm sign-ups themselves. */
    public const CONFIRMING_PROVIDERS = ['mailchimp', 'convertkit'];

    public const DEFAULT_TEXT = 'I agree to receive emails and accept the privacy policy.';

    /** Longest wording kept; it's a sentence beside a checkbox, not a policy. */
    public const MAX_TEXT = 1000;

    /** A token is 32 random bytes, base64url without padding: always 43 characters. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * Consent settings with every key set and every value the right type.
     *
     * @return array{checkbox: bool, required: bool, text: string, doubleOptIn: string}
     */
    public static function normalize(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];

        $checkbox = self::bool($settings['checkbox'] ?? false);
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string)(is_scalar($settings['text'] ?? null) ? $settings['text'] : '')));
        $mode = (string)(is_scalar($settings['doubleOptIn'] ?? null) ? $settings['doubleOptIn'] : self::MODE_OFF);

        return [
            'checkbox' => $checkbox,
            // A required box that isn't shown would refuse every submission.
            'required' => $checkbox && self::bool($settings['required'] ?? false),
            'text' => $text,
            'doubleOptIn' => in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF,
        ];
    }

    /**
     * Why these settings can't be saved, as English messages; an empty list means fine.
     *
     * @param array{checkbox: bool, required: bool, text: string, doubleOptIn: string} $settings Normalized.
     * @return string[]
     */
    public static function problems(array $settings, ?string $provider): array
    {
        $problems = [];

        if ($settings['checkbox'] && $settings['text'] === '') {
            $problems[] = 'Write the consent text the checkbox shows.';
        }

        if (mb_strlen($settings['text']) > self::MAX_TEXT) {
            $problems[] = 'The consent text can be at most 1000 characters.';
        }

        if ($settings['doubleOptIn'] === self::MODE_PROVIDER && !in_array($provider, self::CONFIRMING_PROVIDERS, true)) {
            $problems[] = 'Only Mailchimp and ConvertKit confirm sign-ups themselves. Choose one of them, or have Leads send the confirmation email.';
        }

        return $problems;
    }

    /** The wording's fingerprint: the same text always gives the same version. */
    public static function version(string $text): string
    {
        return hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", $text)));
    }

    /**
     * The wording as HTML: everything escaped, then `[label](url)` turned into a link when the URL
     * is `http(s)://` or site-relative. Anything else, `javascript:` included, stays as text.
     */
    public static function html(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $linked = preg_replace_callback(
            '~\[([^\[\]\n]{1,200})\]\(((?:https?://|/(?!/))[^\s()<>"\'&]*(?:&amp;[^\s()<>"\'&]*)*)\)~i',
            static fn(array $m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $escaped,
        );

        return nl2br($linked ?? $escaped, false);
    }

    /** A new confirmation token. Only its {@see hash()} is stored. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Whether a string could be a token at all — checked before any lookup. */
    public static function isTokenShaped(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** What is stored and looked up: a database read never sees a usable token. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
    }
}
