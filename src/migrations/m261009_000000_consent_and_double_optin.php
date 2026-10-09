<?php

namespace justinholtweb\leads\migrations;

use craft\db\Migration;

/**
 * Consent checkbox and double opt-in: a popup's consent settings, and on each submission the
 * consent it carried and the state of its confirmation.
 */
class m261009_000000_consent_and_double_optin extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%leads_popups}}', 'consentSettings')) {
            $this->addColumn('{{%leads_popups}}', 'consentSettings', $this->json()->null());
        }

        foreach (Install::submissionConsentColumns($this) as $name => $type) {
            if (!$this->db->columnExists('{{%leads_submissions}}', $name)) {
                $this->addColumn('{{%leads_submissions}}', $name, $type);
            }
        }

        $this->createIndex(null, '{{%leads_submissions}}', ['confirmTokenHash'], true);
        $this->createIndex(null, '{{%leads_submissions}}', ['confirmExpiresAt']);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261009_000000_consent_and_double_optin cannot be reverted.\n";

        return false;
    }
}
