<?php

namespace justinholtweb\leads\services;

use craft\base\Component;
use justinholtweb\leads\integrations\AbstractIntegration;
use justinholtweb\leads\integrations\ConvertKitIntegration;
use justinholtweb\leads\integrations\MailchimpIntegration;
use justinholtweb\leads\integrations\WebhookIntegration;

class Integrations extends Component
{
    public function getIntegration(string $provider, array $settings): ?AbstractIntegration
    {
        return match ($provider) {
            'mailchimp' => new MailchimpIntegration($settings),
            'convertkit' => new ConvertKitIntegration($settings),
            'webhook' => new WebhookIntegration($settings),
            default => null,
        };
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
