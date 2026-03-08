<?php

namespace justinholtweb\leads\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $popupType
 * @property string $triggerType
 * @property string|null $triggerValue
 * @property string|null $templateKey
 * @property string|null $heading
 * @property string|null $bodyText
 * @property string|null $buttonText
 * @property string|null $buttonColor
 * @property string|null $backgroundColor
 * @property string|null $backgroundImage
 * @property string|null $customCss
 * @property array|null $formFields
 * @property array|null $targetingRules
 * @property string|null $integrationProvider
 * @property array|null $integrationSettings
 * @property string|null $position
 * @property string $popupStatus
 * @property int $priority
 */
class PopupRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%leads_popups}}';
    }
}
