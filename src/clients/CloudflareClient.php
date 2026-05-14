<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\clients;

use Craft;
use craft\helpers\App;
use KriticalIT\Ligagate\models\Settings;
use RuntimeException;

class CloudflareClient
{
    private const PROXIABLE_RECORD_TYPES = ['A', 'AAAA'];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array<int,array{id:string,type:string,name:string,proxied:bool}>
     */
    public function findDnsRecords(string $hostname): array
    {
        $records = $this->request('GET', sprintf('zones/%s/dns_records', rawurlencode($this->zoneId())), [
            'query' => [
                'name' => $hostname,
                'per_page' => 50,
            ],
        ]);

        $matches = [];
        foreach ($records as $record) {
            if (
                ($record['name'] ?? null) === $hostname &&
                in_array($record['type'] ?? null, self::PROXIABLE_RECORD_TYPES, true) &&
                array_key_exists('proxied', $record)
            ) {
                $matches[] = $this->normalizeRecord($record);
            }
        }

        if ($matches === []) {
            throw new RuntimeException(sprintf('No proxied Cloudflare DNS record found for "%s".', $hostname));
        }

        return $matches;
    }

    /**
     * @return array{id:string,type:string,name:string,proxied:bool}
     */
    public function getDnsRecord(string $recordId): array
    {
        $record = $this->request('GET', sprintf('zones/%s/dns_records/%s', rawurlencode($this->zoneId()), rawurlencode($recordId)));

        return $this->normalizeRecord($record);
    }

    /**
     * @return array{id:string,type:string,name:string,proxied:bool}
     */
    public function setDnsRecordProxied(string $recordId, bool $proxied): array
    {
        $record = $this->request('PATCH', sprintf('zones/%s/dns_records/%s', rawurlencode($this->zoneId()), rawurlencode($recordId)), [
            'json' => [
                'proxied' => $proxied,
            ],
        ]);

        return $this->normalizeRecord($record);
    }

    /**
     * @return mixed
     */
    private function request(string $method, string $uri, array $options = []): mixed
    {
        $response = Craft::createGuzzleClient([
            'base_uri' => 'https://api.cloudflare.com/client/v4/',
            'timeout' => $this->settings->requestTimeout,
            'connect_timeout' => $this->settings->requestTimeout,
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->apiToken()),
                'Accept' => 'application/json',
            ],
        ])->request($method, $uri, $options);

        $payload = json_decode((string)$response->getBody(), true);

        if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
            $errors = is_array($payload['errors'] ?? null) ? json_encode($payload['errors']) : 'Unknown Cloudflare API error.';
            throw new RuntimeException((string)$errors);
        }

        return $payload['result'] ?? null;
    }

    private function zoneId(): string
    {
        $zoneId = trim((string)App::parseEnv($this->settings->zoneId));

        if ($zoneId === '') {
            throw new RuntimeException('Cloudflare zone ID is not configured.');
        }

        return $zoneId;
    }

    private function apiToken(): string
    {
        $apiToken = trim((string)App::parseEnv($this->settings->apiToken));

        if ($apiToken === '') {
            throw new RuntimeException('Cloudflare API token is not configured.');
        }

        return $apiToken;
    }

    /**
     * @return array{id:string,type:string,name:string,proxied:bool}
     */
    private function normalizeRecord(mixed $record): array
    {
        if (!is_array($record) || !isset($record['id'], $record['type'], $record['name']) || !array_key_exists('proxied', $record)) {
            throw new RuntimeException('Cloudflare returned an invalid DNS record payload.');
        }

        if (!in_array($record['type'], self::PROXIABLE_RECORD_TYPES, true)) {
            throw new RuntimeException(sprintf('Cloudflare DNS record type "%s" is not supported by this plugin.', (string)$record['type']));
        }

        return [
            'id' => (string)$record['id'],
            'type' => (string)$record['type'],
            'name' => (string)$record['name'],
            'proxied' => (bool)$record['proxied'],
        ];
    }
}
