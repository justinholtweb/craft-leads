<?php

namespace justinholtweb\leads\integrations;

use Craft;

class ConvertKitIntegration extends AbstractIntegration
{
    private const API_BASE = 'https://api.convertkit.com/v3';

    public function sendSubscriber(string $email, ?string $name = null, array $customFields = []): bool
    {
        $apiSecret = $this->settings['apiSecret'] ?? '';
        $formId = $this->settings['formId'] ?? '';

        if (!$apiSecret || !$formId) {
            return false;
        }

        $url = self::API_BASE . "/forms/{$formId}/subscribe";

        $data = [
            'api_secret' => $apiSecret,
            'email' => $email,
        ];

        if ($name) {
            $data['first_name'] = explode(' ', $name)[0];
        }

        $response = $this->request($url, $data);

        return $response !== null && isset($response['subscription']);
    }

    public function testConnection(): array
    {
        $apiSecret = $this->settings['apiSecret'] ?? '';

        if (!$apiSecret) {
            return ['success' => false, 'message' => 'API secret is required.'];
        }

        $url = self::API_BASE . '/account?api_secret=' . urlencode($apiSecret);
        $response = $this->request($url, null, 'GET');

        if ($response && isset($response['name'])) {
            return ['success' => true, 'message' => 'Connected as: ' . $response['name']];
        }

        return ['success' => false, 'message' => 'Could not connect to ConvertKit.'];
    }

    public function getLists(): array
    {
        $apiSecret = $this->settings['apiSecret'] ?? '';

        if (!$apiSecret) {
            return [];
        }

        $url = self::API_BASE . '/forms?api_secret=' . urlencode($apiSecret);
        $response = $this->request($url, null, 'GET');

        if (!$response || !isset($response['forms'])) {
            return [];
        }

        $lists = [];
        foreach ($response['forms'] as $form) {
            $lists[] = [
                'id' => $form['id'],
                'name' => $form['name'],
            ];
        }

        return $lists;
    }

    private function request(string $url, ?array $data, string $method = 'POST'): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            Craft::error('ConvertKit API request failed', 'leads');
            return null;
        }

        return json_decode($response, true);
    }
}
