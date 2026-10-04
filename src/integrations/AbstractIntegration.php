<?php

namespace justinholtweb\leads\integrations;

abstract class AbstractIntegration
{
    protected array $settings;

    /** Whether a webhook may go to private and loopback addresses (`allowPrivateWebhookHosts`). */
    protected bool $allowPrivateHosts;

    public function __construct(array $settings, bool $allowPrivateHosts = false)
    {
        $this->settings = $settings;
        $this->allowPrivateHosts = $allowPrivateHosts;
    }

    abstract public function sendSubscriber(string $email, ?string $name = null, array $customFields = []): bool;

    abstract public function testConnection(): array;

    abstract public function getLists(): array;
}
