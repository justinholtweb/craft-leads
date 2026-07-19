<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\db\Query;

class Analytics extends Component
{
    public function recordImpression(int $popupId): void
    {
        $this->incrementStat($popupId, 'impressions');
    }

    public function recordConversion(int $popupId): void
    {
        $this->incrementStat($popupId, 'conversions');
    }

    public function recordClose(int $popupId): void
    {
        $this->incrementStat($popupId, 'closes');
    }

    public function getStatsForPopup(int $popupId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = (new Query())
            ->from('{{%leads_stats}}')
            ->where(['popupId' => $popupId])
            ->orderBy('date ASC');

        if ($startDate) {
            $query->andWhere(['>=', 'date', $startDate]);
        }

        if ($endDate) {
            $query->andWhere(['<=', 'date', $endDate]);
        }

        return $query->all();
    }

    public function getOverviewStats(?string $startDate = null, ?string $endDate = null): array
    {
        $query = (new Query())
            ->select([
                'SUM(impressions) as totalImpressions',
                'SUM(conversions) as totalConversions',
                'SUM(closes) as totalCloses',
            ])
            ->from('{{%leads_stats}}');

        if ($startDate) {
            $query->andWhere(['>=', 'date', $startDate]);
        }

        if ($endDate) {
            $query->andWhere(['<=', 'date', $endDate]);
        }

        $result = $query->one();

        return [
            'impressions' => (int)($result['totalImpressions'] ?? 0),
            'conversions' => (int)($result['totalConversions'] ?? 0),
            'closes' => (int)($result['totalCloses'] ?? 0),
            'conversionRate' => $result['totalImpressions'] > 0
                ? round(($result['totalConversions'] / $result['totalImpressions']) * 100, 2)
                : 0,
        ];
    }

    private function incrementStat(int $popupId, string $column): void
    {
        $today = date('Y-m-d');
        $db = Craft::$app->getDb();

        // Try to increment existing row
        $affected = $db->createCommand()->update(
            '{{%leads_stats}}',
            [$column => new \yii\db\Expression("[[$column]] + 1")],
            ['popupId' => $popupId, 'date' => $today]
        )->execute();

        // Insert new row if none existed
        if ($affected === 0) {
            $db->createCommand()->insert('{{%leads_stats}}', [
                'popupId' => $popupId,
                'date' => $today,
                $column => 1,
                'impressions' => $column === 'impressions' ? 1 : 0,
                'conversions' => $column === 'conversions' ? 1 : 0,
                'closes' => $column === 'closes' ? 1 : 0,
                'dateCreated' => new \yii\db\Expression('NOW()'),
                'dateUpdated' => new \yii\db\Expression('NOW()'),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();
        }
    }
}
