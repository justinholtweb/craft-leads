<?php

namespace justinholtweb\leads\migrations;

use craft\db\Migration;

/**
 * An exit-intent popup's trigger value is now its fallback on phones and tablets, in seconds.
 * It meant nothing before, and usually still held the time delay the popup was created with, so
 * it's cleared: no existing popup starts showing on a timer.
 */
class m261103_000000_exit_intent_fallback extends Migration
{
    public function safeUp(): bool
    {
        $this->update('{{%leads_popups}}', ['triggerValue' => null], ['triggerType' => 'exit'], [], false);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
