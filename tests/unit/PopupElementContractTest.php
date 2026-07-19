<?php

namespace justinholtweb\leads\tests\unit;

use craft\base\Element;
use justinholtweb\leads\elements\Popup;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Contract tests for the Popup element's custom index-column rendering.
 *
 * Craft 5 renamed the element list-column hook from the Craft 4
 * `tableAttributeHtml()` to `attributeHtml()`. A method still named
 * `tableAttributeHtml()` is silently ignored by Craft 5 — the custom
 * status badge, type and trigger labels never render, and the
 * `default => parent::tableAttributeHtml()` arm fatals on the removed
 * base method. These assertions pin the current hook so a regression
 * (or upstream rename) fails loudly here instead of in the CP.
 */
final class PopupElementContractTest extends TestCase
{
    public function testPopupOverridesCraft5AttributeHtmlHook(): void
    {
        $this->assertTrue(
            method_exists(Popup::class, 'attributeHtml'),
            'Popup must override attributeHtml() — the Craft 5 index-column hook.',
        );

        $method = new ReflectionMethod(Popup::class, 'attributeHtml');
        $this->assertSame(
            Popup::class,
            $method->getDeclaringClass()->getName(),
            'attributeHtml() must be declared on Popup, not merely inherited.',
        );
    }

    public function testPopupDoesNotUseTheRemovedCraft4Hook(): void
    {
        $method = new ReflectionMethod(Popup::class, 'attributeHtml');

        // The obsolete Craft 4 hook must not be reintroduced on the element.
        if (method_exists(Popup::class, 'tableAttributeHtml')) {
            $obsolete = new ReflectionMethod(Popup::class, 'tableAttributeHtml');
            $this->assertNotSame(
                Popup::class,
                $obsolete->getDeclaringClass()->getName(),
                'Popup must not declare the removed Craft 4 tableAttributeHtml() hook.',
            );
        }

        $this->assertTrue($method->isProtected(), 'attributeHtml() must stay protected.');

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('string', $returnType->getName());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('attribute', $params[0]->getName());
    }

    public function testBaseElementDefinesTheHookWeOverride(): void
    {
        // Guards against upstream drift: if Craft renames the hook again, the
        // override becomes dead code — catch that here rather than in the CP.
        $this->assertTrue(
            method_exists(Element::class, 'attributeHtml'),
            'craft\\base\\Element is expected to define attributeHtml().',
        );
        $this->assertFalse(
            method_exists(Element::class, 'tableAttributeHtml'),
            'craft\\base\\Element no longer defines the Craft 4 tableAttributeHtml().',
        );
    }
}
