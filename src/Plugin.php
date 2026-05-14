<?php

namespace KriticalIT\Ligagate;

use Craft;
use KriticalIT\Ligagate\models\Settings;
use KriticalIT\Ligagate\services\ProxyService;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;

/**
 * Disable CF Ligagate plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @author Kritical IT <info@gongarce.io>
 * @copyright Kritical IT
 * @license MIT
 * @property ProxyService $proxy
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'proxy' => ProxyService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('disable-cf-ligagate/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
    }
}
