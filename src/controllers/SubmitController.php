<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\Plugin;
use yii\web\Response;

class SubmitController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    public function beforeAction($action): bool
    {
        if ($action->id === 'index') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $popupId = (int)$request->getRequiredBodyParam('popupId');
        $email = $request->getRequiredBodyParam('email');
        $name = $request->getBodyParam('name');
        $pageUrl = $request->getBodyParam('pageUrl', '');
        $customFields = $request->getBodyParam('customFields', []);

        // Honeypot check
        $settings = Plugin::getInstance()->getSettings();
        if ($settings->enableHoneypot) {
            $honeypot = $request->getBodyParam('leads_hp');
            if (!empty($honeypot)) {
                return $this->asJson(['success' => true]);
            }
        }

        // Validate popup exists and is active
        $popup = Plugin::getInstance()->popups->getById($popupId);
        if (!$popup || $popup->popupStatus !== 'active') {
            return $this->asJson(['success' => false, 'error' => 'Invalid popup.']);
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid email address.']);
        }

        $success = Plugin::getInstance()->submissions->submit(
            $popupId,
            $email,
            $name,
            $customFields,
            $pageUrl
        );

        return $this->asJson(['success' => $success]);
    }
}
