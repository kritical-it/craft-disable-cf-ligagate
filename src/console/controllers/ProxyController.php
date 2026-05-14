<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\console\controllers;

use Craft;
use craft\console\Controller;
use KriticalIT\Ligagate\jobs\CheckProxyStatusJob;
use KriticalIT\Ligagate\Plugin;
use yii\console\ExitCode;

class ProxyController extends Controller
{
    public bool $queue = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'q' => 'queue',
        ]);
    }

    public function actionCheck(): int
    {
        if ($this->queue) {
            Craft::$app->getQueue()->push(new CheckProxyStatusJob());
            $this->stdout("Cloudflare proxy status check queued.\n");

            return ExitCode::OK;
        }

        return $this->printSummary(Plugin::getInstance()->proxy->check());
    }

    public function actionDisable(): int
    {
        return $this->printSummary(Plugin::getInstance()->proxy->disable());
    }

    public function actionEnable(): int
    {
        return $this->printSummary(Plugin::getInstance()->proxy->enable());
    }

    /**
     * @param array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>} $summary
     */
    private function printSummary(array $summary): int
    {
        $this->stdout(sprintf(
            "Desired state: %s\nRecords checked: %d\nRecords changed: %d\n",
            $summary['shouldDisable'] ? 'proxy disabled' : 'proxy enabled',
            $summary['checked'],
            $summary['changed']
        ));

        foreach ($summary['errors'] as $error) {
            $this->stderr(sprintf("Error: %s\n", $error));
        }

        return $summary['errors'] === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
