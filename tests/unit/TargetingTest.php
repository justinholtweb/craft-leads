<?php

namespace justinholtweb\leads\tests\unit;

use justinholtweb\leads\helpers\Targeting;
use PHPUnit\Framework\TestCase;

/**
 * The server's half of targeting: reading what the editor posts, refusing what the page script
 * couldn't follow, matching page patterns, and what goes to the browser.
 */
final class TargetingTest extends TestCase
{
    public function testNoRulesAreTheDefaults(): void
    {
        $this->assertSame(Targeting::DEFAULTS, Targeting::normalize(null));
        $this->assertSame([], Targeting::problems(Targeting::normalize([])));
    }

    /**
     * Before 5.1.0 the only rule was `pages`, a list of include patterns. It still means that.
     */
    public function testStoredRulesFromBefore510StillWork(): void
    {
        $rules = Targeting::normalize(['pages' => ['/blog/*']]);

        $this->assertSame(['/blog/*'], $rules['pages']);
        $this->assertTrue(Targeting::matchesPage($rules, '/blog/post'));
        $this->assertFalse(Targeting::matchesPage($rules, '/shop'));
    }

    public function testTheEditorsTextAndCheckboxesAreNormalized(): void
    {
        $rules = Targeting::normalize([
            'pages' => " /blog/*\r\n\n/news/* \n/blog/*",
            'excludePages' => '',
            'devices' => ['', 'mobile', 'tablet'],
            'frequency' => 'days',
            'frequencyDays' => '14',
            'minPageViews' => '3',
            'visitor' => 'returning',
            'hideAfterConversion' => '',
            'dismissDays' => '0',
        ]);

        $this->assertSame(['/blog/*', '/news/*'], $rules['pages']);
        $this->assertSame([], $rules['excludePages']);
        $this->assertSame(['mobile', 'tablet'], $rules['devices']);
        $this->assertSame(14, $rules['frequencyDays']);
        $this->assertSame(3, $rules['minPageViews']);
        $this->assertSame(0, $rules['dismissDays']);
        $this->assertFalse($rules['hideAfterConversion']);
        $this->assertSame([], Targeting::problems($rules));
    }

    /**
     * Rules are normalized on the way in and again every time they're read — including by
     * validation — so normalizing twice must change nothing. (A stored "any device" once read back
     * as "no device ticked" and the popup wouldn't save.)
     */
    public function testNormalizingTwiceChangesNothing(): void
    {
        foreach ([[], ['devices' => ['mobile']], ['devices' => ['', 'desktop', 'tablet', 'mobile'], 'pages' => "/a\n/b", 'frequency' => 'days', 'frequencyDays' => '3']] as $posted) {
            $once = Targeting::normalize($posted);
            $this->assertSame($once, Targeting::normalize($once));
            $this->assertSame([], Targeting::problems(Targeting::normalize($once)));
        }
    }

    public function testEveryDeviceTickedIsStoredAsNoDeviceRule(): void
    {
        $this->assertSame([], Targeting::normalize(['devices' => ['', 'desktop', 'tablet', 'mobile']])['devices']);
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testWhatThePageScriptCouldntFollowIsRefused(array $posted): void
    {
        $this->assertNotSame([], Targeting::problems(Targeting::normalize($posted)));
    }

    public static function refusedProvider(): array
    {
        return [
            'no device ticked' => [['devices' => '']],
            'an unknown device' => [['devices' => ['desktop', 'fridge']]],
            'an unknown frequency' => [['frequency' => 'hourly']],
            'zero days between showings' => [['frequency' => 'days', 'frequencyDays' => '0']],
            'fractional days' => [['frequency' => 'days', 'frequencyDays' => '1.5']],
            'negative page views' => [['minPageViews' => '-1']],
            'words for page views' => [['minPageViews' => 'three']],
            'an unknown visitor' => [['visitor' => 'robots']],
            'negative close pause' => [['dismissDays' => '-2']],
            'a very long pattern' => [['pages' => '/' . str_repeat('a', 300)]],
            'too many patterns' => [['excludePages' => array_map(static fn($i) => "/p$i", range(1, 101))]],
        ];
    }

    public function testExcludesWinAndPatternsSeeThePathAndTheQueryString(): void
    {
        $rules = Targeting::normalize(['pages' => "/blog/*\n/shop?ref=*", 'excludePages' => '/blog/private*']);

        $this->assertTrue(Targeting::matchesPage($rules, '/blog/hello?utm_source=x'), 'path match ignores the query string');
        $this->assertFalse(Targeting::matchesPage($rules, '/blog/private-post'));
        $this->assertTrue(Targeting::matchesPage($rules, '/shop?ref=mail'), 'a pattern may name a query string');
        $this->assertFalse(Targeting::matchesPage($rules, '/shop'));

        $exclusionsOnly = Targeting::normalize(['excludePages' => '/checkout*']);
        $this->assertTrue(Targeting::matchesPage($exclusionsOnly, '/'));
        $this->assertFalse(Targeting::matchesPage($exclusionsOnly, '/checkout/pay'));
    }

    public function testTheBrowserGetsEverythingButThePages(): void
    {
        $client = Targeting::forClient(Targeting::normalize(['pages' => ['/a'], 'devices' => ['mobile'], 'minPageViews' => 2]));

        $this->assertArrayNotHasKey('pages', $client);
        $this->assertArrayNotHasKey('excludePages', $client);
        $this->assertSame(['mobile'], $client['devices']);
        $this->assertSame(2, $client['minPageViews']);
        $this->assertTrue(Targeting::countsVisits(Targeting::normalize(['minPageViews' => 2])));
        $this->assertTrue(Targeting::countsVisits(Targeting::normalize(['visitor' => 'new'])));
        $this->assertFalse(Targeting::countsVisits(Targeting::normalize(['frequency' => 'once'])));
    }
}
