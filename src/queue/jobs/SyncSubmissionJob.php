<?php

namespace justinholtweb\leads\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\records\SubmissionRecord;

class SyncSubmissionJob extends BaseJob
{
    public int $submissionId = 0;

    public function execute($queue): void
    {
        $record = SubmissionRecord::findOne($this->submissionId);

        if (!$record) {
            throw new \RuntimeException("Submission {$this->submissionId} not found.");
        }

        $popup = Plugin::getInstance()->popups->getById($record->popupId);

        if (!$popup || !$popup->integrationProvider) {
            $record->syncStatus = 'failed';
            $record->save(false);
            return;
        }

        $integrationSettings = $popup->getIntegrationSettingsArray();
        $integration = Plugin::getInstance()->integrations->getIntegration(
            $popup->integrationProvider,
            $integrationSettings
        );

        if (!$integration) {
            $record->syncStatus = 'failed';
            $record->save(false);
            Craft::error("Unknown integration provider: {$popup->integrationProvider}", 'leads');
            return;
        }

        // The json() column decodes to an array; coerce anything unexpected to [].
        $customFields = $record->customFields;
        $customFields = is_array($customFields) ? $customFields : [];

        $success = $integration->sendSubscriber(
            $record->email,
            $record->name,
            $customFields
        );

        $record->syncStatus = $success ? 'synced' : 'failed';
        $record->syncedAt = $success ? date('Y-m-d H:i:s') : null;
        $record->save(false);

        if (!$success) {
            Craft::error("Failed to sync submission {$this->submissionId} to {$popup->integrationProvider}", 'leads');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('leads', 'Syncing submission #{id} to email provider', ['id' => $this->submissionId]);
    }
}
