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

    public function defineRules(): array
    {
        return [
            [['defaultButtonColor', 'defaultBackgroundColor'], 'string', 'max' => 20],
            [['dataRetentionDays'], 'integer', 'min' => 30],
            [['rateLimitPerMinute'], 'integer', 'min' => 1, 'max' => 60],
            [['autoInjectScript', 'enableHoneypot'], 'boolean'],
        ];
    }
}
