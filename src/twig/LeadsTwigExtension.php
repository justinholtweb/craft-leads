<?php

namespace justinholtweb\leads\twig;

use Craft;
use craft\helpers\Html;
use justinholtweb\leads\helpers\Targeting;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\services\Renderer;
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
        $renderer = Plugin::getInstance()->renderer;
        $configs = [];

        foreach ($popups as $popup) {
            if ($popup->popupType === 'inline') {
                continue;
            }
            $configs[] = $renderer->getPopupConfig($popup);
        }

        // Even with nothing to show here, load the script when a popup's rules count page views or
        // visits — otherwise this page wouldn't count towards them.
        if (empty($configs) && !Plugin::getInstance()->popups->countsVisits()) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(FrontendAsset::class);

        $configJson = Renderer::scriptJson($configs);

        // Added to, not assigned: a leadsInline() earlier in the page has already pushed its config.
        return '<script>window._leadsConfig = (window._leadsConfig || []).concat(' . $configJson . ');</script>';
    }

    public function leadsInline(?string $handle = null): string
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

        if (!$popup || !Targeting::matchesPage($popup->getTargeting(), Craft::$app->getRequest()->getUrl())) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(FrontendAsset::class);

        $renderer = Plugin::getInstance()->renderer;
        $config = $renderer->getPopupConfig($popup);

        // Hidden until the page script has checked the device and visitor rules — and it's the
        // script that sends the form, so without it there is nothing to show.
        return Html::tag('div', $renderer->renderPopup($popup), ['data-leads-inline' => $popup->id, 'hidden' => true])
            . '<script>window._leadsConfig = window._leadsConfig || []; window._leadsConfig.push(' . Renderer::scriptJson($config) . ');</script>';
    }
}
