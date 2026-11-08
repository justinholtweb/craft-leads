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
            'a source that is neither' => [['sources' => 'newsletter']],
            'a non-UTM parameter' => [['sources' => 'ref=partner']],
            'a host with a path' => [['sources' => 'google.com/search']],
            'too many sources' => [['sources' => array_map(static fn($i) => "s$i.com", range(1, 51))]],
            'a start that isn’t a date' => [['startDate' => 'next tuesday']],
            'an end before the start' => [['startDate' => '2026-11-27T00:00:00Z', 'endDate' => '2026-11-26T00:00:00Z']],
            'an end at the start' => [['startDate' => '2026-11-27T00:00:00Z', 'endDate' => '2026-11-27T00:00:00Z']],
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

    public function testTrafficSources(): void
    {
        $rules = Targeting::normalize(['sources' => " UTM_Campaign=Spring-Sale\r\nGoogle.com\n*.facebook.com\nutm_source=*\ngoogle.com"]);

        $this->assertSame(['utm_campaign=Spring-Sale', 'google.com', '*.facebook.com', 'utm_source=*'], $rules['sources'], 'names lower-cased, values kept, duplicates dropped');
        $this->assertSame([], Targeting::problems($rules));
        $this->assertSame($rules['sources'], Targeting::forClient($rules)['sources']);
        $this->assertTrue(Targeting::countsVisits($rules), 'the script has to see the landing page');
    }

    public function testScheduleDates(): void
    {
        $rules = Targeting::normalize(['startDate' => '2026-11-27T00:00:00-05:00', 'endDate' => '2026-12-01T05:00:00Z']);

        $this->assertSame('2026-11-27T05:00:00Z', $rules['startDate'], 'stored in UTC');
        $this->assertSame([], Targeting::problems($rules));
        $this->assertSame($rules, Targeting::normalize($rules), 'normalizing twice changes nothing');

        $start = strtotime('2026-11-27T05:00:00Z');
        $end = strtotime('2026-12-01T05:00:00Z');
        $this->assertFalse(Targeting::inSchedule($rules, $start - 1));
        $this->assertTrue(Targeting::inSchedule($rules, $start));
        $this->assertTrue(Targeting::inSchedule($rules, $end - 1));
        $this->assertFalse(Targeting::inSchedule($rules, $end));
        $this->assertSame('scheduled', Targeting::scheduleState($rules, $start - 1));
        $this->assertNull(Targeting::scheduleState($rules, $start));
        $this->assertSame('ended', Targeting::scheduleState($rules, $end));

        $client = Targeting::forClient($rules);
        $this->assertSame($start * 1000, $client['start']);
        $this->assertSame($end * 1000, $client['end']);

        $open = Targeting::normalize(['startDate' => '', 'endDate' => null]);
        $this->assertTrue(Targeting::inSchedule($open, 0));
        $this->assertNull(Targeting::forClient($open)['end']);
        $this->assertNull(Targeting::scheduleState(Targeting::normalize([]), time()));
    }

    /**
     * Audience and cart rules as the editor posts them: a select, group checkboxes, number inputs
     * and two element selects sharing one name.
     */
    public function testAudienceAndCartRulesFromTheEditor(): void
    {
        $rules = Targeting::normalize([
            'audience' => 'all',
            'userGroups' => ['', 'wholesale', 'wholesale'],
            'cart' => 'notEmpty',
            'cartMin' => '',
            'cartBelow' => '50',
            'cartCategories' => ['', '12', '7', '12'],
        ]);

        $this->assertSame('users', $rules['audience'], 'naming a group is naming signed-in users');
        $this->assertSame(['wholesale'], $rules['userGroups']);
        $this->assertNull($rules['cartMin']);
        $this->assertSame(50.0, $rules['cartBelow']);
        $this->assertSame([12, 7], $rules['cartCategories']);
        $this->assertSame([], Targeting::problems($rules));
        $this->assertSame($rules, Targeting::normalize($rules), 'normalizing twice changes nothing');

        $client = Targeting::forClient($rules);
        $this->assertSame(['user', 'cart'], $client['needs']);
        $this->assertSame(['wholesale'], $client['groups']);
        $this->assertSame([12, 7], $client['cartIn']);
        $this->assertSame(50.0, $client['cartBelow']);
    }

    /** No audience or cart rule: the page asks the server nothing. */
    public function testWithoutThoseRulesNothingIsAsked(): void
    {
        $this->assertSame([], Targeting::needs(Targeting::normalize([])));
        $this->assertSame([], Targeting::forClient(Targeting::normalize(['devices' => ['mobile']]))['needs']);
        $this->assertSame(['user'], Targeting::needs(Targeting::normalize(['audience' => 'guests'])));
        $this->assertSame(['cart'], Targeting::needs(Targeting::normalize(['cartMin' => '10'])));
    }

    /**
     * @dataProvider impossibleAudienceAndCartRules
     * @param array<string, mixed> $posted
     */
    public function testImpossibleAudienceAndCartRulesAreRefused(array $posted, string $says): void
    {
        $problems = Targeting::problems(Targeting::normalize($posted));

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString($says, implode(' ', $problems));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function impossibleAudienceAndCartRules(): array
    {
        return [
            'guests in a group' => [['audience' => 'guests', 'userGroups' => ['vip']], 'Guests aren’t in any user group'],
            'an unknown audience' => [['audience' => 'robots'], 'Signed in must be'],
            'a group that is not a handle' => [['userGroups' => ['VIP members']], 'handles'],
            'an unknown cart state' => [['cart' => 'full'], 'cart rule'],
            'a subtotal that is not a number' => [['cartMin' => 'ten'], 'Subtotal at least'],
            'a negative threshold' => [['cartBelow' => '-5'], 'Subtotal below'],
            'below at or under the minimum' => [['cartMin' => '50', 'cartBelow' => '50'], 'has to be more than'],
            'an empty cart holding a product' => [['cart' => 'empty', 'cartCategories' => ['3']], 'empty cart'],
            'an empty cart with a minimum' => [['cart' => 'empty', 'cartMin' => '1'], 'empty cart'],
        ];
    }
}
