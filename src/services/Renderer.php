<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
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

    /**
     * Build the self-contained markup (stylesheet, config, engine script) to
     * inject overlay-style popups (modal/slide-in/bar) onto a front-end page.
     *
     * This publishes the frontend asset directory and emits explicit tags
     * rather than relying on the asset-bundle pipeline, so it works even on
     * templates that never call {{ head() }} / {{ endBody() }}.
     *
     * @param Popup[] $popups
     */
    public function getInjectHtml(array $popups): string
    {
        $configs = [];
        foreach ($popups as $popup) {
            // Inline forms are placed explicitly with leadsInline(), not auto-injected.
            if ($popup->popupType === 'inline') {
                continue;
            }
            $configs[] = $this->getPopupConfig($popup);
        }

        if (empty($configs)) {
            return '';
        }

        $assetManager = Craft::$app->getAssetManager();
        $sourcePath = dirname(__DIR__) . '/web/assets/frontend/dist';
        // publish() returns [publishedPath, publishedUrl] — we want the URL.
        [, $baseUrl] = $assetManager->publish($sourcePath);

        $cssUrl = $baseUrl . '/css/leads.css';
        $jsUrl = $baseUrl . '/js/leads.js';
        $configJson = Json::encode($configs);

        return '<link rel="stylesheet" href="' . $cssUrl . '">'
            . '<script>window._leadsConfig = (window._leadsConfig || []).concat(' . $configJson . ');</script>'
            . '<script src="' . $jsUrl . '" defer></script>';
    }
}
