<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\Plugin;
use yii\web\Response;

class IntegrationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('leads:managePopups');

        return true;
    }

    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $provider = $request->getRequiredBodyParam('provider');
        $settings = $request->getBodyParam('settings', []);

        $result = Plugin::getInstance()->integrations->testConnection($provider, $settings);

        return $this->asJson($result);
    }

    public function actionFetchLists(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $provider = $request->getRequiredBodyParam('provider');
        $settings = $request->getBodyParam('settings', []);

        $lists = Plugin::getInstance()->integrations->getLists($provider, $settings);

        return $this->asJson(['lists' => $lists]);
    }
}
