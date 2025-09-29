<?php

declare(strict_types=1);

namespace App\Scrape;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use App\Scrape\FieldBsoExtractor;
use App\Scrape\ResultEventExtractor;


final class GameScoreScraper
{
    public function __construct(
        private ClientInterface $http,
        private string $fieldBsoSelector = '#async-fieldBso',
        private string $resultEventSelector = '#result',
        private string $baseRunnersSelector = '#field',
        private string $dakyuSelector = '#field',
        private string $replayNavSelector = '#replay',
        private string $pitchesDetailSelector = '#pitchesDetail'
    ) {}
    /**
     * @param string $url 例: https://baseball.yahoo.co.jp/npb/game/2021029839/score?index=0110100
     * @param array  $gameMeta 任意: ['away'=>['team_code'=>..,'team_raw'=>..], 'home'=>[...]]
     */
    public function scrape(string $url, array $gameMeta = []): array
    {
        $res   = $this->http->request('GET', $url);
        $html  = (string) $res->getBody();
        $root  = new Crawler($html);

        // 1.1: BSO/スコア小表/見出し（回・表裏 or 試合終了）
        $fieldBso = new FieldBsoExtractor($this->fieldBsoSelector)->extract($root);

        // 1.2: 結果イベント
        $resultEvent = new ResultEventExtractor($this->resultEventSelector)->extract($root);

        // 1.3: 走者
        $baseRunners = new BaseRunnersExtractor($this->baseRunnersSelector)->extract($root);

        // 1.3.1: 打球
        $dakyu = new DakyuResultExtractor($this->dakyuSelector)->extract($root);

        // 1.4: 投手・打者・次打者
        $pitchBatterPanel = new PitchBatterPanelExtractor()->extract($root);

        // 1.5: リプレイナビ
        $replayNav = new ReplayNavExtractor($this->replayNavSelector)->extract($root);

        // 3.1 コース図
        $pitchCourseChart = new PitchCourseChartExtractor($this->pitchesDetailSelector)->extract($root);

        // 3.2 投球明細テーブル
        $pitchResultTable = new PitchResultTableExtractor($this->pitchesDetailSelector)->extract($root);


        // 今後 1.3〜1.5, 3.1〜3.3 を順次追加していく想定
        return [
            'url'       => $url,
            'game_meta' => $gameMeta,
            'field_bso' => $fieldBso,
            'result_event' => $resultEvent,
            'base_runners' => $baseRunners,
            'dakyu' => $dakyu,
            'pitch_batter_panel' => $pitchBatterPanel,
            'replay_nav' => $replayNav,
            'pitch_course_chart' => $pitchCourseChart,
            'pitch_result_table' => $pitchResultTable,
        ];
    }
}
