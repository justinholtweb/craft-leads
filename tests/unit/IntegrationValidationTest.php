<?php

namespace justinholtweb\leads\tests\unit;

use justinholtweb\leads\integrations\ConvertKitIntegration;
use justinholtweb\leads\integrations\MailchimpIntegration;
use justinholtweb\leads\integrations\WebhookIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the integration validation paths that run BEFORE any
 * network call — credential guards and webhook URL validation.
 *
 * These are the branches a misconfigured popup hits, so they must fail
 * closed: never attempt a request, never report a false "connected".
 * The actual HTTP calls are not exercised here (no live API keys).
 */
final class IntegrationValidationTest extends TestCase
{
    // ---- Webhook ------------------------------------------------------------

    public function testWebhookTestConnectionRequiresUrl(): void
    {
        $result = (new WebhookIntegration([]))->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('Webhook URL is required.', $result['message']);
    }

    public function testWebhookTestConnectionRejectsMalformedUrl(): void
    {
        $result = (new WebhookIntegration(['webhookUrl' => 'not-a-url']))->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid webhook URL.', $result['message']);
    }

    public function testWebhookTestConnectionAcceptsValidUrl(): void
    {
        $result = (new WebhookIntegration(['webhookUrl' => 'https://example.com/hook']))->testConnection();

        $this->assertTrue($result['success']);
        $this->assertSame('Webhook URL is valid.', $result['message']);
    }

    public function testWebhookSendSubscriberFailsClosedWithoutUrl(): void
    {
        $this->assertFalse((new WebhookIntegration([]))->sendSubscriber('a@example.com'));
    }

    public function testWebhookHasNoLists(): void
    {
        $this->assertSame([], (new WebhookIntegration(['webhookUrl' => 'https://example.com']))->getLists());
    }

    // ---- Mailchimp ----------------------------------------------------------

    public function testMailchimpTestConnectionRequiresApiKey(): void
    {
        $result = (new MailchimpIntegration([]))->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('API key is required.', $result['message']);
    }

    public function testMailchimpSendSubscriberFailsClosedWithoutCredentials(): void
    {
        // Missing both key and list.
        $this->assertFalse((new MailchimpIntegration([]))->sendSubscriber('a@example.com'));
        // Key present, list missing -> still fails closed.
        $this->assertFalse(
            (new MailchimpIntegration(['apiKey' => 'abc-us1']))->sendSubscriber('a@example.com'),
        );
    }

    public function testMailchimpGetListsEmptyWithoutApiKey(): void
    {
        $this->assertSame([], (new MailchimpIntegration([]))->getLists());
    }

    // ---- ConvertKit ---------------------------------------------------------

    public function testConvertKitTestConnectionRequiresApiSecret(): void
    {
        $result = (new ConvertKitIntegration([]))->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('API secret is required.', $result['message']);
    }

    public function testConvertKitSendSubscriberFailsClosedWithoutCredentials(): void
    {
        $this->assertFalse((new ConvertKitIntegration([]))->sendSubscriber('a@example.com'));
        // Secret present, form missing -> still fails closed.
        $this->assertFalse(
            (new ConvertKitIntegration(['apiSecret' => 'secret']))->sendSubscriber('a@example.com'),
        );
    }

    public function testConvertKitGetListsEmptyWithoutApiSecret(): void
    {
        $this->assertSame([], (new ConvertKitIntegration([]))->getLists());
    }
}
