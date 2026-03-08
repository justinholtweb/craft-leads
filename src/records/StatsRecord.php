<?php

namespace justinholtweb\leads\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $popupId
 * @property string $date
 * @property int $impressions
 * @property int $conversions
 * @property int $closes
 */
class StatsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%leads_stats}}';
    }
}
