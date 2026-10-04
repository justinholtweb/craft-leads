<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\helpers\RateLimit;
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

        // These counts are the dashboard and the A/B numbers, and the endpoint is anonymous. So it
        // takes what a page would send — Tracking Events per Minute from one address, under a
        // site-wide ceiling — and only for a popup that is live. Before 5.0.6 it counted anything,
        // for any popup ID, as fast as it was sent.
        if (!RateLimit::allow('track', Plugin::getInstance()->getSettings()->trackingPerMinute)) {
            $this->response->setStatusCode(429);

            return $this->asJson(['success' => false]);
        }

        $popup = Plugin::getInstance()->popups->getById($popupId);

        if (!$popup || $popup->popupStatus !== 'active') {
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
