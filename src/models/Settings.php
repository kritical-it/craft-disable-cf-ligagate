<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\models;

use craft\base\Model;
use craft\helpers\App;
use KriticalIT\Ligagate\resolvers\BlockedAnyStatusResolver;

/**
 * Disable CF Ligagate settings
 */
class Settings extends Model
{
    public const STRATEGY_EXACT_IP = 'exactIp';
    public const STRATEGY_ANY_IP = 'anyIp';

    public string $zoneId = '';
    public string $apiToken = '';
    public string $dnsRecordHostnames = '';
    public string $statusUrl = 'https://hayahora.futbol/estado/blocked-any.txt';
    public string $disableStrategy = self::STRATEGY_EXACT_IP;
    public string $resolverClass = '';
    public int $anyIpThreshold = 10;
    public int $requestTimeout = 10;
    public bool|string $respectManualChanges = true;

    /**
     * @return string[]
     */
    public function getDnsRecordHostnames(): array
    {
        $value = App::parseEnv($this->dnsRecordHostnames);
        $value = is_string($value) ? $value : $this->dnsRecordHostnames;

        $hostnames = preg_split('/[\s,]+/', $value) ?: [];
        $hostnames = array_map(static fn(string $hostname): string => strtolower(trim($hostname)), $hostnames);

        return array_values(array_unique(array_filter($hostnames)));
    }

    /**
     * @return array<string,string>
     */
    public function getDisableStrategyOptions(): array
    {
        return [
            self::STRATEGY_EXACT_IP => 'Disable if match exact IP',
            self::STRATEGY_ANY_IP => 'Disable if any IP',
        ];
    }

    public function getResolverClass(): string
    {
        $envResolver = App::env('DISABLE_CF_LIGAGATE_RESOLVER_CLASS');

        if (is_string($envResolver) && trim($envResolver) !== '') {
            return trim($envResolver);
        }

        $configuredResolver = App::parseEnv($this->resolverClass);
        if (is_string($configuredResolver) && trim($configuredResolver) !== '') {
            return trim($configuredResolver);
        }

        return BlockedAnyStatusResolver::class;
    }

    public function getRespectManualChanges(): bool
    {
        return App::parseBooleanEnv($this->respectManualChanges) ?? (bool)$this->respectManualChanges;
    }

    public function rules(): array
    {
        return [
            [['zoneId', 'apiToken', 'dnsRecordHostnames', 'statusUrl', 'disableStrategy', 'resolverClass'], 'string'],
            [['disableStrategy'], 'in', 'range' => array_keys($this->getDisableStrategyOptions())],
            [['respectManualChanges'], 'safe'],
            [['anyIpThreshold'], 'integer', 'min' => 1],
            [['requestTimeout'], 'integer', 'min' => 1, 'max' => 60],
        ];
    }
}
