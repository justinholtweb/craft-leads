<?php

namespace justinholtweb\leads\integrations;

use Craft;

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

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
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

        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => 'Invalid webhook URL.'];
        }

        return ['success' => true, 'message' => 'Webhook URL is valid.'];
    }

    public function getLists(): array
    {
        return [];
    }
}
