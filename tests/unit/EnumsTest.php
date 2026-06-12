<?php

namespace justinholtweb\leads\tests\unit;

use justinholtweb\leads\enums\IntegrationProvider;
use justinholtweb\leads\enums\PopupPosition;
use justinholtweb\leads\enums\PopupStatus;
use justinholtweb\leads\enums\PopupType;
use justinholtweb\leads\enums\SyncStatus;
use justinholtweb\leads\enums\TriggerType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backed string enums.
 *
 * These guard the contract the rest of the plugin relies on: the stored
 * string values (persisted in the database and project config), and the
 * label()/color() mappings that the element index and CP templates render.
 * A renamed case value would silently break existing rows, so the exact
 * string values are pinned here.
 */
final class EnumsTest extends TestCase
{
    // ---- PopupStatus --------------------------------------------------------

    public function testPopupStatusValues(): void
    {
        $this->assertSame('draft', PopupStatus::Draft->value);
        $this->assertSame('active', PopupStatus::Active->value);
        $this->assertSame('paused', PopupStatus::Paused->value);
        $this->assertSame('archived', PopupStatus::Archived->value);
    }

    public function testPopupStatusLabels(): void
    {
        $this->assertSame('Draft', PopupStatus::Draft->label());
        $this->assertSame('Active', PopupStatus::Active->label());
        $this->assertSame('Paused', PopupStatus::Paused->label());
        $this->assertSame('Archived', PopupStatus::Archived->label());
    }

    public function testPopupStatusColors(): void
    {
        // Colors map to Craft status badge classes.
        $this->assertSame('white', PopupStatus::Draft->color());
        $this->assertSame('green', PopupStatus::Active->color());
        $this->assertSame('orange', PopupStatus::Paused->color());
        $this->assertSame('red', PopupStatus::Archived->color());
    }

    public function testPopupStatusFromStoredValueRoundTrips(): void
    {
        foreach (PopupStatus::cases() as $case) {
            $this->assertSame($case, PopupStatus::from($case->value));
        }
    }

    public function testPopupStatusRejectsUnknownValue(): void
    {
        $this->assertNull(PopupStatus::tryFrom('bogus'));
    }

    // ---- PopupType ----------------------------------------------------------

    public function testPopupTypeValuesMatchElementValidationRange(): void
    {
        // Popup::defineRules() pins this exact range — keep them in lockstep.
        $values = array_map(fn(PopupType $t) => $t->value, PopupType::cases());
        $this->assertSame(['modal', 'slidein', 'bar', 'inline'], $values);
    }

    public function testPopupTypeLabels(): void
    {
        $this->assertSame('Modal', PopupType::Modal->label());
        $this->assertSame('Slide-in', PopupType::SlideIn->label());
        $this->assertSame('Notification Bar', PopupType::Bar->label());
        $this->assertSame('Inline', PopupType::Inline->label());
    }

    // ---- TriggerType --------------------------------------------------------

    public function testTriggerTypeValuesMatchElementValidationRange(): void
    {
        $values = array_map(fn(TriggerType $t) => $t->value, TriggerType::cases());
        $this->assertSame(['time', 'scroll', 'exit', 'click'], $values);
    }

    public function testTriggerTypeLabels(): void
    {
        $this->assertSame('Time Delay', TriggerType::Time->label());
        $this->assertSame('Scroll Percentage', TriggerType::Scroll->label());
        $this->assertSame('Exit Intent', TriggerType::Exit->label());
        $this->assertSame('Click', TriggerType::Click->label());
    }

    // ---- PopupPosition ------------------------------------------------------

    public function testPopupPositionValues(): void
    {
        $values = array_map(fn(PopupPosition $p) => $p->value, PopupPosition::cases());
        $this->assertSame(['top', 'bottom', 'bottom-right', 'bottom-left'], $values);
    }

    public function testPopupPositionLabels(): void
    {
        $this->assertSame('Top', PopupPosition::Top->label());
        $this->assertSame('Bottom', PopupPosition::Bottom->label());
        $this->assertSame('Bottom Right', PopupPosition::BottomRight->label());
        $this->assertSame('Bottom Left', PopupPosition::BottomLeft->label());
    }

    // ---- IntegrationProvider ------------------------------------------------

    public function testIntegrationProviderValues(): void
    {
        $values = array_map(fn(IntegrationProvider $p) => $p->value, IntegrationProvider::cases());
        $this->assertSame(['mailchimp', 'convertkit', 'webhook'], $values);
    }

    public function testIntegrationProviderLabels(): void
    {
        $this->assertSame('Mailchimp', IntegrationProvider::Mailchimp->label());
        $this->assertSame('ConvertKit', IntegrationProvider::ConvertKit->label());
        $this->assertSame('Webhook', IntegrationProvider::Webhook->label());
    }

    // ---- SyncStatus ---------------------------------------------------------

    public function testSyncStatusValues(): void
    {
        $this->assertSame('pending', SyncStatus::Pending->value);
        $this->assertSame('synced', SyncStatus::Synced->value);
        $this->assertSame('failed', SyncStatus::Failed->value);
    }

    public function testSyncStatusColors(): void
    {
        $this->assertSame('orange', SyncStatus::Pending->color());
        $this->assertSame('green', SyncStatus::Synced->color());
        $this->assertSame('red', SyncStatus::Failed->color());
    }
}
