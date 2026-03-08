<?php

namespace justinholtweb\leads\enums;

enum IntegrationProvider: string
{
    case Mailchimp = 'mailchimp';
    case ConvertKit = 'convertkit';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Mailchimp => 'Mailchimp',
            self::ConvertKit => 'ConvertKit',
            self::Webhook => 'Webhook',
        };
    }
}
