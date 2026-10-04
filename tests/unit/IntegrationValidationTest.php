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
        $this->assertSame('Only http:// and https:// webhook URLs are allowed.', $result['message']);
    }

    /**
     * The webhook integration POSTs anonymous submissions to this URL. Before 5.0.6 any URL was
     * accepted, so it could reach the cloud metadata service or the private network.
     */
    public function testWebhookRefusesPrivateLoopbackAndMetadataAddresses(): void
    {
        foreach (['http://127.0.0.1/hook', 'http://169.254.169.254/latest/meta-data/', 'https://10.0.0.5/x', 'http://[::ffff:127.0.0.1]/x', 'https://user:pass@example.com/x', 'ftp://example.com/x'] as $url) {
            $result = (new WebhookIntegration(['webhookUrl' => $url]))->testConnection();
            $this->assertFalse($result['success'], $url);
        }

        // …unless the site has said private hosts are fine.
        $this->assertTrue((new WebhookIntegration(['webhookUrl' => 'http://10.0.0.5/x'], true))->testConnection()['success']);
    }

    public function testWebhookSendRefusesAPrivateAddressWithoutTryingIt(): void
    {
        // Port 9 on loopback answers nothing; refusing up front returns at once.
        $start = microtime(true);
        $this->assertFalse((new WebhookIntegration(['webhookUrl' => 'http://127.0.0.1:9/x']))->sendSubscriber('a@example.com'));
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    /**
     * The data centre in a Mailchimp key becomes part of the API host name. Before 5.0.6 a key
     * ending `-evil.example/#` sent the key to another server.
     */
    public function testMailchimpDataCentreMustLookLikeOne(): void
    {
        $this->assertSame('us12', MailchimpIntegration::dataCenter('0123abcd-us12'));
        foreach (['0123abcd-evil.example/#', '0123abcd-us12.evil.example', '0123abcd', '0123abcd-', 'x-us1:8080'] as $key) {
            $this->assertNull(MailchimpIntegration::dataCenter($key), $key);
        }

        $result = (new MailchimpIntegration(['apiKey' => 'abc-evil.example/#']))->testConnection();
        $this->assertFalse($result['success']);
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
