<?php

namespace justinholtweb\leads\integrations;

use Craft;

class MailchimpIntegration extends AbstractIntegration
{
    public function sendSubscriber(string $email, ?string $name = null, array $customFields = []): bool
    {
        $apiKey = $this->settings['apiKey'] ?? '';
        $listId = $this->settings['listId'] ?? '';

        if (!$apiKey || !$listId) {
            return false;
        }

        $dc = self::dataCenter($apiKey);

        if ($dc === null) {
            return false;
        }

        $url = "https://{$dc}.api.mailchimp.com/3.0/lists/" . rawurlencode((string)$listId) . '/members';

        $mergeFields = [];
        if ($name) {
            $parts = explode(' ', $name, 2);
            $mergeFields['FNAME'] = $parts[0];
            if (isset($parts[1])) {
                $mergeFields['LNAME'] = $parts[1];
            }
        }

        $data = [
            'email_address' => $email,
            'status' => 'subscribed',
        ];

        if (!empty($mergeFields)) {
            $data['merge_fields'] = $mergeFields;
        }

        $response = $this->request($url, $data, $apiKey);

        return $response !== null && !isset($response['status']) || (isset($response['status']) && $response['status'] === 'subscribed');
    }

    public function testConnection(): array
    {
        $apiKey = $this->settings['apiKey'] ?? '';

        if (!$apiKey) {
            return ['success' => false, 'message' => 'API key is required.'];
        }

        $dc = self::dataCenter($apiKey);

        if ($dc === null) {
            return ['success' => false, 'message' => 'That doesn’t look like a Mailchimp API key.'];
        }

        $url = "https://{$dc}.api.mailchimp.com/3.0/ping";

        $response = $this->request($url, null, $apiKey, 'GET');

        if ($response && isset($response['health_status'])) {
            return ['success' => true, 'message' => 'Connected successfully.'];
        }

        return ['success' => false, 'message' => 'Could not connect to Mailchimp.'];
    }

    public function getLists(): array
    {
        $apiKey = $this->settings['apiKey'] ?? '';

        if (!$apiKey) {
            return [];
        }

        $dc = self::dataCenter($apiKey);

        if ($dc === null) {
            return [];
        }

        $url = "https://{$dc}.api.mailchimp.com/3.0/lists?count=100";

        $response = $this->request($url, null, $apiKey, 'GET');

        if (!$response || !isset($response['lists'])) {
            return [];
        }

        $lists = [];
        foreach ($response['lists'] as $list) {
            $lists[] = [
                'id' => $list['id'],
                'name' => $list['name'],
                'memberCount' => $list['stats']['member_count'] ?? 0,
            ];
        }

        return $lists;
    }

    /**
     * The data centre a Mailchimp key names (`…-us12` → `us12`), or null when it doesn't look like
     * one. It becomes part of the API's host name, so before 5.0.6 a key ending `-evil.example/#`
     * sent the key itself to another server.
     */
    public static function dataCenter(string $apiKey): ?string
    {
        $parts = explode('-', $apiKey);
        $dc = strtolower((string)end($parts));

        return count($parts) >= 2 && preg_match('/^[a-z]+\d+$/', $dc) ? $dc : null;
    }

    private function request(string $url, ?array $data, string $apiKey, string $method = 'POST'): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: apikey ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT' && $data) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            Craft::error('Mailchimp API request failed', 'leads');
            return null;
        }

        return json_decode($response, true);
    }
}
