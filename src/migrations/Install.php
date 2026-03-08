<?php

namespace justinholtweb\leads\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%leads_stats}}');
        $this->dropTableIfExists('{{%leads_submissions}}');
        $this->dropTableIfExists('{{%leads_popups}}');

        return true;
    }

    private function createTables(): void
    {
        // Popups (Element table)
        $this->createTable('{{%leads_popups}}', [
            'id' => $this->integer()->notNull(),
            'popupType' => $this->string(20)->notNull()->defaultValue('modal'),
            'triggerType' => $this->string(20)->notNull()->defaultValue('time'),
            'triggerValue' => $this->string(255)->null(),
            'templateKey' => $this->string(100)->null(),
            'heading' => $this->string(255)->null(),
            'bodyText' => $this->text()->null(),
            'buttonText' => $this->string(255)->null(),
            'buttonColor' => $this->string(20)->null(),
            'backgroundColor' => $this->string(20)->null(),
            'backgroundImage' => $this->string(500)->null(),
            'customCss' => $this->text()->null(),
            'formFields' => $this->json()->null(),
            'targetingRules' => $this->json()->null(),
            'integrationProvider' => $this->string(50)->null(),
            'integrationSettings' => $this->json()->null(),
            'position' => $this->string(50)->null(),
            'popupStatus' => $this->string(20)->notNull()->defaultValue('draft'),
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // Submissions
        $this->createTable('{{%leads_submissions}}', [
            'id' => $this->primaryKey(),
            'popupId' => $this->integer()->notNull(),
            'email' => $this->string(255)->notNull(),
            'name' => $this->string(255)->null(),
            'customFields' => $this->json()->null(),
            'ipAddress' => $this->string(45)->null(),
            'userAgent' => $this->string(500)->null(),
            'pageUrl' => $this->string(500)->notNull(),
            'syncStatus' => $this->string(20)->notNull()->defaultValue('pending'),
            'syncedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Stats (daily aggregation)
        $this->createTable('{{%leads_stats}}', [
            'id' => $this->primaryKey(),
            'popupId' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'impressions' => $this->integer()->notNull()->defaultValue(0),
            'conversions' => $this->integer()->notNull()->defaultValue(0),
            'closes' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // Popups
        $this->createIndex(null, '{{%leads_popups}}', ['popupStatus']);
        $this->createIndex(null, '{{%leads_popups}}', ['popupType']);
        $this->createIndex(null, '{{%leads_popups}}', ['priority']);

        // Submissions
        $this->createIndex(null, '{{%leads_submissions}}', ['popupId']);
        $this->createIndex(null, '{{%leads_submissions}}', ['email']);
        $this->createIndex(null, '{{%leads_submissions}}', ['syncStatus']);
        $this->createIndex(null, '{{%leads_submissions}}', ['dateCreated']);

        // Stats
        $this->createIndex(null, '{{%leads_stats}}', ['popupId', 'date'], true);
        $this->createIndex(null, '{{%leads_stats}}', ['date']);
    }

    private function addForeignKeys(): void
    {
        // Popups → elements
        $this->addForeignKey(null, '{{%leads_popups}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', null);

        // Submissions → popups
        $this->addForeignKey(null, '{{%leads_submissions}}', ['popupId'], '{{%leads_popups}}', ['id'], 'CASCADE', null);

        // Stats → popups
        $this->addForeignKey(null, '{{%leads_stats}}', ['popupId'], '{{%leads_popups}}', ['id'], 'CASCADE', null);
    }
}
