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
use justinholtweb\leads\records\PopupRecord;
use yii\base\InvalidConfigException;

class Popup extends Element
{
    public string $popupType = 'modal';
    public string $triggerType = 'time';
    public ?string $triggerValue = '3';
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

    public static function defineSources(string $context = null): array
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

    protected static function defineActions(string $source = null): array
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
            'popupStatus' => '<span class="status ' . PopupStatus::from($this->popupStatus)->color() . '"></span>' . PopupStatus::from($this->popupStatus)->label(),
            'popupType' => PopupType::from($this->popupType)->label(),
            'triggerType' => ucfirst($this->triggerType),
            default => parent::attributeHtml($attribute),
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
        $rules[] = [['triggerType'], 'in', 'range' => ['time', 'scroll', 'exit', 'click']];
        $rules[] = [['popupStatus'], 'in', 'range' => ['draft', 'active', 'paused', 'archived']];
        $rules[] = [['heading', 'buttonText', 'triggerValue'], 'string', 'max' => 255];
        $rules[] = [['buttonColor', 'backgroundColor'], 'string', 'max' => 20];
        $rules[] = [['priority'], 'integer'];

        return $rules;
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
