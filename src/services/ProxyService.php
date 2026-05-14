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
     * @return array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>}
     */
    public function check(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $shouldDisable = $this->resolver($settings)->shouldDisableProxy($settings);

        return $shouldDisable ? $this->disableConfiguredRecords($settings) : $this->restorePluginDisabledRecords($settings);
    }

    /**
     * @return array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>}
     */
    public function disable(): array
    {
        return $this->disableConfiguredRecords(Plugin::getInstance()->getSettings());
    }

    /**
     * @return array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>}
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
     * @return array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>}
     */
    private function disableConfiguredRecords(Settings $settings): array
    {
        $client = new CloudflareClient($settings);
        $summary = $this->summary(true);

        foreach ($settings->getDnsRecordHostnames() as $hostname) {
            try {
                foreach ($client->findDnsRecords($hostname) as $record) {
                    $summary['checked']++;
                    $state = $this->stateForRecord($hostname, $record);
                    $this->touchState($state, $record, null);

                    if ($record['proxied'] === false) {
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
                $this->markHostnameError($hostname, $e->getMessage());
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $summary;
    }

    /**
     * @return array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>}
     */
    private function restorePluginDisabledRecords(Settings $settings): array
    {
        $client = new CloudflareClient($settings);
        $summary = $this->summary(false);
        $states = $this->pluginDisabledStatesForConfiguredHosts($settings);

        foreach ($states as $state) {
            try {
                if (empty($state['recordId'])) {
                    continue;
                }

                $record = $client->getDnsRecord((string)$state['recordId']);
                $summary['checked']++;
                $this->touchState($state, $record, null);

                if ($record['proxied'] === true) {
                    $this->saveState($state, [
                        'lastKnownProxied' => true,
                        'originalProxied' => null,
                        'disabledByPlugin' => false,
                        'lastError' => null,
                    ]);
                    continue;
                }

                if (($state['originalProxied'] ?? null) !== true) {
                    $this->saveState($state, [
                        'lastKnownProxied' => false,
                        'disabledByPlugin' => false,
                        'lastError' => null,
                    ]);
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
                $this->saveState($state, ['lastError' => $e->getMessage()]);
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $summary;
    }

    /**
     * @param array{id:string,type:string,name:string,proxied:bool} $record
     * @return array<string,mixed>
     */
    private function stateForRecord(string $hostname, array $record): array
    {
        $state = (new Query)
            ->from(Table::DNS_RECORD_STATES)
            ->where(['recordId' => $record['id']])
            ->one();

        if ($state !== false) {
            return $state;
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
     * @return array{shouldDisable:bool,checked:int,changed:int,errors:array<int,string>}
     */
    private function summary(bool $shouldDisable): array
    {
        return [
            'shouldDisable' => $shouldDisable,
            'checked' => 0,
            'changed' => 0,
            'errors' => [],
        ];
    }
}
