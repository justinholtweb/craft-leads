<?php

namespace justinholtweb\leads\twig;

use justinholtweb\leads\elements\db\PopupQuery;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\Plugin;

class LeadsVariable
{
    public function popups(): PopupQuery
    {
        return Popup::find();
    }

    public function popup(int $id): ?Popup
    {
        return Plugin::getInstance()->popups->getById($id);
    }

    public function totalSubmissions(?int $popupId = null): int
    {
        return Plugin::getInstance()->submissions->getTotalSubmissions($popupId);
    }

    public function overviewStats(?string $startDate = null, ?string $endDate = null): array
    {
        return Plugin::getInstance()->analytics->getOverviewStats($startDate, $endDate);
    }
}
