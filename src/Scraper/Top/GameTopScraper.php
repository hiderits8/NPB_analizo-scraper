<?php

declare(strict_types=1);

namespace App\Scraper\Top;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

use App\Scraper\Top\Extractor\GameMetaExtractor;
use App\Scraper\Top\Extractor\GameParticipantsExtractor;
use App\Resolver\Resolver;

final class GameStatsScraper
{
    public function __construct(
        private ClientInterface $http,
        private Resolver $resolver,
        private string $teamlevel, // First|Farm
    ) {}

    /**
     * @param string $url 例: https://baseball.yahoo.co.jp/npb/game/2021029839/top
     */
    public function scrape(string $url): array
    {
        $html = (string) $this->http->request('GET', $url)->getBody();
        $root = new Crawler($html);

        $gameMeta = new GameMetaExtractor($this->resolver)
            ->extract($root, $this->teamlevel);

        $members = new GameParticipantsExtractor()->extract($root);

        return [
            'url' => $url,
            'game_meta' => $gameMeta,
            'participants' => $members,
        ];
    }
}
