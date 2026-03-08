<?php

namespace justinholtweb\leads\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $popupId
 * @property string $email
 * @property string|null $name
 * @property array|null $customFields
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property string $pageUrl
 * @property string $syncStatus
 * @property string|null $syncedAt
 */
class SubmissionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%leads_submissions}}';
    }
}
