<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\enums\PopupStatus;

class Popups extends Component
{
    public function getById(int $id): ?Popup
    {
        return Popup::find()->id($id)->one();
    }

    public function save(Popup $popup): bool
    {
        return Craft::$app->getElements()->saveElement($popup);
    }

    public function delete(Popup $popup): bool
    {
        return Craft::$app->getElements()->deleteElement($popup);
    }

    public function duplicate(int $popupId): ?Popup
    {
        $original = $this->getById($popupId);
        if (!$original) {
            return null;
        }

        $duplicate = new Popup();
        $duplicate->title = $original->title . ' (Copy)';
        $duplicate->popupType = $original->popupType;
        $duplicate->triggerType = $original->triggerType;
        $duplicate->triggerValue = $original->triggerValue;
        $duplicate->templateKey = $original->templateKey;
        $duplicate->heading = $original->heading;
        $duplicate->bodyText = $original->bodyText;
        $duplicate->buttonText = $original->buttonText;
        $duplicate->buttonColor = $original->buttonColor;
        $duplicate->backgroundColor = $original->backgroundColor;
        $duplicate->backgroundImage = $original->backgroundImage;
        $duplicate->customCss = $original->customCss;
        $duplicate->formFields = $original->formFields;
        $duplicate->targetingRules = $original->targetingRules;
        $duplicate->integrationProvider = $original->integrationProvider;
        $duplicate->integrationSettings = $original->integrationSettings;
        $duplicate->position = $original->position;
        $duplicate->popupStatus = PopupStatus::Draft->value;
        $duplicate->priority = $original->priority;

        if (Craft::$app->getElements()->saveElement($duplicate)) {
            return $duplicate;
        }

        return null;
    }

    public function getActivePopupsForPage(string $url): array
    {
        $popups = Popup::find()
            ->popupStatus('active')
            ->orderBy('priority ASC')
            ->all();

        return array_filter($popups, function(Popup $popup) use ($url) {
            return $this->matchesTargetingRules($popup, $url);
        });
    }

    private function matchesTargetingRules(Popup $popup, string $url): bool
    {
        $rules = $popup->getTargetingRulesArray();

        if (empty($rules)) {
            return true;
        }

        // Page URL matching
        if (!empty($rules['pages'])) {
            $matched = false;
            foreach ($rules['pages'] as $pattern) {
                if (fnmatch($pattern, $url)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }
}
