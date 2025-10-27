<?php

declare(strict_types=1);

namespace App\Scraper\Score;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use App\Scraper\Score\Extractor\FieldBsoExtractor;
use App\Scraper\Score\Extractor\ResultEventExtractor;
use App\Scraper\Score\Extractor\BaseRunnersExtractor;
use App\Scraper\Score\Extractor\DakyuResultExtractor;
use App\Scraper\Score\Extractor\PitchBatterPanelExtractor;
use App\Scraper\Score\Extractor\ReplayNavExtractor;
use App\Scraper\Score\Extractor\PitchCourseChartExtractor;
use App\Scraper\Score\Extractor\PitchResultTableExtractor;


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
     * @return array 解析結果の連想配列
     * 例:
     * [
     *   'url'          => '...',
     *   'game_meta'    => [...], 
     *   'field_bso'    => [
     *                      'inning'            => 7,
     *                      'half'              => 'top' | 'bottom' | null,
     *                      'bso'               => ['b'=>2, 's'=>3, 'o'=>3],
     *                      'score_rows'        => [
     *                          ['abbr'=>'西', 'score'=>3, 'active'=>true],
     *                          ['abbr'=>'日', 'score'=>9, 'active'=>false],
     *                     ],
     *                      ],
     *  'result_event'  => [
     *                      'event_types'       => ['play', 'sub_runner', ...],
     *                      'primary_raw'       => '中安打 ＋1点',
     *                      'detail_raw'        => '140km/h カットボール、ランナー1,2塁',
     *                      'seq_in_pa'         => 3,                                   
     *                      'is_hit'            => true,      
     *                      'runs_add'          => 1,                       
     *                      'substitutions'     => [                                
     *                          ['kind'         => 'runner'|'batter'|'pitcher'|'defense',
     *                           'items'        => [
     *                               ['from' => '外崎', 'to' => '長谷川'],
     *                               ['type'=>'enter','name'=>'五十幡','to_pos'=>'中'],
     *                               ['type'=>'move','name'=>'淺間','from_pos'=>'中','to_pos'=>'左'],
     *                            ],
     *                          ],
     *                      ],
     *                      'steals'            => [                                   
     *                          [
     *                            'type'        => 'success'|'failure',
     *                            'runner'      => '外崎',
     *                          ],
     *                      ],
     *  'base_runners'  => [
     *                      'base_class'        => 'b101',
     *                      'occupied'          => ['1'=>true,'2'=>false,'3'=>true],
     *                      'runners'           => [
     *                          '1' => ['uniform_no'=>27, 'name_raw'=>'中村悠'],
     *                          '2' => ['uniform_no'=>38, 'name_raw'=>'小幡'],
     *                          '3' => ['uniform_no'=>1,  'name_raw'=>'山田'],
     *                          ],
     *                      ],
     *  'dakyu'         => [
     *                      'present'           => bool,
     *                      'dakyu_code'        => ?int,
     *                      'batted_ball_type'  => ?string,
     *                      'direction_num'     => ?int,
     *                      ],
     *  'pitch_batter_panel' => [
     *                      'pitcher'           => [
     *                          'name_raw'      => '山本由伸',
     *                          'number'        => 16,
     *                          'hand_text'     => '右投',
     *                          'bf'            => 5,
     *                          ] | null,
     *                      'batter'            => [
     *                          'name_raw'      => '村上宗隆',
     *                          'number'        => 3,
     *                          'hand_text'     => '右打',
     *                          ] | null,
     *                      'next_batter'       => '鈴木誠也',
     *                      ] | null,
     *  'replay_nav'    => [
     *                      'prev'              => ['href' => '?index=0910300', 'index' => '0910300'] | null,
     *                      'next'              => ['href' => '?index=0910401', 'index' => '0910401'] | null,
     *                      'batter_ordinal'    => 4 | null,
     *                      ],
     *  'pitch_course_chart' => [
     *                      'exists'            => bool,
     *                      'batter_box_side'   => 'left'|'right'|null,
     *                      'pitches'           => [
     *                              [
     *                                'seq_in_pa'   => int,
     *                                'bucket'      => int,
     *                                'bucket_label'=> string,
     *                                'top_px'      => float,
     *                                'left_px'     => float,
     *                              ], ...
     *                          ],
     *                      ],
     *  'pitch_result_table' => [
     *                      'exists'            => true,
     *                      'batter'            => ['name_raw'=>'中野 拓夢','hand'=>'左'],
     *                      'pitcher'           => ['name_raw'=>'大西 広樹','hand'=>'右'],
     *                      'pitches'           => [
     *                              [
     *                                'seq_in_pa'       => 1,
     *                                'pitcher_np_cum'  => 9,
     *                                'ball_class'      => 'ball2',
     *                                'category'        => 'ball',
     *                                'pitch_type'      => 'フォーク',
     *                                'speed_kmh'       => 136,
     *                                'result_label'    => 'ボール',
     *                                'result_notes'    => [],
     *                              ], ...
     *                          ],
     *                      ], 
     * ]
     */
    public function scrape(string $url, array $gameMeta = []): array
    {
        $html  = (string) $this->http->request('GET', $url)->getBody();
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
