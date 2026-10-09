<?php

namespace justinholtweb\leads\integrations;

abstract class AbstractIntegration
{
    protected array $settings;

    /** Whether a webhook may go to private and loopback addresses (`allowPrivateWebhookHosts`). */
    protected bool $allowPrivateHosts;

    /**
     * The popup's double opt-in is the provider's job: Mailchimp adds the member as `pending` and
     * emails them. ConvertKit's form confirms by its own setting either way.
     */
    public bool $providerConfirms = false;

    /**
     * The submission's consent record — `given`, `text`, `version`, `consented_at`,
     * `confirmed_at`, `double_opt_in` — for providers that can carry it. The webhook sends it.
     *
     * @var array<string, mixed>
     */
    public array $consent = [];

    public function __construct(array $settings, bool $allowPrivateHosts = false)
    {
        $this->settings = $settings;
        $this->allowPrivateHosts = $allowPrivateHosts;
    }

    abstract public function sendSubscriber(string $email, ?string $name = null, array $customFields = []): bool;

    abstract public function testConnection(): array;

    abstract public function getLists(): array;
}
