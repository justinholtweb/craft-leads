<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use justinholtweb\leads\elements\Popup;

class Renderer extends Component
{
    public function renderPopup(Popup $popup): string
    {
        $templateKey = $popup->templateKey ?? 'clean-modal';
        $templatePath = "leads/_popup-templates/{$templateKey}";

        try {
            return Craft::$app->getView()->renderTemplate($templatePath, [
                'popup' => $popup,
            ]);
        } catch (\Exception $e) {
            Craft::error("Failed to render popup template: {$e->getMessage()}", 'leads');
            return '';
        }
    }

    public function getPopupConfig(Popup $popup): array
    {
        return [
            'id' => $popup->id,
            'type' => $popup->popupType,
            'trigger' => $popup->triggerType,
            'triggerValue' => $popup->triggerValue,
            'position' => $popup->position,
            'html' => $this->renderPopup($popup),
            'customCss' => $popup->customCss,
        ];
    }
}
