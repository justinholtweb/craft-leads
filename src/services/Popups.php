<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\enums\PopupStatus;
use justinholtweb\leads\helpers\Targeting;

class Popups extends Component
{
    /**
     * @var Popup[]|null Memoized for the request: the page render and the visit check both ask.
     */
    private ?array $activePopups = null;

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
        $duplicate->consentSettings = $original->consentSettings;
        $duplicate->position = $original->position;
        $duplicate->popupStatus = PopupStatus::Draft->value;
        $duplicate->priority = $original->priority;

        if (Craft::$app->getElements()->saveElement($duplicate)) {
            return $duplicate;
        }

        return null;
    }

    /**
     * The active popups within their dates whose page rules match `$url`, in priority order.
     * Device, frequency, visitor and traffic-source rules are left to the page script — see
     * `helpers\Targeting`.
     *
     * @return Popup[]
     */
    public function getActivePopupsForPage(string $url): array
    {
        return array_values(array_filter(
            $this->getActivePopups(),
            static fn(Popup $popup) => Targeting::inSchedule($popup->getTargeting(), time())
                && Targeting::matchesPage($popup->getTargeting(), $url),
        ));
    }

    /**
     * Whether any running popup needs the page script on every page, even one with no popup of its
     * own — to count page views and visits, or to note where a visit came from where it started.
     */
    public function countsVisits(): bool
    {
        foreach ($this->getActivePopups() as $popup) {
            $rules = $popup->getTargeting();
            if (Targeting::countsVisits($rules) && Targeting::scheduleState($rules, time()) !== 'ended') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Popup[]
     */
    private function getActivePopups(): array
    {
        return $this->activePopups ??= Popup::find()
            ->popupStatus('active')
            ->orderBy('priority ASC')
            ->all();
    }
}
