<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\enums\IntegrationProvider;
use justinholtweb\leads\enums\PopupPosition;
use justinholtweb\leads\enums\PopupStatus;
use justinholtweb\leads\enums\PopupType;
use justinholtweb\leads\enums\TriggerType;
use justinholtweb\leads\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PopupsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('leads:accessPlugin');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('leads/popups/index', [
            'elementType' => Popup::class,
        ]);
    }

    public function actionEdit(?int $popupId = null, ?Popup $popup = null): Response
    {
        if ($popup === null) {
            if ($popupId !== null) {
                $popup = Plugin::getInstance()->popups->getById($popupId);
                if (!$popup) {
                    throw new NotFoundHttpException('Popup not found.');
                }
            } else {
                $popup = new Popup();
            }
        }

        $isNew = !$popup->id;

        $popupTypeOptions = array_map(fn($type) => [
            'label' => $type->label(),
            'value' => $type->value,
        ], PopupType::cases());

        $triggerTypeOptions = array_map(fn($type) => [
            'label' => $type->label(),
            'value' => $type->value,
        ], TriggerType::cases());

        $positionOptions = [
            ['label' => '— Auto —', 'value' => ''],
            ...array_map(fn($pos) => [
                'label' => $pos->label(),
                'value' => $pos->value,
            ], PopupPosition::cases()),
        ];

        $statusOptions = array_map(fn($status) => [
            'label' => $status->label(),
            'value' => $status->value,
        ], PopupStatus::cases());

        $integrationOptions = [
            ['label' => '— None —', 'value' => ''],
            ...array_map(fn($provider) => [
                'label' => $provider->label(),
                'value' => $provider->value,
            ], IntegrationProvider::cases()),
        ];

        $templateOptions = [
            ['label' => 'Clean Modal', 'value' => 'clean-modal'],
            ['label' => 'Clean Slide-in', 'value' => 'clean-slidein'],
            ['label' => 'Clean Bar', 'value' => 'clean-bar'],
            ['label' => 'Bold Modal', 'value' => 'bold-modal'],
            ['label' => 'Bold Slide-in', 'value' => 'bold-slidein'],
            ['label' => 'Bold Bar', 'value' => 'bold-bar'],
            ['label' => 'Minimal Modal', 'value' => 'minimal-modal'],
            ['label' => 'Minimal Inline', 'value' => 'minimal-inline'],
        ];

        return $this->renderTemplate('leads/popups/edit', [
            'popup' => $popup,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('leads', 'New Popup') : $popup->title,
            'popupTypeOptions' => $popupTypeOptions,
            'triggerTypeOptions' => $triggerTypeOptions,
            'positionOptions' => $positionOptions,
            'statusOptions' => $statusOptions,
            'integrationOptions' => $integrationOptions,
            'templateOptions' => $templateOptions,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('leads:managePopups');

        $request = Craft::$app->getRequest();
        $popupId = $request->getBodyParam('popupId');

        if ($popupId) {
            $popup = Plugin::getInstance()->popups->getById($popupId);
            if (!$popup) {
                throw new NotFoundHttpException('Popup not found.');
            }
        } else {
            $popup = new Popup();
        }

        $popup->title = $request->getBodyParam('title');
        $popup->popupType = $request->getBodyParam('popupType', 'modal');
        $popup->triggerType = $request->getBodyParam('triggerType', 'time');
        $popup->triggerValue = $request->getBodyParam('triggerValue');
        $popup->templateKey = $request->getBodyParam('templateKey');
        $popup->heading = $request->getBodyParam('heading');
        $popup->bodyText = $request->getBodyParam('bodyText');
        $popup->buttonText = $request->getBodyParam('buttonText');
        $popup->buttonColor = $request->getBodyParam('buttonColor');
        $popup->backgroundColor = $request->getBodyParam('backgroundColor');
        $popup->backgroundImage = $request->getBodyParam('backgroundImage');
        $popup->customCss = $request->getBodyParam('customCss');
        $popup->formFields = $request->getBodyParam('formFields');
        $popup->targetingRules = $request->getBodyParam('targetingRules');
        $popup->integrationProvider = $request->getBodyParam('integrationProvider') ?: null;
        $popup->integrationSettings = $request->getBodyParam('integrationSettings');
        $popup->position = $request->getBodyParam('position') ?: null;
        $popup->popupStatus = $request->getBodyParam('popupStatus', 'draft');
        $popup->priority = (int)$request->getBodyParam('priority', 0);

        if (!Craft::$app->getElements()->saveElement($popup)) {
            Craft::$app->getSession()->setError(Craft::t('leads', 'Couldn\'t save popup.'));
            Craft::$app->getUrlManager()->setRouteParams(['popup' => $popup]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('leads', 'Popup saved.'));

        return $this->redirectToPostedUrl($popup);
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('leads:managePopups');

        $popupId = Craft::$app->getRequest()->getRequiredBodyParam('popupId');
        $duplicate = Plugin::getInstance()->popups->duplicate($popupId);

        if (!$duplicate) {
            Craft::$app->getSession()->setError(Craft::t('leads', 'Couldn\'t duplicate popup.'));
            return $this->redirect("leads/popups/{$popupId}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('leads', 'Popup duplicated.'));

        return $this->redirect("leads/popups/{$duplicate->id}");
    }
}
