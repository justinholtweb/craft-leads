<?php

namespace justinholtweb\leads\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\enums\Color;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use justinholtweb\leads\elements\db\PopupQuery;
use justinholtweb\leads\enums\PopupStatus;
use justinholtweb\leads\enums\PopupType;
use justinholtweb\leads\helpers\Optin;
use justinholtweb\leads\helpers\Targeting;
use justinholtweb\leads\records\PopupRecord;
use yii\base\InvalidConfigException;

class Popup extends Element
{
    public string $popupType = 'modal';
    public string $triggerType = 'time';
    /**
     * Blank by default: the page script then waits 3 seconds for a time trigger, 50% for scroll
     * and 30 seconds for idle, and an exit-intent popup has no fallback on phones. (The editor
     * offers 3 to a new popup.)
     */
    public ?string $triggerValue = null;
    public ?string $templateKey = 'clean-modal';
    public ?string $heading = null;
    public ?string $bodyText = null;
    public ?string $buttonText = 'Subscribe';
    public ?string $buttonColor = '#3b82f6';
    public ?string $backgroundColor = '#ffffff';
    public ?string $backgroundImage = null;
    public ?string $customCss = null;
    public array|string|null $formFields = null;
    public array|string|null $targetingRules = null;
    public ?string $integrationProvider = null;
    public array|string|null $integrationSettings = null;
    public array|string|null $consentSettings = null;
    public ?string $position = null;
    public string $popupStatus = 'draft';
    public int $priority = 0;

    public static function displayName(): string
    {
        return Craft::t('leads', 'Popup');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('leads', 'Popups');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('leads', 'popup');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('leads', 'popups');
    }

    public static function refHandle(): ?string
    {
        return 'popup';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            'draft' => ['label' => Craft::t('leads', 'Draft'), 'color' => Color::White],
            'active' => ['label' => Craft::t('leads', 'Active'), 'color' => Color::Green],
            'paused' => ['label' => Craft::t('leads', 'Paused'), 'color' => Color::Orange],
            'archived' => ['label' => Craft::t('leads', 'Archived'), 'color' => Color::Red],
        ];
    }

    public static function find(): PopupQuery
    {
        return new PopupQuery(static::class);
    }

    public static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('leads', 'All Popups'),
            ],
            [
                'key' => 'type:modal',
                'label' => Craft::t('leads', 'Modals'),
                'criteria' => ['popupType' => 'modal'],
            ],
            [
                'key' => 'type:slidein',
                'label' => Craft::t('leads', 'Slide-ins'),
                'criteria' => ['popupType' => 'slidein'],
            ],
            [
                'key' => 'type:bar',
                'label' => Craft::t('leads', 'Notification Bars'),
                'criteria' => ['popupType' => 'bar'],
            ],
            [
                'key' => 'type:inline',
                'label' => Craft::t('leads', 'Inline Forms'),
                'criteria' => ['popupType' => 'inline'],
            ],
            [
                'key' => 'status:draft',
                'label' => Craft::t('leads', 'Drafts'),
                'criteria' => ['popupStatus' => 'draft'],
            ],
            [
                'key' => 'status:active',
                'label' => Craft::t('leads', 'Active'),
                'criteria' => ['popupStatus' => 'active'],
            ],
            [
                'key' => 'status:paused',
                'label' => Craft::t('leads', 'Paused'),
                'criteria' => ['popupStatus' => 'paused'],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'popupType' => Craft::t('leads', 'Type'),
            'popupStatus' => Craft::t('leads', 'Status'),
            'triggerType' => Craft::t('leads', 'Trigger'),
            'priority' => Craft::t('leads', 'Priority'),
            'dateCreated' => Craft::t('leads', 'Date Created'),
            'dateUpdated' => Craft::t('leads', 'Date Updated'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['popupType', 'popupStatus', 'triggerType', 'dateCreated'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['heading', 'bodyText'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            [
                'label' => Craft::t('leads', 'Priority'),
                'orderBy' => 'leads_popups.priority',
                'attribute' => 'priority',
                'defaultDir' => 'asc',
            ],
            [
                'label' => Craft::t('leads', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    protected static function defineActions(?string $source = null): array
    {
        return [
            Delete::class,
            Restore::class,
        ];
    }

    public function getStatus(): ?string
    {
        return $this->popupStatus;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("leads/popups/{$this->id}");
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'popupStatus' => '<span class="status ' . PopupStatus::from($this->popupStatus)->color() . '"></span>' . PopupStatus::from($this->popupStatus)->label() . $this->scheduleLabel(),
            'popupType' => PopupType::from($this->popupType)->label(),
            'triggerType' => ucfirst($this->triggerType),
            default => parent::attributeHtml($attribute),
        };
    }

    /** “ (scheduled)” or “ (ended)” after an active popup's status, when its dates say so. */
    private function scheduleLabel(): string
    {
        if ($this->popupStatus !== PopupStatus::Active->value) {
            return '';
        }

        return match (Targeting::scheduleState($this->getTargeting(), time())) {
            'scheduled' => ' <span class="light">(' . Craft::t('leads', 'scheduled') . ')</span>',
            'ended' => ' <span class="light">(' . Craft::t('leads', 'ended') . ')</span>',
            default => '',
        };
    }

    public function canView(\craft\elements\User $user): bool
    {
        return $user->can('leads:managePopups') || $user->can('leads:accessPlugin');
    }

    public function canSave(\craft\elements\User $user): bool
    {
        return $user->can('leads:managePopups');
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        return $user->can('leads:managePopups');
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['popupType'], 'required'];
        $rules[] = [['popupType'], 'in', 'range' => ['modal', 'slidein', 'bar', 'inline']];
        $rules[] = [['triggerType'], 'in', 'range' => ['time', 'scroll', 'exit', 'click', 'idle', 'view']];
        $rules[] = [['popupStatus'], 'in', 'range' => ['draft', 'active', 'paused', 'archived']];
        $rules[] = [['heading', 'buttonText', 'triggerValue'], 'string', 'max' => 255];
        $rules[] = [['buttonColor', 'backgroundColor'], 'string', 'max' => 20];
        $rules[] = [['priority'], 'integer'];
        $rules[] = [['integrationSettings'], 'validateIntegrationSettings', 'skipOnEmpty' => false];
        $rules[] = [['targetingRules'], 'validateTargetingRules', 'skipOnEmpty' => false];
        $rules[] = [['consentSettings'], 'validateConsentSettings', 'skipOnEmpty' => false];
        $rules[] = [['templateKey'], 'validateDesign', 'skipOnEmpty' => false];
        $rules[] = [['triggerValue'], 'validateExitFallback', 'skipOnEmpty' => false];

        return $rules;
    }

    /**
     * Refuses a design that isn't one — a built-in name Leads doesn't ship, a site template that
     * isn't in the designs folder — and renders a site design once, so a Twig error shows here
     * rather than as a popup that quietly never appears.
     */
    public function validateDesign(string $attribute): void
    {
        $renderer = \justinholtweb\leads\Plugin::getInstance()->renderer;
        $key = (string)$this->templateKey;

        if ($key === '') {
            $this->templateKey = 'clean-modal';

            return;
        }

        if (!$renderer->isDesign($key)) {
            $this->addError($attribute, Craft::t('leads', 'Choose one of the designs.'));

            return;
        }

        if (str_starts_with($key, \justinholtweb\leads\services\Renderer::SITE_PREFIX)) {
            try {
                $html = $renderer->renderPopup($this, true);
            } catch (\Throwable $e) {
                $this->addError($attribute, Craft::t('leads', 'The design couldn’t be rendered: {error}', ['error' => $e->getMessage()]));

                return;
            }
            if (!preg_match('/^\s*(<!--.*?-->\s*)*<(?!style|script|link)[a-z]/is', $html)) {
                $this->addError($attribute, Craft::t('leads', 'The design has to start with the popup’s one root element (a `<div>`, say), with any `<style>` inside it.'));
            }
        }
    }

    /**
     * An exit-intent popup's trigger value is its fallback on phones and tablets: show after this
     * many seconds if the visitor never scrolls back up. Blank for no fallback.
     */
    public function validateExitFallback(string $attribute): void
    {
        if ($this->triggerType !== 'exit' || $this->triggerValue === null || $this->triggerValue === '') {
            return;
        }

        if (!ctype_digit($this->triggerValue) || (int)$this->triggerValue < 1 || (int)$this->triggerValue > 600) {
            $this->addError($attribute, Craft::t('leads', 'The fallback has to be a whole number of seconds from 1 to 600, or blank for none.'));
        }
    }

    /**
     * Refuses integration settings Leads couldn't use, on save — a webhook URL it won't send to,
     * an `$ENV_VAR` that isn't set, a Mailchimp key with no data centre — rather than letting the
     * first submission find out.
     */
    public function validateIntegrationSettings(string $attribute): void
    {
        if (!$this->integrationProvider) {
            return;
        }

        foreach (\justinholtweb\leads\Plugin::getInstance()->integrations->problems($this->integrationProvider, $this->getIntegrationSettingsArray()) as $problem) {
            $this->addError($attribute, $problem);
        }
    }

    /**
     * Refuses targeting rules the page script couldn't follow — no device ticked, a frequency it
     * doesn't know, a negative page-view count — so the popup doesn't quietly show to everyone.
     */
    public function validateTargetingRules(string $attribute): void
    {
        $rules = $this->getTargeting();
        foreach (Targeting::problems($rules) as $problem) {
            $this->addError($attribute, Craft::t('leads', $problem));
        }

        // What the pure rules can't know: which groups there are, and whether there's a cart.
        $groups = array_map(static fn($group) => $group->handle, Craft::$app->getUserGroups()->getAllGroups());
        $unknown = array_diff($rules['userGroups'], $groups);
        if ($unknown !== []) {
            $this->addError($attribute, Craft::t('leads', 'There’s no user group “{handle}”.', ['handle' => reset($unknown)]));
        }
        if (in_array('cart', Targeting::needs($rules), true) && !\justinholtweb\leads\helpers\CartBridge::commerceIsActive()) {
            $this->addError($attribute, Craft::t('leads', 'Cart rules need Craft Commerce.'));
        }
    }

    /**
     * Refuses a consent checkbox with no wording, and a provider confirmation the chosen provider
     * can't do — the webhook has no "pending" to put anybody in.
     */
    public function validateConsentSettings(string $attribute): void
    {
        foreach (Optin::problems($this->getConsent(), $this->integrationProvider) as $problem) {
            $this->addError($attribute, Craft::t('leads', $problem));
        }
    }

    /**
     * The popup's consent checkbox and double opt-in settings, every key set.
     *
     * @return array{checkbox: bool, required: bool, text: string, doubleOptIn: string}
     */
    public function getConsent(): array
    {
        return Optin::normalize($this->normalizeJsonValue($this->consentSettings));
    }

    /** Whether Leads itself emails a confirmation link before a sign-up goes anywhere. */
    public function getSendsConfirmation(): bool
    {
        return $this->getConsent()['doubleOptIn'] === Optin::MODE_EMAIL;
    }

    /** The consent checkbox's wording as HTML, links and all. */
    public function getConsentHtml(): string
    {
        return Optin::html($this->getConsent()['text']);
    }

    /**
     * The popup's targeting rules with every key set — the defaults for anything it doesn't say.
     *
     * @return array<string, mixed>
     */
    public function getTargeting(): array
    {
        return Targeting::normalize($this->getTargetingRulesArray());
    }

    public function getFormFieldsArray(): array
    {
        return $this->normalizeJsonValue($this->formFields);
    }

    public function getTargetingRulesArray(): array
    {
        return $this->normalizeJsonValue($this->targetingRules);
    }

    public function getIntegrationSettingsArray(): array
    {
        return $this->normalizeJsonValue($this->integrationSettings);
    }

    /**
     * Coerce a JSON column value (which may arrive as an array, a JSON string,
     * or null depending on how the element was hydrated) into an array.
     */
    private function normalizeJsonValue(array|string|null $value): array
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : [];
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new PopupRecord();
        } else {
            $record = PopupRecord::findOne($this->id);
            if (!$record) {
                throw new InvalidConfigException("Invalid popup ID: {$this->id}");
            }
        }

        $record->id = $this->id;
        $record->popupType = $this->popupType;
        $record->triggerType = $this->triggerType;
        $record->triggerValue = $this->triggerValue;
        $record->templateKey = $this->templateKey;
        $record->heading = $this->heading;
        $record->bodyText = $this->bodyText;
        $record->buttonText = $this->buttonText;
        $record->buttonColor = $this->buttonColor;
        $record->backgroundColor = $this->backgroundColor;
        $record->backgroundImage = $this->backgroundImage;
        $record->customCss = $this->customCss;
        // These are json() columns — assign normalized arrays and let Craft's
        // JSON column binding encode them once. Encoding here too would double-encode.
        $record->formFields = $this->getFormFieldsArray() ?: null;
        $record->targetingRules = $this->getTargetingRulesArray() ?: null;
        $record->integrationProvider = $this->integrationProvider;
        $record->integrationSettings = $this->getIntegrationSettingsArray() ?: null;
        $consent = $this->getConsent();
        $record->consentSettings = $consent['checkbox'] || $consent['doubleOptIn'] !== Optin::MODE_OFF ? $consent : null;
        $record->position = $this->position;
        $record->popupStatus = $this->popupStatus;
        $record->priority = $this->priority;

        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        $record = PopupRecord::findOne($this->id);
        if ($record) {
            $record->delete();
        }

        parent::afterDelete();
    }
}
