<?php

namespace justinholtweb\leads\models;

use craft\base\Model;

class Settings extends Model
{
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

    public function defineRules(): array
    {
        return [
            [['defaultButtonColor', 'defaultBackgroundColor'], 'string', 'max' => 20],
            [['dataRetentionDays'], 'integer', 'min' => 0],
            [['rateLimitPerMinute'], 'integer', 'min' => 1, 'max' => 60],
            [['trackingPerMinute'], 'integer', 'min' => 1, 'max' => 600],
            [['autoInjectScript', 'enableHoneypot', 'allowPrivateWebhookHosts'], 'boolean'],
        ];
    }
}
