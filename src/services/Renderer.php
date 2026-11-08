<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\helpers\Targeting;
use justinholtweb\leads\Plugin;

class Renderer extends Component
{
    /** The designs that ship with Leads, under `templates/_popup-templates`. */
    public const BUILT_IN = [
        'clean-modal' => 'Clean Modal',
        'clean-slidein' => 'Clean Slide-in',
        'clean-bar' => 'Clean Bar',
        'bold-modal' => 'Bold Modal',
        'bold-slidein' => 'Bold Slide-in',
        'bold-bar' => 'Bold Bar',
        'minimal-modal' => 'Minimal Modal',
        'minimal-inline' => 'Minimal Inline',
    ];

    /** A `templateKey` naming one of the site's own designs: `site:split-signup`. */
    public const SITE_PREFIX = 'site:';

    /** A design's file name, without its extension. No folders, no dots. */
    private const DESIGN_NAME = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/';

    /**
     * The popup's markup — its design rendered with its content. An empty string (and a logged
     * error) when the design can't be rendered; with `$throw`, the error instead, so the editor can
     * show it.
     */
    public function renderPopup(Popup $popup, bool $throw = false): string
    {
        $key = (string)$popup->templateKey;

        try {
            if (str_starts_with($key, self::SITE_PREFIX)) {
                return $this->renderSiteDesign($popup, substr($key, strlen(self::SITE_PREFIX)));
            }

            // Only the designs Leads ships: the key is never a path.
            return $this->renderCp('leads/_popup-templates/' . (isset(self::BUILT_IN[$key]) ? $key : 'clean-modal'), ['popup' => $popup]);
        } catch (\Throwable $e) {
            if ($throw) {
                throw $e;
            }
            Craft::error("Failed to render popup template: {$e->getMessage()}", 'leads');

            return '';
        }
    }

    /**
     * The site's own designs, `name => label`: the templates directly in the designs folder
     * (Settings → Designs, `_leads` by default), leaving out partials whose names start with `_`.
     *
     * @return array<string, string>
     */
    public function getSiteDesigns(): array
    {
        $folder = Plugin::getInstance()->getSettings()->getDesignsFolder();
        if ($folder === null) {
            return [];
        }

        $dir = Craft::$app->getPath()->getSiteTemplatesPath() . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($dir)) {
            return [];
        }

        $designs = [];
        foreach (Craft::$app->getConfig()->getGeneral()->defaultTemplateExtensions as $extension) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.' . $extension) ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (is_file($file) && preg_match(self::DESIGN_NAME, $name) && !isset($designs[$name])) {
                    $designs[$name] = ucfirst(str_replace(['-', '_'], ' ', $name));
                }
            }
        }
        ksort($designs);

        return $designs;
    }

    /** Whether `$key` is a design the editor may choose. */
    public function isDesign(string $key): bool
    {
        if (str_starts_with($key, self::SITE_PREFIX)) {
            return isset($this->getSiteDesigns()[substr($key, strlen(self::SITE_PREFIX))]);
        }

        return isset(self::BUILT_IN[$key]);
    }

    /**
     * A site design gets the popup, its fields, and the parts the page script works with already
     * made: `form` (the `<form data-leads-form>` with the fields, consent box and button) and
     * `success`. It has to have one root element; see the README.
     */
    private function renderSiteDesign(Popup $popup, string $name): string
    {
        $folder = Plugin::getInstance()->getSettings()->getDesignsFolder();
        if ($folder === null || !preg_match(self::DESIGN_NAME, $name)) {
            throw new \RuntimeException("“{$name}” isn’t one of the site’s popup designs.");
        }

        $fields = $popup->getFormFieldsArray() ?: [['type' => 'email', 'name' => 'email', 'placeholder' => Craft::t('leads', 'Enter your email'), 'required' => true]];
        $form = $this->renderCp('leads/_popup-templates/_form', ['popup' => $popup, 'fields' => $fields]);
        $success = $popup->getSendsConfirmation()
            ? Craft::t('leads', 'Almost done: check your inbox and click the link to confirm.')
            : Craft::t('leads', 'Thanks for subscribing!');

        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $template = $folder . '/' . $name;
            if (!$view->doesTemplateExist($template)) {
                throw new \RuntimeException("The design template “{$template}” doesn’t exist.");
            }

            return $view->renderTemplate($template, [
                'popup' => $popup,
                'fields' => $fields,
                'form' => Template::raw($form),
                'success' => $success,
                'consent' => $popup->getConsent() + ['html' => Template::raw($popup->getConsentHtml())],
            ]);
        } finally {
            $view->setTemplateMode($previousMode);
        }
    }

    /**
     * Popup templates ship with the plugin, so they live under the CP template root. On a front-end
     * (site) request the view defaults to site template mode and can't find them — render in CP
     * mode and restore the previous mode afterward.
     *
     * @param array<string, mixed> $variables
     */
    private function renderCp(string $template, array $variables): string
    {
        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate($template, $variables);
        } finally {
            $view->setTemplateMode($previousMode);
        }
    }

    /**
     * Popup config as JSON that is safe inside an inline `<script>`. A popup's custom CSS is
     * written by whoever manages popups, and plain JSON leaves `</script>` intact — so before 5.0.6
     * a stylesheet could end the tag and run script on every page that showed the popup. `<`, `>`,
     * `&` and quotes are hex-escaped; the page script still reads exactly the same values.
     */
    public static function scriptJson(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public function getPopupConfig(Popup $popup): array
    {
        $targeting = Targeting::forClient($popup->getTargeting());

        return [
            'id' => $popup->id,
            'type' => $popup->popupType,
            'trigger' => $popup->triggerType,
            'triggerValue' => $popup->triggerValue,
            'position' => $popup->position,
            'html' => $this->renderPopup($popup),
            'customCss' => $popup->customCss,
            'targeting' => $targeting,
            // Overlays wait while the site's cookie banner is on screen; bars and inline forms don't.
            'holdFor' => in_array($popup->popupType, ['modal', 'slidein'], true)
                ? Plugin::getInstance()->getSettings()->getConsentBannerSelectors()
                : [],
            // Where the page script asks about the visitor, for rules a cached page can't decide.
            'visitorUrl' => $targeting['needs'] !== [] ? self::visitorUrl() : null,
        ];
    }

    /**
     * The visitor action's URL without its scheme and host, as the page script's other endpoints
     * are, so a page served on another of the site's hosts asks its own. An action URL, which
     * full-page caches leave alone.
     */
    public static function visitorUrl(): string
    {
        $url = UrlHelper::actionUrl('leads/visitor');
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return $path . ($query ? '?' . $query : '');
    }

    /**
     * Build the self-contained markup (stylesheet, config, engine script) to
     * inject overlay-style popups (modal/slide-in/bar) onto a front-end page.
     *
     * This publishes the frontend asset directory and emits explicit tags
     * rather than relying on the asset-bundle pipeline, so it works even on
     * templates that never call {{ head() }} / {{ endBody() }}.
     *
     * With `$countVisits`, the script is emitted even when no popup belongs on this page, so the
     * page views and visits that targeting rules count include this one.
     *
     * @param Popup[] $popups
     */
    public function getInjectHtml(array $popups, bool $countVisits = false): string
    {
        $configs = [];
        foreach ($popups as $popup) {
            // Inline forms are placed explicitly with leadsInline(), not auto-injected.
            if ($popup->popupType === 'inline') {
                continue;
            }
            $configs[] = $this->getPopupConfig($popup);
        }

        if (empty($configs) && !$countVisits) {
            return '';
        }

        $assetManager = Craft::$app->getAssetManager();
        $sourcePath = dirname(__DIR__) . '/web/assets/frontend/dist';
        // publish() returns [publishedPath, publishedUrl] — we want the URL.
        [, $baseUrl] = $assetManager->publish($sourcePath);

        $cssUrl = $baseUrl . '/css/leads.css';
        $jsUrl = $baseUrl . '/js/leads.js';
        $configJson = self::scriptJson($configs);

        return '<link rel="stylesheet" href="' . $cssUrl . '">'
            . '<script>window._leadsConfig = (window._leadsConfig || []).concat(' . $configJson . ');</script>'
            . '<script src="' . $jsUrl . '" defer></script>';
    }
}
