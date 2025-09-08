<?php

namespace App\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class DictClient
{
    private Client $http;
    private string $base;

    public function __construct(string $baseUrl, int $timeoutSec = 10)
    {
        $this->base = rtrim($baseUrl, '/');
        $this->http = new Client([
            'base_uri' => $this->base,
            'timeout'  => $timeoutSec,
            'headers'  => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function teams(): array
    {
        $json = $this->getJson('/dict/teams') ?? [];
        return $json['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function stadiums(): array
    {
        $json = $this->getJson('/dict/stadiums') ?? [];
        return $json['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function clubs(): array
    {
        $json = $this->getJson('/dict/clubs') ?? [];
        return $json['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $path): array
    {
        try {
            $res = $this->http->get($this->base . $path);
            if ($res->getStatusCode() !== 200) {
                return [];
            }
            /** @return array<string, mixed> */
            $json = json_decode((string)$res->getBody()->getContents(), true) ?? [];
            return $json;
        } catch (GuzzleException $e) {
            // loggerに送る？
            return [];
        }
    }
}
