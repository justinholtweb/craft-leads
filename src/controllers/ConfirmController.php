<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\helpers\RateLimit;
use justinholtweb\leads\Plugin;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * The page a double opt-in confirmation link opens.
 *
 * Opening the link changes nothing: it shows a Confirm button, and the POST that button sends is
 * what confirms. Mail scanners and link previewers open every link in an email, and a GET that
 * confirmed would subscribe everyone whose mail provider checks links — the opposite of proving
 * the person asked.
 */
class ConfirmController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    /** Confirmation page views and attempts one address may make in a minute. */
    public const PER_MINUTE = 20;

    public function actionIndex(): Response
    {
        if (!RateLimit::allow('confirm', self::PER_MINUTE)) {
            throw new TooManyRequestsHttpException(Craft::t('leads', 'Too many attempts. Please try again in a minute.'));
        }

        $request = Craft::$app->getRequest();
        $submissions = Plugin::getInstance()->submissions;

        if ($request->getIsPost()) {
            $code = $request->getBodyParam('code');
            $record = $submissions->confirm($code);
            $state = $record !== null ? 'confirmed' : 'invalid';
        } else {
            $code = $request->getQueryParam('code');
            $record = $submissions->findPendingByToken($code);
            $state = $record !== null ? 'ask' : 'invalid';
        }

        $popup = $record !== null ? Plugin::getInstance()->popups->getById($record->popupId) : null;

        $headers = $this->response->getHeaders();
        // The token is in the URL: keep it out of caches, out of the Referer header sent to
        // anything the page links to, and out of search results.
        $headers->set('Cache-Control', 'private, no-store');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        if ($state === 'invalid') {
            $this->response->setStatusCode(404);
        }

        return $this->renderPage([
            'state' => $state,
            // Only a code that is still good goes back into the page.
            'code' => $state === 'ask' ? (string)$code : null,
            'popup' => $popup instanceof Popup ? $popup : null,
            'siteName' => Craft::$app->getSites()->getCurrentSite()->getName(),
        ]);
    }

    /** The site's own template when one is set and exists, otherwise Leads' plain page. */
    private function renderPage(array $variables): Response
    {
        $template = trim(Plugin::getInstance()->getSettings()->confirmationTemplate);
        $view = Craft::$app->getView();

        if ($template !== '' && $view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $this->renderTemplate('leads/_frontend/confirm', $variables, View::TEMPLATE_MODE_CP);
    }
}
