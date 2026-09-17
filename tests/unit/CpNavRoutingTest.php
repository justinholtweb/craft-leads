<?php

namespace justinholtweb\leads\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the control-panel nav ↔ route registration.
 *
 * `BasePlugin::getCpNavItem()` defaults the top-level nav item's URL to the plugin
 * handle, and the bare `leads` path originally had no CP route rule — so clicking
 * "Leads" in the sidebar 404'd while every subnav child resolved fine. These
 * assertions pin every URL the nav can emit to a registered route, so a new subnav
 * entry added without its route (or a route renamed out from under the nav) fails
 * here instead of in a customer's CP.
 *
 * Plugin.php is inspected as source rather than executed: registering the rules
 * requires a booted Craft application, which this pure-unit suite does not have.
 */
final class CpNavRoutingTest extends TestCase
{
    private static function pluginSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
        self::assertIsString($source, 'Could not read src/Plugin.php.');

        return $source;
    }

    private static function sectionBetween(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertNotFalse($start, "Could not locate {$signature} in src/Plugin.php.");

        // Stop at whichever declaration comes first, so the slice is this method alone —
        // over-capturing into a later method would let its strings satisfy these assertions.
        $after = $start + strlen($signature);
        $ends = [];
        foreach (['private', 'protected', 'public'] as $visibility) {
            $next = strpos($source, "\n    {$visibility} function ", $after);
            if ($next !== false) {
                $ends[] = $next;
            }
        }
        self::assertNotEmpty($ends, "Could not find the end of {$signature}.");

        return substr($source, $start, min($ends) - $start);
    }

    /** @return list<string> */
    private static function cpRouteRules(): array
    {
        $section = self::sectionBetween(self::pluginSource(), 'private function registerCpRoutes()');
        preg_match_all("/\\\$event->rules\['([^']+)'\]/", $section, $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private static function navUrls(): array
    {
        $section = self::sectionBetween(self::pluginSource(), 'public function getCpNavItem()');
        preg_match_all("/'url' => '([^']+)'/", $section, $matches);

        return $matches[1];
    }

    public function testBareHandlePathIsRouted(): void
    {
        $this->assertContains(
            'leads',
            self::cpRouteRules(),
            'The bare `leads` path must be routed — it is where getCpNavItem() sends bookmarks and typed URLs.',
        );
    }

    public function testEverySubnavUrlHasACpRoute(): void
    {
        $rules = self::cpRouteRules();
        $urls = self::navUrls();

        $this->assertNotEmpty($urls, 'Expected getCpNavItem() to declare subnav URLs.');

        foreach ($urls as $url) {
            $this->assertContains(
                $url,
                $rules,
                "Subnav URL `{$url}` has no matching CP route rule — clicking it would 404.",
            );
        }
    }

    public function testTopLevelNavItemDoesNotKeepTheInheritedBareUrl(): void
    {
        $section = self::sectionBetween(self::pluginSource(), 'public function getCpNavItem()');

        $this->assertMatchesRegularExpression(
            '/\$nav\[\'url\'\]\s*=/',
            $section,
            "getCpNavItem() must set \$nav['url'] rather than inherit the bare plugin handle.",
        );
    }

    /**
     * Each subnav gate must mirror the `requirePermission()` its controller's index
     * action calls, or the nav offers a link that answers 403.
     */
    public function testSubnavGatesMatchControllerPermissions(): void
    {
        $section = self::sectionBetween(self::pluginSource(), 'public function getCpNavItem()');

        $expected = [
            'dashboard' => 'leads:viewDashboard',
            'popups' => 'leads:accessPlugin',
            'submissions' => 'leads:viewSubmissions',
        ];

        foreach ($expected as $key => $permission) {
            $gateStart = strpos($section, "\$nav['subnav']['{$key}']");
            $this->assertNotFalse($gateStart, "Expected a `{$key}` subnav entry.");

            $gate = substr($section, 0, $gateStart);
            $gate = substr($gate, (int)strrpos($gate, 'if ('));

            $this->assertStringContainsString(
                $permission,
                $gate,
                "The `{$key}` subnav entry must be gated on {$permission}, matching its controller.",
            );
            $this->assertSame(
                1,
                substr_count($gate, 'checkPermission('),
                "The `{$key}` subnav gate must check exactly {$permission} — a fallback permission lets it offer a 403.",
            );
        }
    }
}
