<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use justinholtweb\leads\helpers\Ip;
use justinholtweb\leads\integrations\AbstractIntegration;
use justinholtweb\leads\integrations\ConvertKitIntegration;
use justinholtweb\leads\integrations\MailchimpIntegration;
use justinholtweb\leads\integrations\WebhookIntegration;
use justinholtweb\leads\Plugin;

class Integrations extends Component
{
    /** The settings each provider takes; nothing else is stored for it. */
    public const FIELDS = [
        'mailchimp' => ['apiKey', 'listId'],
        'convertkit' => ['apiSecret', 'formId'],
        'webhook' => ['webhookUrl'],
    ];

    public function getIntegration(string $provider, array $settings): ?AbstractIntegration
    {
        $settings = $this->resolve($settings);

        return match ($provider) {
            'mailchimp' => new MailchimpIntegration($settings),
            'convertkit' => new ConvertKitIntegration($settings),
            'webhook' => new WebhookIntegration($settings, Plugin::getInstance()->getSettings()->allowPrivateWebhookHosts),
            default => null,
        };
    }

    /**
     * Integration settings with `$ENV_VAR` references swapped for their values, so keys can stay
     * out of the database (and out of every database dump). Resolved at the moment of use only;
     * the reference is what's stored. Before 5.0.6 keys could only be stored as they were.
     */
    public function resolve(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (is_string($value) && str_starts_with($value, '$')) {
                $settings[$key] = (string)App::parseEnv($value);
            }
        }

        return $settings;
    }

    /**
     * The problems with a popup's integration settings, as messages; an empty list means fine.
     *
     * @return string[]
     */
    public function problems(string $provider, array $settings): array
    {
        $problems = [];

        foreach (self::FIELDS[$provider] ?? [] as $field) {
            $value = $settings[$field] ?? null;

            if (is_string($value) && str_starts_with($value, '$') && (string)App::parseEnv($value) === '') {
                $problems[] = Craft::t('leads', 'The environment variable {name} isn’t set.', ['name' => $value]);
            }
        }

        $resolved = $this->resolve($settings);

        if ($provider === 'webhook' && !empty($resolved['webhookUrl'])) {
            $target = self::webhookTarget((string)$resolved['webhookUrl'], Plugin::getInstance()->getSettings()->allowPrivateWebhookHosts);

            if (is_string($target)) {
                $problems[] = Craft::t('leads', $target);
            }
        }

        if ($provider === 'mailchimp' && !empty($resolved['apiKey']) && MailchimpIntegration::dataCenter((string)$resolved['apiKey']) === null) {
            $problems[] = Craft::t('leads', 'That doesn’t look like a Mailchimp API key: it should end in a data centre such as “-us12”.');
        }

        return $problems;
    }

    /**
     * Where a webhook may be sent, or why it may not.
     *
     * Each anonymous submission is POSTed to this URL, so an unchecked one let anyone who manages
     * popups point the server at the cloud metadata service or the private network. So: `http`/
     * `https` only, no credentials, every resolved address public ({@see Ip::resolvePublic()}),
     * and the caller pins the connection to those addresses and follows no redirects.
     * `allowPrivateWebhookHosts` (config file only) skips the address check.
     *
     * Pure — no Craft — so the integrations can use it in unit tests; the messages are English and
     * callers in a request translate them.
     *
     * @return array{host: string, port: int, addresses: string[]}|string The pinned target, or the refusal.
     */
    public static function webhookTarget(string $url, bool $allowPrivateHosts = false): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Only http:// and https:// webhook URLs are allowed.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Webhook URLs may not carry a username or password.';
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($allowPrivateHosts) {
            return ['host' => $host, 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Webhooks only go to public addresses.';
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    public function testConnection(string $provider, array $settings): array
    {
        $integration = $this->getIntegration($provider, $settings);

        if (!$integration) {
            return ['success' => false, 'message' => 'Unknown integration provider.'];
        }

        return $integration->testConnection();
    }

    public function getLists(string $provider, array $settings): array
    {
        $integration = $this->getIntegration($provider, $settings);

        if (!$integration) {
            return [];
        }

        return $integration->getLists();
    }
}
