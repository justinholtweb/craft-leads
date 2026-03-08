<?php

namespace justinholtweb\leads;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\models\Settings;
use justinholtweb\leads\services\Analytics;
use justinholtweb\leads\services\Integrations;
use justinholtweb\leads\services\Popups;
use justinholtweb\leads\services\Renderer;
use justinholtweb\leads\services\Submissions;
use justinholtweb\leads\twig\LeadsTwigExtension;
use justinholtweb\leads\twig\LeadsVariable;
use yii\base\Event;

/**
 * Leads — Popup & Lead Generation for Craft CMS
 *
 * @property Popups $popups
 * @property Submissions $submissions
 * @property Analytics $analytics
 * @property Integrations $integrations
 * @property Renderer $renderer
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'popups' => Popups::class,
                'submissions' => Submissions::class,
                'analytics' => Analytics::class,
                'integrations' => Integrations::class,
                'renderer' => Renderer::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function () {
            $this->registerElementTypes();
            $this->registerCpRoutes();
            $this->registerSiteRoutes();
            $this->registerPermissions();
            $this->registerTwigExtensions();
            $this->registerVariables();
        });
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = 'Leads';

        $nav['subnav'] = [];

        if (Craft::$app->getUser()->checkPermission('leads:viewDashboard') ||
            Craft::$app->getUser()->checkPermission('leads:accessPlugin')) {
            $nav['subnav']['dashboard'] = [
                'label' => Craft::t('leads', 'Dashboard'),
                'url' => 'leads/dashboard',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('leads:managePopups') ||
            Craft::$app->getUser()->checkPermission('leads:accessPlugin')) {
            $nav['subnav']['popups'] = [
                'label' => Craft::t('leads', 'Popups'),
                'url' => 'leads/popups',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('leads:viewSubmissions') ||
            Craft::$app->getUser()->checkPermission('leads:accessPlugin')) {
            $nav['subnav']['submissions'] = [
                'label' => Craft::t('leads', 'Submissions'),
                'url' => 'leads/submissions',
            ];
        }

        if (Craft::$app->getUser()->getIsAdmin() ||
            Craft::$app->getUser()->checkPermission('leads:manageSettings')) {
            $nav['subnav']['settings'] = [
                'label' => Craft::t('leads', 'Settings'),
                'url' => 'leads/settings',
            ];
        }

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('leads/settings/index', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = Popup::class;
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Dashboard
                $event->rules['leads/dashboard'] = 'leads/dashboard/index';

                // Popups
                $event->rules['leads/popups'] = 'leads/popups/index';
                $event->rules['leads/popups/new'] = 'leads/popups/edit';
                $event->rules['leads/popups/<popupId:\d+>'] = 'leads/popups/edit';

                // Submissions
                $event->rules['leads/submissions'] = 'leads/submissions/index';

                // Settings
                $event->rules['leads/settings'] = 'leads/settings/index';
            }
        );
    }

    private function registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Form submission
                $event->rules['leads/submit'] = 'leads/submit/index';

                // Tracking
                $event->rules['leads/track'] = 'leads/tracking/track';
            }
        );
    }

    private function registerTwigExtensions(): void
    {
        Craft::$app->getView()->registerTwigExtension(new LeadsTwigExtension());
    }

    private function registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('leads', LeadsVariable::class);
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('leads', 'Leads'),
                    'permissions' => [
                        'leads:accessPlugin' => [
                            'label' => Craft::t('leads', 'Access Leads'),
                        ],
                        'leads:managePopups' => [
                            'label' => Craft::t('leads', 'Manage popups'),
                        ],
                        'leads:viewSubmissions' => [
                            'label' => Craft::t('leads', 'View submissions'),
                            'nested' => [
                                'leads:exportSubmissions' => [
                                    'label' => Craft::t('leads', 'Export submissions'),
                                ],
                                'leads:deleteSubmissions' => [
                                    'label' => Craft::t('leads', 'Delete submissions'),
                                ],
                            ],
                        ],
                        'leads:viewDashboard' => [
                            'label' => Craft::t('leads', 'View dashboard'),
                        ],
                        'leads:manageSettings' => [
                            'label' => Craft::t('leads', 'Manage settings'),
                        ],
                    ],
                ];
            }
        );
    }
}
