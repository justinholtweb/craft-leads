<?php

namespace justinholtweb\leads\twig;

use Craft;
use craft\helpers\Json;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\web\assets\frontend\FrontendAsset;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class LeadsTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('leadsPopups', [$this, 'leadsPopups'], ['is_safe' => ['html']]),
            new TwigFunction('leadsInline', [$this, 'leadsInline'], ['is_safe' => ['html']]),
        ];
    }

    public function leadsPopups(): string
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return '';
        }

        $currentUrl = Craft::$app->getRequest()->getUrl();
        $popups = Plugin::getInstance()->popups->getActivePopupsForPage($currentUrl);

        if (empty($popups)) {
            return '';
        }

        $renderer = Plugin::getInstance()->renderer;
        $configs = [];

        foreach ($popups as $popup) {
            if ($popup->popupType === 'inline') {
                continue;
            }
            $configs[] = $renderer->getPopupConfig($popup);
        }

        if (empty($configs)) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(FrontendAsset::class);

        $configJson = Json::encode($configs);

        return '<script>window._leadsConfig = ' . $configJson . ';</script>';
    }

    public function leadsInline(string $handle = null): string
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return '';
        }

        $query = \justinholtweb\leads\elements\Popup::find()
            ->popupStatus('active')
            ->popupType('inline');

        if ($handle) {
            $query->slug($handle);
        }

        $popup = $query->one();

        if (!$popup) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(FrontendAsset::class);

        $renderer = Plugin::getInstance()->renderer;
        $config = $renderer->getPopupConfig($popup);

        $configJson = Json::encode([$config]);

        return $renderer->renderPopup($popup)
            . '<script>window._leadsConfig = window._leadsConfig || []; window._leadsConfig.push(' . Json::encode($config) . ');</script>';
    }
}
