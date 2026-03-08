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

        $dc = $this->getDataCenter($apiKey);
        $url = "https://{$dc}.api.mailchimp.com/3.0/lists/{$listId}/members";

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

        $dc = $this->getDataCenter($apiKey);
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

        $dc = $this->getDataCenter($apiKey);
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

    private function getDataCenter(string $apiKey): string
    {
        $parts = explode('-', $apiKey);
        return $parts[1] ?? 'us1';
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
