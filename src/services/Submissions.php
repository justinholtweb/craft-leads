<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\records\SubmissionRecord;

class Submissions extends Component
{
    public function submit(int $popupId, string $email, ?string $name = null, array $customFields = [], ?string $pageUrl = null): bool
    {
        $record = new SubmissionRecord();
        $record->popupId = $popupId;
        $record->email = $email;
        $record->name = $name;
        // Assign the raw array — the json() column encodes it once. Pre-encoding
        // here would double-encode (Craft binds arrays to json columns itself).
        $record->customFields = !empty($customFields) ? $customFields : null;
        $record->ipAddress = Craft::$app->getRequest()->getUserIP();
        $record->userAgent = Craft::$app->getRequest()->getUserAgent();
        $record->pageUrl = $pageUrl ?? Craft::$app->getRequest()->getReferrer() ?? '';
        $record->syncStatus = 'pending';

        if (!$record->save()) {
            return false;
        }

        // Record conversion stat
        Plugin::getInstance()->analytics->recordConversion($popupId);

        return true;
    }

    public function getSubmissions(int $popupId = null, int $limit = 50, int $offset = 0): array
    {
        $query = (new Query())
            ->from('{{%leads_submissions}}')
            ->orderBy('dateCreated DESC')
            ->limit($limit)
            ->offset($offset);

        if ($popupId) {
            $query->andWhere(['popupId' => $popupId]);
        }

        return $query->all();
    }

    public function getTotalSubmissions(?int $popupId = null): int
    {
        $query = (new Query())
            ->from('{{%leads_submissions}}');

        if ($popupId) {
            $query->andWhere(['popupId' => $popupId]);
        }

        return (int)$query->count();
    }

    public function deleteSubmission(int $id): bool
    {
        $record = SubmissionRecord::findOne($id);
        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    public function exportSubmissions(?int $popupId = null): array
    {
        $query = (new Query())
            ->select(['email', 'name', 'customFields', 'pageUrl', 'syncStatus', 'dateCreated'])
            ->from('{{%leads_submissions}}')
            ->orderBy('dateCreated DESC');

        if ($popupId) {
            $query->andWhere(['popupId' => $popupId]);
        }

        return $query->all();
    }
}
