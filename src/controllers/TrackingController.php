<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\Plugin;
use yii\web\Response;

class TrackingController extends Controller
{
    protected array|bool|int $allowAnonymous = ['track'];

    public function beforeAction($action): bool
    {
        if ($action->id === 'track') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionTrack(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $popupId = (int)$request->getRequiredBodyParam('popupId');
        $type = $request->getRequiredBodyParam('type');

        if (!in_array($type, ['impression', 'conversion', 'close'], true)) {
            return $this->asJson(['success' => false]);
        }

        $analytics = Plugin::getInstance()->analytics;

        match ($type) {
            'impression' => $analytics->recordImpression($popupId),
            'conversion' => $analytics->recordConversion($popupId),
            'close' => $analytics->recordClose($popupId),
        };

        return $this->asJson(['success' => true]);
    }
}
