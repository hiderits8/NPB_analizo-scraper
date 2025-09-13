<?php

namespace App\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * apiから辞書を取得するクラス
 * 
 * 関数の戻り値がnullの場合は、辞書が取得できなかったということ
 */
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
     * @return array<string, mixed> ('team_id', 'team_name', 'league', 'level', 'club_id')
     */
    public function teams(): array
    {
        $json = $this->getJson('/dict/teams') ?? [];
        return $json['data'] ?? [];
    }

    /**
     * @return array<string, mixed> ('stadium_id', 'stadium_name', 'is_dome')
     */
    public function stadiums(): array
    {
        $json = $this->getJson('/dict/stadiums') ?? [];
        return $json['data'] ?? [];
    }

    /**
     * @return array<string, mixed> ('club_id', 'club_name')
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
