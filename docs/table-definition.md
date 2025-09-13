# テーブル定義書

## Team

| 項目名     | データ型       | 制約 | 説明                                  |
|------------|----------------|------|---------------------------------------|
| team_id    | INT            | PK   | チームの一意識別子                      |
| team_name  | VARCHAR(100)   |      | チーム名                              |
| league     | VARCHAR(50)    |      | 所属リーグ（例：セ・リーグ、パ・リーグ）   |
| created_at | DATETIME       |      | レコード作成日時                        |
| updated_at | DATETIME       |      | 最終更新日時                          |

---

## Stadium

| 項目名       | データ型       | 制約 | 説明                                    |
|--------------|----------------|------|-----------------------------------------|
| stadium_id   | INT            | PK   | 球場の一意識別子                        |
| stadium_name | VARCHAR(100)   |      | 球場名                                  |
| is_dome      | BOOLEAN        |      | ドーム球場の場合 TRUE                    |
| created_at   | DATETIME       |      | レコード作成日時                        |
| updated_at   | DATETIME       |      | 最終更新日時                          |

---

## GameCategory

| 項目名         | データ型       | 制約 | 説明                                                         |
|----------------|----------------|------|-------------------------------------------------------------|
| category_id    | INT            | PK   | カテゴリの一意識別子                                          |
| category_name  | VARCHAR(50)    |      | 例: Official, Farm, Interleague, CS, JapanSeries, Open        |
| created_at     | DATETIME       |      | 作成日時                                                    |
| updated_at     | DATETIME       |      | 更新日時                                                    |

---

## Game

| 項目名             | データ型       | 制約 | 説明                                                        |
|--------------------|----------------|------|------------------------------------------------------------|
| game_id            | INT            | PK   | 試合の一意識別子                                             |
| season_year        | INT            |      | シーズン年度（2月始まり）                                    |
| game_date          | DATE           |      | 試合日                                                     |
| stadium_id         | INT            | FK   | 使用球場ID                                                 |
| home_team_id       | INT            | FK   | ホームチームID                                             |
| away_team_id       | INT            | FK   | アウェイチームID                                           |
| final_score_home   | INT            |      | ホームチーム最終得点                                          |
| final_score_away   | INT            |      | アウェイチーム最終得点                                          |
| status             | VARCHAR(20)    |      | 試合状態（scheduled, completed, cancelled）               |
| game_type          | VARCHAR(10)    |      | 試合種別（day, nighter）                                    |
| category_id        | INT            | FK   | 試合カテゴリID                                             |
| created_at         | DATETIME       |      | 作成日時                                                    |
| updated_at         | DATETIME       |      | 更新日時                                                    |

---

## Player

| 項目名              | データ型       | 制約 | 説明                                                         |
|---------------------|----------------|------|-------------------------------------------------------------|
| player_id           | INT            | PK   | 選手の一意識別子                                             |
| official_name       | VARCHAR(100)   |      | 最新の公式登録名（例: 坂本勇人）                              |
| display_name        | VARCHAR(100)   |      | 最新の表示名（例: 坂本勇人 または 坂本勇）                     |
| english_name        | VARCHAR(100)   |      | 最新の英語公式登録名（例: Hayato Sakamoto）                   |
| date_of_birth       | DATE           |      | 生年月日                                                    |
| height              | INT            |      | 身長 (cm)                                                  |
| weight              | INT            |      | 体重 (kg)                                                  |
| throws_left         | BOOLEAN        |      | 左投げならTRUE                                               |
| throws_right        | BOOLEAN        |      | 右投げならTRUE                                               |
| bats_left           | BOOLEAN        |      | 左打ちならTRUE                                               |
| bats_right          | BOOLEAN        |      | 右打ちならTRUE                                               |
| created_at          | DATETIME       |      | 作成日時                                                    |
| updated_at          | DATETIME       |      | 更新日時                                                    |

---

## PlayerNameHistory

| 項目名         | データ型       | 制約 | 説明                                                                                      |
|----------------|----------------|------|-------------------------------------------------------------------------------------------|
| history_id     | INT            | PK   | 履歴レコードの一意識別子                                                                   |
| player_id      | INT            | FK   | 対象選手のID                                                                              |
| name           | VARCHAR(100)   |      | 変更された名前（例: 坂本勇人, 坂本勇）                                                      |
| name_type      | VARCHAR(20)    |      | 名称の種類 ("official", "display", "english")                                             |
| effective_date | DATE           |      | この名前が有効になった日                                                                    |
| end_date       | DATE           |      | この名前の終了日（現状ならNULL）                                                            |
| created_at     | DATETIME       |      | 履歴作成日時                                                                              |
| updated_at     | DATETIME       |      | 履歴更新日時                                                                              |

---

## Roster

| 項目名         | データ型       | 制約 | 説明                                                                       |
|----------------|----------------|------|---------------------------------------------------------------------------|
| roster_id      | INT            | PK   | 所属エントリの一意識別子                                                   |
| player_id      | INT            | FK   | 選手ID                                                                    |
| team_id        | INT            | FK   | 所属チームID                                                               |
| season_year    | INT            |      | シーズン年度（2月始まり）                                                 |
| start_date     | DATE           |      | 所属開始日                                                                |
| end_date       | DATE           |      | 所属終了日（現役ならNULL）                                                 |
| uniform_number | INT            |      | 背番号                                                                    |
| position       | VARCHAR(20)    |      | 主要ポジション（公式戦、2軍、登録情報の実績に基づく算出結果）                    |
| created_at     | DATETIME       |      | レコード作成日時                                                          |
| updated_at     | DATETIME       |      | 最終更新日時                                                            |

---

## PlayerGameAppearance

| 項目名         | データ型       | 制約 | 説明                                                                     |
|----------------|----------------|------|-------------------------------------------------------------------------|
| appearance_id  | INT            | PK   | 出場記録の一意識別子                                                      |
| game_id        | INT            | FK   | 試合ID                                                                  |
| player_id      | INT            | FK   | 選手ID                                                                  |
| start_inning   | INT            |      | 出場開始イニング（例: スタメンなら1）                                      |
| end_inning     | INT            |      | 出場終了イニング（交代があればそのイニング、無ければ試合終了時のイニング）       |
| innings_played | FLOAT          |      | 出場イニング数（例: 6.2 → 6イニング＋2/3）                              |
| created_at     | DATETIME       |      | 作成日時                                                                |
| updated_at     | DATETIME       |      | 更新日時                                                                |

---

## PlayByPlay

| 項目名             | データ型       | 制約 | 説明                                                                                          |
|--------------------|----------------|------|-----------------------------------------------------------------------------------------------|
| pbp_id             | INT            | PK   | インプレーの一意識別子                                                                          |
| game_id            | INT            | FK   | 試合ID                                                                                        |
| inning             | INT            |      | イニング番号                                                                                  |
| top_bottom         | VARCHAR(1)     |      | T: 上, B: 下                                                                                  |
| count_b            | INT            |      | 現在のボール数                                                                                |
| count_s            | INT            |      | 現在のストライク数                                                                            |
| count_o            | INT            |      | 現在のアウト数                                                                                |
| runner_first_id    | INT            | FK   | 1塁走者（存在しなければNULL）                                                                   |
| runner_second_id   | INT            | FK   | 2塁走者（存在しなければNULL）                                                                   |
| runner_third_id    | INT            | FK   | 3塁走者（存在しなければNULL）                                                                   |
| event_type         | VARCHAR(50)    |      | イベント種別（例: play, substitution, pitch, steal, error, advancement, wild_pitch, passed_ball, interference） |
| created_at         | DATETIME       |      | 作成日時                                                                                      |
| updated_at         | DATETIME       |      | 更新日時                                                                                      |

---

## PitchEvent

| 項目名                | データ型       | 制約 | 説明                                                                                           |
|-----------------------|----------------|------|-----------------------------------------------------------------------------------------------|
| event_id              | INT            | PK   | 投球イベントの一意識別子                                                                        |
| pbp_id                | INT            | FK   | 関連するPlayByPlayのID                                                                          |
| pitcher_id            | INT            | FK   | 投手の選手ID                                                                                   |
| batter_id             | INT            | FK   | 打者の選手ID                                                                                   |
| pitch_velocity        | INT            |      | 球速 (km/h)                                                                                    |
| pitch_type            | VARCHAR(50)    |      | 球種（例: Fastball, Curve, Slider）                                                           |
| pitch_location_x      | FLOAT          |      | 投球位置のx座標（正規化: 0～1）                                                                  |
| pitch_location_y      | FLOAT          |      | 投球位置のy座標（正規化: 0～1）                                                                  |
| swing                 | BOOLEAN        |      | スイングしたかどうか                                                                            |
| hit_bases             | INT            |      | ヒットの場合の塁打数（0～4）                                                                     |
| contact_made          | BOOLEAN        |      | 実際にコンタクトが取れたかどうか                                                                 |
| pitcher_hand          | VARCHAR(10)    |      | 使用した腕（Left, Right, Both）                                                                 |
| batter_hand           | VARCHAR(10)    |      | 使用した腕（Left, Right, Both）                                                                 |
| pitch_count_in_inning | INT            |      | イニング内での投球番号                                                                           |
| pitch_count_in_game   | INT            |      | 試合内での通算投球番号                                                                           |
| created_at            | DATETIME       |      | 作成日時                                                                                      |
| updated_at            | DATETIME       |      | 更新日時                                                                                      |

---

## StealEvent

| 項目名        | データ型       | 制約 | 説明                                     |
|---------------|----------------|------|------------------------------------------|
| event_id      | INT            | PK   | 盗塁イベントの一意識別子                   |
| pbp_id        | INT            | FK   | 関連するPlayByPlayのID                     |
| runner_id     | INT            | FK   | 盗塁試行選手のID                          |
| steal_success | BOOLEAN        |      | 盗塁成功ならTRUE                          |
| created_at    | DATETIME       |      | 作成日時                                 |
| updated_at    | DATETIME       |      | 更新日時                                 |

---

## SubstitutionEvent

| 項目名         | データ型       | 制約 | 説明                                                                                           |
|----------------|----------------|------|-----------------------------------------------------------------------------------------------|
| event_id       | INT            | PK   | 交代イベントの一意識別子                                                                         |
| pbp_id         | INT            | FK   | 関連するPlayByPlayのID                                                                           |
| from_position  | VARCHAR(20)    |      | 交代前のポジション（例: Infield, Outfield, Pitcher, Catcher, Bench）                             |
| to_position    | VARCHAR(20)    |      | 交代後のポジション（例: Infield, Outfield, Pitcher, Catcher, Bench）                             |
| player_id      | INT            | FK   | 交代対象の選手ID                                                                              |
| appearance_id  | INT            | FK   | 関連するPlayerGameAppearanceのID（出場記録の更新に連動）                                       |
| created_at     | DATETIME       |      | 作成日時                                                                                      |
| updated_at     | DATETIME       |      | 更新日時                                                                                      |

---

## AdvancementEvent

| 項目名   | データ型  | 制約 | 説明                                             |
|----------|-----------|------|--------------------------------------------------|
| event_id | INT       | PK   | 進塁イベントの一意識別子                            |
| pbp_id   | INT       | FK   | 関連するPlayByPlayのID                              |
| player_id| INT       | FK   | 進塁対象の選手ID                                   |
| from_base| INT       |      | 進塁前の塁（例: 1=一塁）                             |
| to_base  | INT       |      | 進塁後の塁（例: 2=二塁）                             |
| created_at| DATETIME  |      | 作成日時                                         |
| updated_at| DATETIME  |      | 更新日時                                         |

---

## ErrorEvent

| 項目名             | データ型       | 制約 | 説明                                                             |
|--------------------|----------------|------|-----------------------------------------------------------------|
| event_id           | INT            | PK   | エラーイベントの一意識別子                                          |
| pbp_id             | INT            | FK   | 関連するPlayByPlayのID                                           |
| error_context      | VARCHAR(50)    |      | エラー状況（例: batted_ball, pickoff, throwing_error）              |
| defensive_position | VARCHAR(20)    |      | エラー発生時の守備ポジション                                      |
| created_at         | DATETIME       |      | 作成日時                                                         |
| updated_at         | DATETIME       |      | 更新日時                                                         |

---

## OtherEvent

| 項目名       | データ型       | 制約 | 説明                                                                     |
|--------------|----------------|------|-------------------------------------------------------------------------|
| event_id     | INT            | PK   | その他イベントの一意識別子                                               |
| pbp_id       | INT            | FK   | 関連するPlayByPlayのID                                                   |
| event_subtype| VARCHAR(50)    |      | サブタイプ（例: balk, wild_pitch, passed_ball, interference）              |
| detail       | VARCHAR(255)   |      | 補足情報                                                                |
| created_at   | DATETIME       |      | 作成日時                                                               |
| updated_at   | DATETIME       |      | 更新日時                                                               |

---

## PlayerGameStats

| 項目名         | データ型       | 制約 | 説明                                                                                         |
|----------------|----------------|------|----------------------------------------------------------------------------------------------|
| stats_id       | INT            | PK   | 統計レコードの一意識別子                                                                       |
| game_id        | INT            | FK   | 試合ID                                                                                      |
| player_id      | INT            | FK   | 選手ID                                                                                      |
| AB             | INT            |      | 打席数 (At Bats)                                                                            |
| R              | INT            |      | 得点 (Runs)                                                                                 |
| H              | INT            |      | 安打数 (Hits)                                                                               |
| doubles        | INT            |      | 二塁打数 (Doubles)                                                                           |
| triples        | INT            |      | 三塁打数 (Triples)                                                                           |
| HR             | INT            |      | 本塁打数 (Home Runs)                                                                         |
| RBI            | INT            |      | 打点 (Runs Batted In)                                                                        |
| SO             | INT            |      | 三振 (Strikeouts, batting)                                                                  |
| BB             | INT            |      | 四球 (Walks)                                                                                |
| HBP            | INT            |      | 死球 (Hit By Pitch)                                                                         |
| SacBunt        | INT            |      | 犠打 (Sacrifice Bunts)                                                                       |
| SacFly         | INT            |      | 犠飛 (Sacrifice Flies)                                                                       |
| SB             | INT            |      | 盗塁 (Stolen Bases)                                                                          |
| E              | INT            |      | 失策 (Errors)                                                                              |
| IP             | FLOAT          |      | 投球回数 (Innings Pitched)                                                                   |
| Pitches        | INT            |      | 投球数 (Total Pitches)                                                                       |
| BF             | INT            |      | 対戦打者数 (Batters Faced)                                                                    |
| H_allowed      | INT            |      | 被安打数 (Hits Allowed)                                                                      |
| HR_allowed     | INT            |      | 被本塁打数 (Home Runs Allowed)                                                               |
| K              | INT            |      | 奪三振 (Strikeouts, pitching)                                                                |
| BB_given       | INT            |      | 与四球数 (Walks Given)                                                                       |
| HBP_given      | INT            |      | 与死球数 (Hit By Pitch Given)                                                                |
| R_allowed      | INT            |      | 失点 (Runs Allowed)                                                                          |
| ER             | INT            |      | 自責点 (Earned Runs)                                                                         |
| W              | INT            |      | 勝利数 (Wins)                                                                                |
| L              | INT            |      | 敗戦数 (Losses)                                                                              |
| Holds          | INT            |      | ホールド数 (Holds)                                                                           |
| SV             | INT            |      | セーブ数 (Saves)                                                                             |
| InningsPlayed  | FLOAT          |      | 出場イニング数（PlayerGameAppearance集計値）                                                 |
| created_at     | DATETIME       |      | 統計レコード作成日時                                                                         |
| updated_at     | DATETIME       |      | 統計レコード更新日時                                                                         |

