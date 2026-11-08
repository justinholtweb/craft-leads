<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\helpers\CartBridge;
use justinholtweb\leads\helpers\RateLimit;
use justinholtweb\leads\helpers\Targeting;
use justinholtweb\leads\Plugin;
use yii\web\Response;

/**
 * What a cached page can't know about its visitor — whether they're signed in and in which user
 * groups, and what's in their cart — for the page script's audience and cart rules.
 *
 *     GET actions/leads/visitor?need=user,cart&in=12,34
 *
 * An action, so full-page caches such as Blitz never store it, and `no-store, private` besides.
 * The page script only calls it when a popup on the page has such a rule, and asks only for what
 * those rules need. Nothing here creates a session, a cart or a cookie: a guest stays a guest.
 */
class VisitorController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        $this->requireSiteRequest();
        $request = Craft::$app->getRequest();

        $headers = $this->response->getHeaders();
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-Robots-Tag', 'noindex');

        if (!$request->getIsGet()) {
            $this->response->setStatusCode(405);

            return $this->asJson(['error' => 'GET only']);
        }

        // One call per page view that has such a rule, so it shares the tracking budget's size.
        if (!RateLimit::allow('visitor', Plugin::getInstance()->getSettings()->trackingPerMinute)) {
            $this->response->setStatusCode(429);

            return $this->asJson([]);
        }

        $need = array_intersect(explode(',', (string)$request->getQueryParam('need', '')), ['user', 'cart']);
        $data = [];

        if (in_array('user', $need, true)) {
            // getIdentity() only reads a session that already exists; a guest doesn't get one.
            $user = Craft::$app->getUser()->getIdentity();
            $data['user'] = [
                'guest' => $user === null,
                'groups' => $user === null ? [] : array_values(array_map(static fn($group) => $group->handle, $user->getGroups())),
            ];
        }

        if (in_array('cart', $need, true)) {
            $in = array_slice(array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string)$request->getQueryParam('in', ''))),
                static fn(int $id) => $id > 0,
            ))), 0, Targeting::MAX_CATEGORIES * 4);
            $data['cart'] = CartBridge::summary($in);
        }

        return $this->asJson($data);
    }
}
