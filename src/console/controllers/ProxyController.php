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
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue', 'dryRun']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'q' => 'queue',
            'd' => 'dryRun',
        ]);
    }

    public function actionCheck(): int
    {
        if ($this->queue && $this->dryRun) {
            $this->stderr("--dry-run cannot be combined with --queue because the result must be printed immediately.\n");

            return ExitCode::USAGE;
        }

        if ($this->queue) {
            Craft::$app->getQueue()->push(new CheckProxyStatusJob());
            $this->stdout("Cloudflare proxy status check queued.\n");

            return ExitCode::OK;
        }

        return $this->printSummary(Plugin::getInstance()->proxy->check($this->dryRun));
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
     * @param array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>} $summary
     */
    private function printSummary(array $summary): int
    {
        $this->stdout(sprintf(
            "Mode: %s\nDesired state: %s\nRecords checked: %d\nRecords %s: %d\n",
            ($summary['dryRun'] ?? false) ? 'dry run' : 'apply',
            $summary['shouldDisable'] ? 'proxy disabled' : 'proxy enabled',
            $summary['checked'],
            ($summary['dryRun'] ?? false) ? 'that would change' : 'changed',
            $summary['changed']
        ));

        if (($summary['dryRun'] ?? false) === true) {
            $this->printDryRunDetails($summary);
        }

        foreach ($summary['errors'] as $error) {
            $this->stderr(sprintf("Error: %s\n", $error));
        }

        return $summary['errors'] === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @param array{diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>} $summary
     */
    private function printDryRunDetails(array $summary): void
    {
        $diagnostics = $summary['diagnostics'] ?? [];

        if ($diagnostics !== []) {
            $this->stdout(sprintf("Resolver strategy: %s\n", $diagnostics['strategy'] ?? 'unknown'));

            if (($diagnostics['threshold'] ?? null) !== null) {
                $this->stdout(sprintf("Any IP threshold: %d\n", $diagnostics['threshold']));
            }

            $this->stdout(sprintf(
                "Blocked IPs found (%d): %s\n",
                $diagnostics['blockedIpCount'] ?? 0,
                $this->formatList($diagnostics['blockedIps'] ?? [])
            ));

            if (($diagnostics['strategy'] ?? null) === 'exactIp') {
                if (($diagnostics['witnessHostname'] ?? null) !== null) {
                    $this->stdout(sprintf("Witness hostname: %s\n", $diagnostics['witnessHostname']));
                    $this->stdout(sprintf(
                        "Witness IPs (%d): %s\n",
                        $diagnostics['witnessIpCount'] ?? 0,
                        $this->formatList($diagnostics['witnessIps'] ?? [])
                    ));
                }

                $this->stdout(sprintf(
                    "Protected hostname IPs (%d): %s\n",
                    $diagnostics['protectedIpCount'] ?? 0,
                    $this->formatList($diagnostics['protectedIps'] ?? [])
                ));
                $this->stdout(sprintf(
                    "All resolved IPs (%d): %s\n",
                    $diagnostics['resolvedIpCount'] ?? 0,
                    $this->formatList($diagnostics['resolvedIps'] ?? [])
                ));
                $this->stdout(sprintf(
                    "Matched IPs (%d): %s\n",
                    $diagnostics['matchedIpCount'] ?? 0,
                    $this->formatList($diagnostics['matchedIps'] ?? [])
                ));
            }
        }

        if (($summary['records'] ?? []) === []) {
            return;
        }

        $this->stdout("Cloudflare records:\n");
        foreach ($summary['records'] as $record) {
            $this->stdout(sprintf(
                "- %s %s [%s]: proxied=%s, target=%s, wouldChange=%s\n",
                $record['type'] ?? 'unknown',
                $record['name'] ?? 'unknown',
                $record['id'] ?? 'unknown',
                ($record['proxied'] ?? false) ? 'true' : 'false',
                ($record['targetProxied'] ?? false) ? 'true' : 'false',
                ($record['wouldChange'] ?? false) ? 'yes' : 'no'
            ));
        }
    }

    private function formatList(array $values): string
    {
        if ($values === []) {
            return '-';
        }

        return implode(', ', array_map(static fn($value): string => (string)$value, $values));
    }
}
