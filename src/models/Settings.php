<?php

namespace justinholtweb\leads\models;

use craft\base\Model;

class Settings extends Model
{
    /** A folder under the site templates: no leading slash, no `..`, no dots at all. */
    private const FOLDER = '/^([A-Za-z0-9_][A-Za-z0-9_-]{0,99})(\/[A-Za-z0-9_][A-Za-z0-9_-]{0,99}){0,5}$|^$/';

    public bool $autoInjectScript = true;
    public string $defaultButtonColor = '#3b82f6';
    public string $defaultBackgroundColor = '#ffffff';
    public int $dataRetentionDays = 365;
    public bool $enableHoneypot = true;
    public int $rateLimitPerMinute = 5;

    /**
     * Let the webhook integration post to private, loopback and link-local addresses — an
     * endpoint on your own network. Off by default; set it in `config/leads.php`, it isn't on the
     * settings screen. Redirects are never followed either way.
     */
    public bool $allowPrivateWebhookHosts = false;

    /** Tracking events (impressions, conversions, closes) one address may send in a minute. */
    public int $trackingPerMinute = 60;

    /**
     * CSS selectors for the site's cookie banner, one per line. While one of them is on screen,
     * modals and slide-ins wait, and show once it has gone. Blank to never wait.
     */
    public string $consentBannerSelectors = "[data-toss-consent-root]\n#CybotCookiebotDialog\n#onetrust-banner-sdk\n.osano-cm-dialog\n.cky-consent-container\n#iubenda-cs-banner\n#cmplz-cookiebanner-container .cmplz-cookiebanner\n#cc-main .cm\n.cc-window";

    /**
     * The folder in the site's templates whose templates editors can pick as a popup's design,
     * next to the built-in ones. Blank to offer only the built-in designs.
     */
    public string $designsFolder = '_leads';

    /** How long a double opt-in confirmation link works, in hours. */
    public int $confirmationExpiryHours = 48;

    /** The confirmation email's subject. `{siteName}` and `{popup}` are filled in. */
    public string $confirmationSubject = 'Please confirm your subscription to {siteName}';

    /**
     * The confirmation email's text. Plain text, not Twig: `{link}`, `{siteName}`, `{popup}` and
     * `{hours}` are filled in, and `{link}` has to be there.
     */
    public string $confirmationBody = "Thanks for signing up to {siteName}.\n\nPlease confirm your address by opening this link:\n\n{link}\n\nThe link works for {hours} hours. If you didn't sign up, ignore this email and you won't hear from us again.";

    /**
     * A site template to show the confirmation page with, instead of Leads' own plain page. It gets
     * `state` (`ask`, `confirmed` or `invalid`), `code`, and `popup`.
     */
    public string $confirmationTemplate = '';

    /**
     * The craft-lock purpose a confirmed sign-up's consent is recorded under, when Lock is
     * installed. Blank to not record it there.
     */
    public string $lockPurpose = 'marketing';

    public function defineRules(): array
    {
        return [
            [['confirmationExpiryHours'], 'integer', 'min' => 1, 'max' => 720],
            [['confirmationSubject'], 'string', 'max' => 255],
            [['confirmationBody'], 'string', 'max' => 5000],
            [['confirmationBody'], function(string $attribute) {
                if (trim($this->$attribute) !== '' && !str_contains($this->$attribute, '{link}')) {
                    $this->addError($attribute, \Craft::t('leads', 'The email has to include {link}, or nobody can confirm.', ['link' => '{link}']));
                }
            }],
            [['confirmationTemplate'], 'string', 'max' => 500],
            [['consentBannerSelectors'], 'string', 'max' => 2000],
            [['designsFolder'], 'match', 'pattern' => self::FOLDER, 'message' => \Craft::t('leads', 'A folder in your templates, such as `_leads` or `_partials/popups`.')],
            [['lockPurpose'], 'match', 'pattern' => '/^[A-Za-z0-9_-]{0,64}$/'],
            [['defaultButtonColor', 'defaultBackgroundColor'], 'string', 'max' => 20],
            [['dataRetentionDays'], 'integer', 'min' => 0],
            [['rateLimitPerMinute'], 'integer', 'min' => 1, 'max' => 60],
            [['trackingPerMinute'], 'integer', 'min' => 1, 'max' => 600],
            [['autoInjectScript', 'enableHoneypot', 'allowPrivateWebhookHosts'], 'boolean'],
        ];
    }

    /**
     * The cookie banner selectors as a list.
     *
     * @return string[]
     */
    public function getConsentBannerSelectors(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->consentBannerSelectors) ?: []), static fn($s) => $s !== ''));
    }

    /** The designs folder, or null when there is none or it isn't one Leads will read. */
    public function getDesignsFolder(): ?string
    {
        $folder = trim($this->designsFolder, " \t/");

        return $folder !== '' && preg_match(self::FOLDER, $folder) ? $folder : null;
    }
}
