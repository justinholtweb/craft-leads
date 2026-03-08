<?php

namespace justinholtweb\leads\integrations;

abstract class AbstractIntegration
{
    protected array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    abstract public function sendSubscriber(string $email, ?string $name = null, array $customFields = []): bool;

    abstract public function testConnection(): array;

    abstract public function getLists(): array;
}
