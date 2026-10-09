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
 * @property bool|null $consentGiven
 * @property string|null $consentText
 * @property string|null $consentVersion
 * @property string|null $consentedAt
 * @property array|string|null $consentEvidence Decoded on MySQL, JSON text on MariaDB
 * @property string|null $confirmTokenHash
 * @property string|null $confirmExpiresAt
 * @property string|null $confirmedAt
 * @property string $dateCreated
 */
class SubmissionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%leads_submissions}}';
    }
}
