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

    /** The settings the form posts; nothing else can be set through it. */
    public const EDITABLE = ['autoInjectScript', 'defaultButtonColor', 'defaultBackgroundColor', 'dataRetentionDays', 'enableHoneypot', 'rateLimitPerMinute', 'trackingPerMinute'];

    public function actionIndex(): Response
    {
        return $this->renderTemplate('leads/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'plugin' => Plugin::getInstance(),
            'readOnly' => !self::canSave(),
        ]);
    }

    /**
     * Settings are project config, so changing them is an admin's job on an environment that
     * allows admin changes — Craft's own rule. Before 5.0.6 the "Manage settings" permission could
     * change them anywhere, the live site included, where the next deploy silently undid it.
     */
    public static function canSave(): bool
    {
        return Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $posted = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        $plugin = Plugin::getInstance();

        // Posted values over the current ones, and only the ones the form has.
        $settings = array_merge(
            $plugin->getSettings()->toArray(),
            array_intersect_key($posted, array_flip(self::EDITABLE)),
        );

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('leads', 'Couldn\'t save settings.'));
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('leads', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
