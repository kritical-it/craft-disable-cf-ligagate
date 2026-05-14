<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use KriticalIT\Ligagate\clients\CloudflareClient;
use KriticalIT\Ligagate\contracts\StatusResolverInterface;
use KriticalIT\Ligagate\db\Table;
use KriticalIT\Ligagate\models\Settings;
use KriticalIT\Ligagate\Plugin;
use RuntimeException;

class ProxyService extends Component
{
    /**
     * @return array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>}
     */
    public function check(bool $dryRun = false): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $resolver = $this->resolver($settings);
        $shouldDisable = $resolver->shouldDisableProxy($settings);
        $diagnostics = method_exists($resolver, 'getLastDiagnostics') ? $resolver->getLastDiagnostics() : [];
        $summary = $shouldDisable ? $this->disableConfiguredRecords($settings, $dryRun) : $this->restorePluginDisabledRecords($settings, $dryRun);
        $summary['diagnostics'] = $diagnostics;

        return $summary;
    }

    /**
     * @return array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>}
     */
    public function disable(): array
    {
        return $this->disableConfiguredRecords(Plugin::getInstance()->getSettings());
    }

    /**
     * @return array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>}
     */
    public function enable(): array
    {
        return $this->restorePluginDisabledRecords(Plugin::getInstance()->getSettings());
    }

    private function resolver(Settings $settings): StatusResolverInterface
    {
        $resolver = Craft::createObject($settings->getResolverClass());

        if (!$resolver instanceof StatusResolverInterface) {
            throw new RuntimeException(sprintf('Status resolver must implement %s.', StatusResolverInterface::class));
        }

        return $resolver;
    }

    /**
     * @return array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>}
     */
    private function disableConfiguredRecords(Settings $settings, bool $dryRun = false): array
    {
        $client = new CloudflareClient($settings);
        $summary = $this->summary(true, $dryRun);

        foreach ($settings->getDnsRecordHostnames() as $hostname) {
            try {
                foreach ($client->findDnsRecords($hostname) as $record) {
                    $summary['checked']++;
                    $state = $this->stateForRecord($hostname, $record, !$dryRun);
                    $wouldChange = $record['proxied'] === true;
                    $summary['records'][] = $this->recordSummary($record, $wouldChange, false);

                    if (!$dryRun && $state === null) {
                        throw new RuntimeException(sprintf('Could not create local state for Cloudflare DNS record "%s".', $record['id']));
                    }

                    if (!$dryRun && $state !== null) {
                        $this->touchState($state, $record, null);
                    }

                    if ($record['proxied'] === false) {
                        if ($dryRun) {
                            continue;
                        }

                        if ((bool)$state['disabledByPlugin'] === true) {
                            $this->saveState($state, [
                                'lastKnownProxied' => false,
                                'lastError' => null,
                            ]);
                        } else {
                            $this->saveState($state, [
                                'lastKnownProxied' => false,
                                'originalProxied' => false,
                                'disabledByPlugin' => false,
                                'lastError' => null,
                            ]);
                        }
                        continue;
                    }

                    if ($dryRun) {
                        $summary['changed']++;
                        continue;
                    }

                    $updated = $client->setDnsRecordProxied($record['id'], false);
                    $this->saveState($state, [
                        'recordType' => $updated['type'],
                        'lastKnownProxied' => false,
                        'originalProxied' => $state['originalProxied'] ?? true,
                        'disabledByPlugin' => true,
                        'lastChangedAt' => Db::prepareDateForDb(new DateTime),
                        'lastError' => null,
                    ]);
                    $summary['changed']++;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = sprintf('%s: %s', $hostname, $e->getMessage());
                if (!$dryRun) {
                    $this->markHostnameError($hostname, $e->getMessage());
                }
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $summary;
    }

    /**
     * @return array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>}
     */
    private function restorePluginDisabledRecords(Settings $settings, bool $dryRun = false): array
    {
        $client = new CloudflareClient($settings);
        $summary = $this->summary(false, $dryRun);
        $states = $this->pluginDisabledStatesForConfiguredHosts($settings);

        foreach ($states as $state) {
            try {
                if (empty($state['recordId'])) {
                    continue;
                }

                $record = $client->getDnsRecord((string)$state['recordId']);
                $summary['checked']++;
                $wouldChange = $record['proxied'] === false && ($state['originalProxied'] ?? null) === true;
                $summary['records'][] = $this->recordSummary($record, $wouldChange, true);

                if (!$dryRun) {
                    $this->touchState($state, $record, null);
                }

                if ($record['proxied'] === true) {
                    if ($dryRun) {
                        continue;
                    }

                    $this->saveState($state, [
                        'lastKnownProxied' => true,
                        'originalProxied' => null,
                        'disabledByPlugin' => false,
                        'lastError' => null,
                    ]);
                    continue;
                }

                if (($state['originalProxied'] ?? null) !== true) {
                    if ($dryRun) {
                        continue;
                    }

                    $this->saveState($state, [
                        'lastKnownProxied' => false,
                        'disabledByPlugin' => false,
                        'lastError' => null,
                    ]);
                    continue;
                }

                if ($dryRun) {
                    $summary['changed']++;
                    continue;
                }

                $updated = $client->setDnsRecordProxied($record['id'], true);
                $this->saveState($state, [
                    'recordType' => $updated['type'],
                    'lastKnownProxied' => true,
                    'originalProxied' => null,
                    'disabledByPlugin' => false,
                    'lastChangedAt' => Db::prepareDateForDb(new DateTime),
                    'lastError' => null,
                ]);
                $summary['changed']++;
            } catch (\Throwable $e) {
                $summary['errors'][] = sprintf('%s: %s', $state['hostname'], $e->getMessage());
                if (!$dryRun) {
                    $this->saveState($state, ['lastError' => $e->getMessage()]);
                }
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $summary;
    }

    /**
     * @param array{id:string,type:string,name:string,proxied:bool} $record
     * @return array<string,mixed>|null
     */
    private function stateForRecord(string $hostname, array $record, bool $create = true): ?array
    {
        $state = (new Query)
            ->from(Table::DNS_RECORD_STATES)
            ->where(['recordId' => $record['id']])
            ->one();

        if ($state !== false) {
            return $state;
        }

        if (!$create) {
            return null;
        }

        $now = Db::prepareDateForDb(new DateTime);
        Db::insert(Table::DNS_RECORD_STATES, [
            'hostname' => $hostname,
            'recordId' => $record['id'],
            'recordType' => $record['type'],
            'lastKnownProxied' => $record['proxied'],
            'originalProxied' => null,
            'disabledByPlugin' => false,
            'lastCheckedAt' => $now,
            'lastChangedAt' => null,
            'lastError' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        return (new Query)
            ->from(Table::DNS_RECORD_STATES)
            ->where(['recordId' => $record['id']])
            ->one();
    }

    /**
     * @param array<string,mixed> $state
     * @param array{id:string,type:string,name:string,proxied:bool} $record
     */
    private function touchState(array $state, array $record, ?string $error): void
    {
        $this->saveState($state, [
            'hostname' => $record['name'],
            'recordId' => $record['id'],
            'recordType' => $record['type'],
            'lastKnownProxied' => $record['proxied'],
            'lastCheckedAt' => Db::prepareDateForDb(new DateTime),
            'lastError' => $error,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $values
     */
    private function saveState(array $state, array $values): void
    {
        $values['dateUpdated'] = Db::prepareDateForDb(new DateTime);
        Db::update(Table::DNS_RECORD_STATES, $values, ['id' => $state['id']]);
    }

    private function markHostnameError(string $hostname, string $error): void
    {
        Db::update(Table::DNS_RECORD_STATES, [
            'lastCheckedAt' => Db::prepareDateForDb(new DateTime),
            'lastError' => $error,
            'dateUpdated' => Db::prepareDateForDb(new DateTime),
        ], ['hostname' => $hostname]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pluginDisabledStatesForConfiguredHosts(Settings $settings): array
    {
        $hostnames = $settings->getDnsRecordHostnames();

        if ($hostnames === []) {
            return [];
        }

        return (new Query)
            ->from(Table::DNS_RECORD_STATES)
            ->where([
                'hostname' => $hostnames,
                'disabledByPlugin' => true,
            ])
            ->all();
    }

    /**
     * @param array{id:string,type:string,name:string,proxied:bool} $record
     * @return array{id:string,type:string,name:string,proxied:bool,wouldChange:bool,targetProxied:bool}
     */
    private function recordSummary(array $record, bool $wouldChange, bool $targetProxied): array
    {
        return [
            'id' => $record['id'],
            'type' => $record['type'],
            'name' => $record['name'],
            'proxied' => $record['proxied'],
            'wouldChange' => $wouldChange,
            'targetProxied' => $targetProxied,
        ];
    }

    /**
     * @return array{shouldDisable:bool,dryRun:bool,checked:int,changed:int,errors:array<int,string>,diagnostics:array<string,mixed>,records:array<int,array<string,mixed>>}
     */
    private function summary(bool $shouldDisable, bool $dryRun): array
    {
        return [
            'shouldDisable' => $shouldDisable,
            'dryRun' => $dryRun,
            'checked' => 0,
            'changed' => 0,
            'errors' => [],
            'diagnostics' => [],
            'records' => [],
        ];
    }
}
