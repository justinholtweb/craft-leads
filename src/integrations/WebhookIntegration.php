<?php

namespace justinholtweb\leads\integrations;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\leads\services\Integrations;

class WebhookIntegration extends AbstractIntegration
{
    public function sendSubscriber(string $email, ?string $name = null, array $customFields = []): bool
    {
        $webhookUrl = $this->settings['webhookUrl'] ?? '';

        if (!$webhookUrl) {
            return false;
        }

        $data = [
            'email' => $email,
            'name' => $name,
            'custom_fields' => $customFields,
            'timestamp' => date('c'),
        ];

        $target = Integrations::webhookTarget((string)$webhookUrl, $this->allowPrivateHosts);

        if (is_string($target)) {
            // Guarded: the integrations are also exercised without Craft, in unit tests.
            if (class_exists(Craft::class)) {
                Craft::warning("Leads did not send a webhook to {$webhookUrl}: {$target}", 'leads');
            }

            return false;
        }

        $options = [
            'json' => $data,
            'timeout' => 10,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => false,
            'curl' => [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS],
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, so every checked address is pinned and DNS can't be swapped
            // between the check and the connection.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            // A client on the curl handler alone: Guzzle's default stack can hand a request to PHP's
            // stream wrapper, which ignores every curl option, the address pin included.
            $response = (new Client(['handler' => HandlerStack::create(new CurlHandler())]))->post((string)$webhookUrl, $options);
            $httpCode = $response->getStatusCode();
        } catch (\Throwable $e) {
            Craft::error("Webhook request failed: {$e->getMessage()}", 'leads');

            return false;
        }

        if ($httpCode >= 300) {
            Craft::error("Webhook request failed: HTTP {$httpCode}", 'leads');
            return false;
        }

        return true;
    }

    public function testConnection(): array
    {
        $webhookUrl = $this->settings['webhookUrl'] ?? '';

        if (!$webhookUrl) {
            return ['success' => false, 'message' => 'Webhook URL is required.'];
        }

        $target = Integrations::webhookTarget((string)$webhookUrl, $this->allowPrivateHosts);

        if (is_string($target)) {
            return ['success' => false, 'message' => $target];
        }

        return ['success' => true, 'message' => 'Webhook URL is valid.'];
    }

    public function getLists(): array
    {
        return [];
    }
}
