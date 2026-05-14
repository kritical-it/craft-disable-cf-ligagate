<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\resolvers;

use Craft;
use craft\helpers\App;
use KriticalIT\Ligagate\contracts\StatusResolverInterface;
use KriticalIT\Ligagate\models\Settings;

class BlockedAnyStatusResolver implements StatusResolverInterface
{
    private array $lastDiagnostics = [];

    public function shouldDisableProxy(Settings $settings): bool
    {
        $this->lastDiagnostics = [];
        $blockedIps = $this->fetchBlockedIps($settings);
        $resolvedIps = [];
        $matchedIps = [];

        if ($settings->disableStrategy === Settings::STRATEGY_ANY_IP) {
            $shouldDisable = count($blockedIps) >= $settings->anyIpThreshold;
            $this->lastDiagnostics = $this->diagnostics($settings, $blockedIps, $resolvedIps, $matchedIps, $shouldDisable);

            return $shouldDisable;
        }

        $witnessHostname = $settings->getWitnessHostname();
        $witnessIps = $witnessHostname !== null ? $this->resolveHostIps([$witnessHostname]) : [];
        $protectedIps = $this->resolveHostIps($settings->getDnsRecordHostnames());
        $resolvedIps = array_values(array_unique(array_merge($witnessIps, $protectedIps)));
        $matchedIps = array_values(array_intersect($blockedIps, $resolvedIps));
        $shouldDisable = $matchedIps !== [];

        $this->lastDiagnostics = $this->diagnostics($settings, $blockedIps, $resolvedIps, $matchedIps, $shouldDisable, $witnessHostname, $witnessIps, $protectedIps);

        return $shouldDisable;
    }

    public function getLastDiagnostics(): array
    {
        return $this->lastDiagnostics;
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
    private function resolveHostIps(array $hostnames): array
    {
        $ips = [];

        foreach ($hostnames as $hostname) {
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

    /**
     * @param string[] $blockedIps
     * @param string[] $resolvedIps
     * @param string[] $matchedIps
     * @param string[] $witnessIps
     * @param string[] $protectedIps
     * @return array{strategy:string,threshold:int|null,witnessHostname:string|null,witnessIps:array<int,string>,protectedIps:array<int,string>,blockedIps:array<int,string>,resolvedIps:array<int,string>,matchedIps:array<int,string>,blockedIpCount:int,witnessIpCount:int,protectedIpCount:int,resolvedIpCount:int,matchedIpCount:int,shouldDisable:bool}
     */
    private function diagnostics(Settings $settings, array $blockedIps, array $resolvedIps, array $matchedIps, bool $shouldDisable, ?string $witnessHostname = null, array $witnessIps = [], array $protectedIps = []): array
    {
        return [
            'strategy' => $settings->disableStrategy,
            'threshold' => $settings->disableStrategy === Settings::STRATEGY_ANY_IP ? $settings->anyIpThreshold : null,
            'witnessHostname' => $witnessHostname,
            'witnessIps' => $witnessIps,
            'protectedIps' => $protectedIps,
            'blockedIps' => $blockedIps,
            'resolvedIps' => $resolvedIps,
            'matchedIps' => $matchedIps,
            'blockedIpCount' => count($blockedIps),
            'witnessIpCount' => count($witnessIps),
            'protectedIpCount' => count($protectedIps),
            'resolvedIpCount' => count($resolvedIps),
            'matchedIpCount' => count($matchedIps),
            'shouldDisable' => $shouldDisable,
        ];
    }
}
