<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\Plugin;
use yii\web\Response;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('leads:viewDashboard');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $startDate = $request->getQueryParam('startDate', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->getQueryParam('endDate', date('Y-m-d'));

        $overview = Plugin::getInstance()->analytics->getOverviewStats($startDate, $endDate);
        $totalSubmissions = Plugin::getInstance()->submissions->getTotalSubmissions();

        return $this->renderTemplate('leads/dashboard/index', [
            'overview' => $overview,
            'totalSubmissions' => $totalSubmissions,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }
}
