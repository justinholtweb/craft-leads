<?php

namespace justinholtweb\leads\tests\unit;

use justinholtweb\leads\enums\SyncStatus;
use justinholtweb\leads\helpers\Optin;
use justinholtweb\leads\integrations\MailchimpIntegration;
use justinholtweb\leads\integrations\WebhookIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The consent checkbox's settings and wording, and the double opt-in token: its shape, its hash,
 * and what a lookup is allowed to see.
 */
final class OptinTest extends TestCase
{
    public function testNormalizeFillsEveryKeyAndCoercesTypes(): void
    {
        $this->assertSame(
            ['checkbox' => false, 'required' => false, 'text' => '', 'doubleOptIn' => 'off'],
            Optin::normalize(null),
        );

        $this->assertSame(
            ['checkbox' => true, 'required' => true, 'text' => "Yes\nplease", 'doubleOptIn' => 'email'],
            Optin::normalize(['checkbox' => '1', 'required' => '1', 'text' => "  Yes\r\nplease ", 'doubleOptIn' => 'email']),
        );
    }

    public function testARequiredBoxThatIsNotShownIsNotRequired(): void
    {
        $this->assertFalse(Optin::normalize(['checkbox' => '', 'required' => '1'])['required']);
    }

    public function testAnUnknownModeIsOff(): void
    {
        $this->assertSame('off', Optin::normalize(['doubleOptIn' => 'sometimes'])['doubleOptIn']);
        $this->assertSame('off', Optin::normalize(['doubleOptIn' => ['email']])['doubleOptIn']);
    }

    public function testProblems(): void
    {
        $this->assertSame([], Optin::problems(Optin::normalize([]), null));
        $this->assertCount(1, Optin::problems(Optin::normalize(['checkbox' => 1]), null), 'a checkbox needs wording');
        $this->assertCount(1, Optin::problems(Optin::normalize(['text' => str_repeat('a', 1001)]), null));

        $provider = Optin::normalize(['doubleOptIn' => 'provider']);
        $this->assertSame([], Optin::problems($provider, 'mailchimp'));
        $this->assertSame([], Optin::problems($provider, 'convertkit'));
        $this->assertCount(1, Optin::problems($provider, 'webhook'), 'a webhook has no pending state');
        $this->assertCount(1, Optin::problems($provider, null));

        // Leads' own email works whatever the integration, or with none.
        $this->assertSame([], Optin::problems(Optin::normalize(['doubleOptIn' => 'email']), null));
    }

    public function testVersionIsStableAcrossLineEndingsAndChangesWithTheWording(): void
    {
        $this->assertSame(Optin::version("a\nb"), Optin::version("a\r\nb "));
        $this->assertNotSame(Optin::version('I agree'), Optin::version('I agree.'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Optin::version('x'));
    }

    public function testHtmlEscapesEverythingButSafeLinks(): void
    {
        $this->assertSame(
            'I accept the <a href="/privacy" target="_blank" rel="noopener">privacy policy</a>.',
            Optin::html('I accept the [privacy policy](/privacy).'),
        );
        $this->assertStringContainsString('href="https://example.com/p?a=1&amp;b=2"', Optin::html('[p](https://example.com/p?a=1&b=2)'));

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', Optin::html('<script>alert(1)</script>'));
        $this->assertStringNotContainsString('<a', Optin::html('[x](javascript:alert(1))'));
        // A quote can't reach the attribute: it is already `&quot;`, which ends the URL match.
        $this->assertStringNotContainsString('<a', Optin::html('[x](/a" onmouseover="alert(1))'));
        $this->assertSame("a<br>\nb", Optin::html("a\nb"));
    }

    public function testAProtocolRelativeUrlIsNotTakenForASitePath(): void
    {
        $this->assertStringNotContainsString('<a', Optin::html('[x](//evil.example)'));
    }

    public function testTokensAreRandomUrlSafeAndExactlyShaped(): void
    {
        $a = Optin::newToken();
        $b = Optin::newToken();

        $this->assertNotSame($a, $b);
        $this->assertTrue(Optin::isTokenShaped($a));
        $this->assertSame(43, strlen($a));

        foreach (['', '*', 'not ' . substr($a, 4), $a . 'x', substr($a, 1), str_replace($a[0], '%', $a), null, 42, [$a]] as $bad) {
            $this->assertFalse(Optin::isTokenShaped($bad), var_export($bad, true));
        }
    }

    public function testOnlyTheHashIsStoredAndItIsDeterministic(): void
    {
        $token = Optin::newToken();

        $this->assertSame(Optin::hash($token), Optin::hash($token));
        $this->assertNotSame($token, Optin::hash($token));
        $this->assertSame(64, strlen(Optin::hash($token)));
    }

    public function testNewSyncStatuses(): void
    {
        $this->assertSame('unconfirmed', SyncStatus::Unconfirmed->value);
        $this->assertSame('none', SyncStatus::None->value);
        $this->assertSame('blue', SyncStatus::Unconfirmed->color());
    }

    public function testMailchimpGetsPendingOnlyWhenItIsToConfirm(): void
    {
        $mailchimp = new MailchimpIntegration(['apiKey' => 'x-us1', 'listId' => 'l']);
        $this->assertSame('subscribed', $mailchimp->memberPayload('a@example.com')['status']);

        $mailchimp->providerConfirms = true;
        $payload = $mailchimp->memberPayload('a@example.com', 'Ada Lovelace');
        $this->assertSame('pending', $payload['status']);
        $this->assertSame(['FNAME' => 'Ada', 'LNAME' => 'Lovelace'], $payload['merge_fields']);
    }

    public function testTheWebhookCarriesTheConsentRecordWhenThereIsOne(): void
    {
        $webhook = new WebhookIntegration(['webhookUrl' => 'https://example.com/h']);
        $this->assertArrayNotHasKey('consent', $webhook->payload('a@example.com'));

        $webhook->consent = ['given' => true, 'text' => 'I agree', 'confirmed_at' => '2026-10-09 10:00:00'];
        $this->assertSame($webhook->consent, $webhook->payload('a@example.com')['consent']);
    }
}
