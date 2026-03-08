<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('leads:manageSettings');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('leads/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $plugin = Plugin::getInstance();

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('leads', 'Couldn\'t save settings.'));
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('leads', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
