<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\enums\IntegrationProvider;
use justinholtweb\leads\enums\PopupPosition;
use justinholtweb\leads\enums\PopupStatus;
use justinholtweb\leads\enums\PopupType;
use justinholtweb\leads\enums\TriggerType;
use justinholtweb\leads\helpers\CartBridge;
use justinholtweb\leads\helpers\Optin;
use justinholtweb\leads\helpers\Targeting;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\services\Renderer;
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
                // Seed a new popup with the configured default colors.
                $settings = Plugin::getInstance()->getSettings();
                $popup->buttonColor = $settings->defaultButtonColor;
                $popup->backgroundColor = $settings->defaultBackgroundColor;
                // The time trigger's delay, as before; it isn't the element's default, so a popup
                // made in code with an exit trigger gets no fallback it didn't ask for.
                $popup->triggerValue = '3';
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

        // The built-in designs, then the site's own, grouped. A stored design that has gone from
        // the folder stays listed, so saving doesn't quietly swap it; validation then says so.
        $renderer = Plugin::getInstance()->renderer;
        $templateOptions = [];
        foreach (Renderer::BUILT_IN as $value => $label) {
            $templateOptions[] = ['label' => Craft::t('leads', $label), 'value' => $value];
        }
        $siteDesigns = $renderer->getSiteDesigns();
        $current = (string)$popup->templateKey;
        if (str_starts_with($current, Renderer::SITE_PREFIX) && !isset($siteDesigns[substr($current, strlen(Renderer::SITE_PREFIX))])) {
            $siteDesigns[substr($current, strlen(Renderer::SITE_PREFIX))] = Craft::t('leads', '{name} (missing)', ['name' => substr($current, strlen(Renderer::SITE_PREFIX))]);
        }
        if ($siteDesigns) {
            $templateOptions = [
                ['optgroup' => Craft::t('leads', 'Built-in')],
                ...$templateOptions,
                ['optgroup' => Craft::t('leads', 'Site templates')],
            ];
            foreach ($siteDesigns as $name => $label) {
                $templateOptions[] = ['label' => $label, 'value' => Renderer::SITE_PREFIX . $name];
            }
        }

        $audienceOptions = [
            ['label' => Craft::t('leads', 'Anyone'), 'value' => 'all'],
            ['label' => Craft::t('leads', 'Guests'), 'value' => 'guests'],
            ['label' => Craft::t('leads', 'Signed-in users'), 'value' => 'users'],
        ];

        $groupOptions = array_map(static fn($group) => [
            'label' => Craft::t('site', $group->name),
            'value' => $group->handle,
        ], Craft::$app->getUserGroups()->getAllGroups());

        $cartOptions = [
            ['label' => Craft::t('leads', 'Any'), 'value' => 'any'],
            ['label' => Craft::t('leads', 'Empty'), 'value' => 'empty'],
            ['label' => Craft::t('leads', 'Not empty'), 'value' => 'notEmpty'],
        ];

        // The categories and entries a cart rule names, for the element selects.
        $cartCategoryIds = $popup->getTargeting()['cartCategories'];
        $cartCategories = $cartCategoryIds ? Category::find()->id($cartCategoryIds)->status(null)->all() : [];
        $cartEntries = $cartCategoryIds ? Entry::find()->id($cartCategoryIds)->status(null)->all() : [];

        $deviceOptions = [
            ['label' => Craft::t('leads', 'Desktop'), 'value' => 'desktop'],
            ['label' => Craft::t('leads', 'Tablet'), 'value' => 'tablet'],
            ['label' => Craft::t('leads', 'Mobile'), 'value' => 'mobile'],
        ];

        $frequencyOptions = [
            ['label' => Craft::t('leads', 'On every page view'), 'value' => 'every'],
            ['label' => Craft::t('leads', 'Once per session'), 'value' => 'session'],
            ['label' => Craft::t('leads', 'Once per visitor'), 'value' => 'once'],
            ['label' => Craft::t('leads', 'At most every few days'), 'value' => 'days'],
        ];

        $visitorOptions = [
            ['label' => Craft::t('leads', 'All visitors'), 'value' => 'all'],
            ['label' => Craft::t('leads', 'New visitors'), 'value' => 'new'],
            ['label' => Craft::t('leads', 'Returning visitors'), 'value' => 'returning'],
        ];

        $doubleOptInOptions = [
            ['label' => Craft::t('leads', 'Off'), 'value' => Optin::MODE_OFF],
            ['label' => Craft::t('leads', 'Leads emails a confirmation link'), 'value' => Optin::MODE_EMAIL],
            ['label' => Craft::t('leads', 'The email provider confirms (Mailchimp, ConvertKit)'), 'value' => Optin::MODE_PROVIDER],
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
            'designsFolder' => Plugin::getInstance()->getSettings()->getDesignsFolder(),
            'audienceOptions' => $audienceOptions,
            'groupOptions' => $groupOptions,
            'cartOptions' => $cartOptions,
            'commerceActive' => CartBridge::commerceIsActive(),
            'cartCategories' => $cartCategories,
            'cartEntries' => $cartEntries,
            'deviceOptions' => $deviceOptions,
            'frequencyOptions' => $frequencyOptions,
            'visitorOptions' => $visitorOptions,
            'doubleOptInOptions' => $doubleOptInOptions,
            'defaultConsentText' => Craft::t('leads', Optin::DEFAULT_TEXT),
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
        // An exit-intent popup has its own field: the fallback on phones and tablets, blank for none.
        // The general one still holds whatever the previous trigger type had in it.
        $popup->triggerValue = $popup->triggerType === 'exit'
            ? (trim((string)$request->getBodyParam('exitFallback', '')) ?: null)
            : $request->getBodyParam('triggerValue');
        $popup->templateKey = $request->getBodyParam('templateKey');
        $popup->heading = $request->getBodyParam('heading');
        $popup->bodyText = $request->getBodyParam('bodyText');
        $popup->buttonText = $request->getBodyParam('buttonText');
        $popup->buttonColor = $request->getBodyParam('buttonColor');
        $popup->backgroundColor = $request->getBodyParam('backgroundColor');
        $popup->backgroundImage = $request->getBodyParam('backgroundImage');
        $popup->customCss = $request->getBodyParam('customCss');
        $popup->formFields = $request->getBodyParam('formFields');
        // The editor posts pattern lists as one-per-line text and devices as checkboxes; store the
        // normalized shape, so what the page script reads is exactly what validation looked at.
        $popup->targetingRules = Targeting::normalize(self::targetingDates((array)$request->getBodyParam('targetingRules', [])));
        $popup->integrationProvider = $request->getBodyParam('integrationProvider') ?: null;
        // Only the settings the chosen provider takes; the form carries every provider's fields.
        $integrationSettings = (array)$request->getBodyParam('integrationSettings', []);
        $popup->integrationSettings = $popup->integrationProvider
            ? array_map(static fn($value) => is_string($value) ? trim($value) : $value, array_intersect_key(
                $integrationSettings,
                array_flip(\justinholtweb\leads\services\Integrations::FIELDS[$popup->integrationProvider] ?? []),
            ))
            : null;
        $popup->consentSettings = Optin::normalize($request->getBodyParam('consentSettings', []));
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
            if ($this->request->getAcceptsJson()) {
                return $this->asFailure(Craft::t('leads', 'Couldn\'t duplicate popup.'));
            }
            Craft::$app->getSession()->setError(Craft::t('leads', 'Couldn\'t duplicate popup.'));
            return $this->redirect("leads/popups/{$popupId}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('leads', 'Popup duplicated.'));

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('leads', 'Popup duplicated.'), ['id' => $duplicate->id]);
        }

        return $this->redirect("leads/popups/{$duplicate->id}");
    }

    /**
     * The editor's start and end dates arrive as Craft's date and time inputs, in the user's
     * timezone; the rules store them as ISO 8601 UTC. A date Craft can't read is passed on as
     * typed, so validation says so instead of quietly dropping it.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private static function targetingDates(array $rules): array
    {
        foreach (['startDate', 'endDate'] as $key) {
            $value = $rules[$key] ?? null;
            if (is_array($value) && trim((string)($value['date'] ?? '')) === '') {
                $rules[$key] = null;
            } elseif ($value !== null && $value !== '') {
                $date = DateTimeHelper::toDateTime($value);
                $rules[$key] = $date
                    ? $date->setTimezone(new \DateTimeZone('UTC'))->format(Targeting::DATE_FORMAT)
                    : (is_array($value) ? trim(($value['date'] ?? '') . ' ' . ($value['time'] ?? '')) : $value);
            }
        }

        return $rules;
    }
}
