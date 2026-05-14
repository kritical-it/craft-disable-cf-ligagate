<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\resolvers;

use Craft;
use craft\helpers\App;
use KriticalIT\Ligagate\contracts\StatusResolverInterface;
use KriticalIT\Ligagate\models\Settings;

class BlockedAnyStatusResolver implements StatusResolverInterface
{
    public function shouldDisableProxy(Settings $settings): bool
    {
        $blockedIps = $this->fetchBlockedIps($settings);

        if ($settings->disableStrategy === Settings::STRATEGY_ANY_IP) {
            return count($blockedIps) >= $settings->anyIpThreshold;
        }

        $resolvedIps = $this->resolveConfiguredHostIps($settings);

        return array_intersect($blockedIps, $resolvedIps) !== [];
    }

    /**
     * @return string[]
     */
    private function fetchBlockedIps(Settings $settings): array
    {
        $url = (string)App::parseEnv($settings->statusUrl);
        $response = Craft::createGuzzleClient([
            'timeout' => $settings->requestTimeout,
            'connect_timeout' => $settings->requestTimeout,
        ])->get($url);

        $body = (string)$response->getBody();
        $ips = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $ip = trim($line);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return string[]
     */
    private function resolveConfiguredHostIps(Settings $settings): array
    {
        $ips = [];

        foreach ($settings->getDnsRecordHostnames() as $hostname) {
            $records = dns_get_record($hostname, DNS_A + DNS_AAAA);

            foreach ($records ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
