<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\web\View;
use justinholtweb\leads\elements\Popup;

class Renderer extends Component
{
    public function renderPopup(Popup $popup): string
    {
        $templateKey = $popup->templateKey ?: 'clean-modal';
        $templatePath = "leads/_popup-templates/{$templateKey}";

        // Popup templates ship with the plugin, so they live under the CP
        // template root. On a front-end (site) request the view defaults to
        // site template mode and can't find them — render in CP mode and
        // restore the previous mode afterward.
        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            return $view->renderTemplate($templatePath, [
                'popup' => $popup,
            ]);
        } catch (\Throwable $e) {
            Craft::error("Failed to render popup template: {$e->getMessage()}", 'leads');
            return '';
        } finally {
            $view->setTemplateMode($previousMode);
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
