# テーブル定義書

---

## Club

| 項目名     | データ型     | 制約 | 説明                         |
|------------|--------------|------|------------------------------|
| club_id    | INT          | PK   | クラブの一意識別子（球団）     |
| club_name  | VARCHAR(100) |      | 例: 読売ジャイアンツ           |
| created_at | DATETIME     |      | 作成日時                     |
| updated_at | DATETIME     |      | 更新日時                     |

---

## Team

| 項目名     | データ型     | 制約 | 説明                                             |
|------------|--------------|------|--------------------------------------------------|
| team_id    | INT          | PK   | チームの一意識別子                                |
| club_id    | INT          | FK   | 所属クラブID（Club.club_id）                     |
| team_name  | VARCHAR(100) |      | チーム名（例: 読売ジャイアンツ（一軍）など）        |
| league     | VARCHAR(50)  |      | 所属リーグ（例：Central、Eastern など）            |
| level      | VARCHAR(20)  |      | レベル（First, Farm など）                        |
| created_at | DATETIME     |      | レコード作成日時                                  |
| updated_at | DATETIME     |      | 最終更新日時                                      |

---

## Stadium

| 項目名       | データ型     | 制約 | 説明                         |
|--------------|--------------|------|------------------------------|
| stadium_id   | INT          | PK   | 球場の一意識別子             |
| stadium_name | VARCHAR(100) |      | 球場名                       |
| is_dome      | BOOLEAN      |      | ドーム球場の場合 TRUE         |
| created_at   | DATETIME     |      | レコード作成日時             |
| updated_at   | DATETIME     |      | 最終更新日時                 |

---

## GameCategory

| 項目名        | データ型     | 制約 | 説明                                                        |
|---------------|--------------|------|-------------------------------------------------------------|
| category_id   | INT          | PK   | カテゴリの一意識別子                                         |
| category_name | VARCHAR(50)  |      | 例: Official, Farm, Interleague, CS, JapanSeries, Open       |
| created_at    | DATETIME     |      | 作成日時                                                    |
| updated_at    | DATETIME     |      | 更新日時                                                    |

---

## Game

| 項目名           | データ型     | 制約 | 説明                                                |
|------------------|--------------|------|-----------------------------------------------------|
| game_id          | INT          | PK   | 試合の一意識別子                                     |
| season_year      | INT          |      | シーズン年度（2月始まり）                             |
| game_date        | DATE         |      | 試合日                                              |
| stadium_id       | INT          | FK   | 使用球場ID（Stadium.stadium_id）                     |
| home_team_id     | INT          | FK   | ホームチームID（Team.team_id）                       |
| away_team_id     | INT          | FK   | アウェイチームID（Team.team_id）                     |
| final_score_home | INT          |      | ホームチーム最終得点                                  |
| final_score_away | INT          |      | アウェイチーム最終得点                                  |
| status           | VARCHAR(20)  |      | 試合状態（scheduled, completed, cancelled）         |
| is_nighter       | BOOLEAN      |      | ナイターかどうか（17時以降開始ならTRUE）              |
| category_id      | INT          | FK   | 試合カテゴリID（GameCategory.category_id）           |
| source_yahoo_id  | VARCHAR(20)  |      | Yahoo!の試合ID（例：2021029801）                      |
| created_at       | DATETIME     |      | 作成日時                                             |
| updated_at       | DATETIME     |      | 更新日時                                             |

---

## Player

| 項目名         | データ型     | 制約 | 説明                                   |
|----------------|--------------|------|----------------------------------------|
| player_id      | INT          | PK   | 選手の一意識別子                        |
| official_name  | VARCHAR(100) |      | 最新の公式登録名（例: 坂本勇人）         |
| display_name   | VARCHAR(100) |      | 最新の表示名（例: 坂本 or 坂本勇）       |
| english_name   | VARCHAR(100) |      | 最新の英語公式登録名（例: Hayato Sakamoto） |
| date_of_birth  | DATE         |      | 生年月日                                |
| height         | INT          |      | 身長 (cm)                              |
| weight         | INT          |      | 体重 (kg)                              |
| throws_left    | BOOLEAN      |      | 左投げならTRUE                          |
| throws_right   | BOOLEAN      |      | 右投げならTRUE                          |
| bats_left      | BOOLEAN      |      | 左打ちならTRUE                          |
| bats_right     | BOOLEAN      |      | 右打ちならTRUE                          |
| created_at     | DATETIME     |      | 作成日時                                |
| updated_at     | DATETIME     |      | 更新日時                                |

---

## PlayerNameHistory

| 項目名         | データ型     | 制約 | 説明                                                         |
|----------------|--------------|------|--------------------------------------------------------------|
| history_id     | INT          | PK   | 履歴レコードの一意識別子                                      |
| player_id      | INT          | FK   | 対象選手のID（Player.player_id）                              |
| name           | VARCHAR(100) |      | 変更された名前（例: 坂本勇人, 坂本勇）                         |
| name_type      | VARCHAR(20)  |      | 名称の種類 ("official", "display", "english")             |
| effective_date | DATE         |      | この名前が有効になった日                                       |
| end_date       | DATE         |      | この名前の終了日（現状ならNULL）                               |
| created_at     | DATETIME     |      | 履歴作成日時                                                 |
| updated_at     | DATETIME     |      | 履歴更新日時                                                 |

---

## ClubMembership

| 項目名        | データ型 | 制約 | 説明                                      |
|---------------|----------|------|-------------------------------------------|
| membership_id | INT      | PK   | 在籍レコードID                             |
| player_id     | INT      | FK   | 選手ID（Player.player_id）                  |
| club_id       | INT      | FK   | クラブID（Club.club_id）                    |
| start_date    | DATE     |      | 所属開始日                                  |
| end_date      | DATE     |      | 所属終了日（現役ならNULL）                   |
| uniform_number| INT      |      | 背番号（任意、年次で変動可）                 |
| created_at    | DATETIME |      | 作成日時                                    |
| updated_at    | DATETIME |      | 更新日時                                    |
> 注: 期間重複の検知はアプリ/ETL側で行う（DB制約では表現が難しい）。


---

## PlayerGameAppearance

| 項目名          | データ型  | 制約 | 説明                                                                 |
|-----------------|-----------|------|----------------------------------------------------------------------|
| appearance_id   | INT       | PK   | 出場記録の一意識別子                                                   |
| game_id         | INT       | FK   | 試合ID（Game.game_id）                                                |
| player_id       | INT       | FK   | 選手ID（Player.player_id）                                            |
| team_id         | INT       | FK   | その試合で所属するチーム（Team.team_id）                               |
| position_id     | INT       | FK   | 出場ポジションID（Position.position_id）                 |
| start_inning    | INT       |      | 出場開始イニング（例: スタメンなら1）                                   |
| end_inning      | INT       |      | 出場終了イニング（交代があればそのイニング、無ければ最終イニング）        |
| outs_recorded   | INT       |      | **出場アウト数**（er.pu準拠。3で除してInningsPlayedに換算可能）      |
| created_at      | DATETIME  |      | 作成日時                                                               |
| updated_at      | DATETIME  |      | 更新日時                                                               |
> UNIQUE: (game_id, player_id, start_inning, position_id)


---

## PlayByPlay

| 項目名                | データ型    | 制約 | 説明                                                                 |
|-----------------------|-------------|------|----------------------------------------------------------------------|
| pbp_id                | INT         | PK   | インプレーの一意識別子                                                |
| game_id               | INT         | FK   | 試合ID（Game.game_id）                                                |
| inning                | INT         |      | イニング番号                                                           |
| top_bottom            | VARCHAR(1)  |      | T: 上, B: 下                                                           |
| pbp_sequence          | INT         |      | イニング内シーケンス番号（1開始）                                       |
| anchor_pitch_sequence | INT         |      | 投球シーケンス番号（0開始：非投球イベントもあるため）                    |
| count_b               | INT         |      | 現在のボール数                                                         |
| count_s               | INT         |      | 現在のストライク数                                                     |
| count_o               | INT         |      | 現在のアウト数                                                         |
| batter_id             | INT         | FK   | 打者の選手ID（Player.player_id）                                       |
| pitcher_id            | INT         | FK   | 投手の選手ID（Player.player_id）                                       |
| runner_first_id       | INT         | FK   | 1塁走者（NULL可）                                                      |
| runner_second_id      | INT         | FK   | 2塁走者（NULL可）                                                      |
| runner_third_id       | INT         | FK   | 3塁走者（NULL可）                                                      |
| event_type            | VARCHAR(50) |      | イベント種別（play, substitution, pitch, steal, error, advancement, wild_pitch, passed_ball, interference） |
| created_at            | DATETIME    |      | 作成日時                                                               |
| updated_at            | DATETIME    |      | 更新日時                                                               |
> UNIQUE: (game_id, inning, top_bottom, pbp_sequence)

---

## PitchEvent

| 項目名                | データ型     | 制約 | 説明                                     |
|-----------------------|--------------|------|------------------------------------------|
| event_id              | INT          | PK   | 投球イベントの一意識別子                  |
| pbp_id                | INT          | FK   | 関連するPlayByPlayのID（PlayByPlay.pbp_id）|
| pitcher_id            | INT          | FK   | 投手の選手ID（Player.player_id）          |
| batter_id             | INT          | FK   | 打者の選手ID（Player.player_id）          |
| pitch_velocity        | INT          |      | 球速 (km/h)                               |
| pitch_type            | VARCHAR(50)  |      | 球種（Fastball, Curve, Slider など）       |
| pitch_location_x      | FLOAT        |      | 投球位置X（正規化: 0～1）                 |
| pitch_location_y      | FLOAT        |      | 投球位置Y（正規化: 0～1）                 |
| swing                 | BOOLEAN      |      | スイング有無                               |
| hit_bases             | INT          |      | ヒットの場合の塁打数（0～4）               |
| contact_made          | BOOLEAN      |      | コンタクト成立有無                         |
| pitcher_hand          | VARCHAR(10)  |      | 投手の利き腕（Left, Right, Both）          |
| batter_hand           | VARCHAR(10)  |      | 打者の利き腕（Left, Right, Both）          |
| pitch_count_in_inning | INT          |      | イニング内投球番号                          |
| pitch_count_in_game   | INT          |      | 試合内通算投球番号                          |
| created_at            | DATETIME     |      | 作成日時                                   |
| updated_at            | DATETIME     |      | 更新日時                                   |
> UNIQUE: (pbp_id) — PBP 1件に最大1

---

## StealEvent

| 項目名        | データ型 | 制約 | 説明                                    |
|---------------|----------|------|-----------------------------------------|
| event_id      | INT      | PK   | 盗塁イベントの一意識別子                 |
| pbp_id        | INT      | FK   | 関連するPlayByPlayのID                   |
| runner_id     | INT      | FK   | 盗塁試行選手のID                         |
| attempted_base| INT      |      | 試行塁（2=二塁, 3=三塁, 4=本塁）          |
| steal_success | BOOLEAN  |      | 盗塁成功ならTRUE                         |
| created_at    | DATETIME |      | 作成日時                                 |
| updated_at    | DATETIME |      | 更新日時                                 |

---

## SubstitutionEvent

| 項目名        | データ型     | 制約 | 説明                                                           |
|---------------|--------------|------|----------------------------------------------------------------|
| event_id      | INT          | PK   | 交代イベントの一意識別子                                         |
| pbp_id        | INT          | FK   | 関連するPlayByPlayのID                                           |
| from_position_id | INT          | FK   | 交代前ポジションID（Position.position_id）                     |
| to_position_id   | INT          | FK   | 交代後ポジションID（Position.position_id）                     |
| player_id     | INT          | FK   | 交代対象の選手ID                                                 |
| appearance_id | INT          | FK   | 関連するPlayerGameAppearance ID                                   |
| created_at    | DATETIME     |      | 作成日時                                                         |
| updated_at    | DATETIME     |      | 更新日時                                                         |
> UNIQUE: (appearance_id) — 途中出場のみ起点

---

## AdvancementEvent

| 項目名   | データ型 | 制約 | 説明                 |
|----------|----------|------|----------------------|
| event_id | INT      | PK   | 進塁イベントの一意識別子 |
| pbp_id   | INT      | FK   | 関連するPlayByPlayのID |
| player_id| INT      | FK   | 進塁対象の選手ID        |
| from_base| INT      |      | 進塁前の塁（1=一塁 等）   |
| to_base  | INT      |      | 進塁後の塁（2=二塁 等）   |
| created_at| DATETIME|      | 作成日時               |
| updated_at| DATETIME|      | 更新日時               |

---

## ErrorEvent

| 項目名             | データ型     | 制約 | 説明                                             |
|--------------------|--------------|------|--------------------------------------------------|
| event_id           | INT          | PK   | エラーイベントの一意識別子                         |
| pbp_id             | INT          | FK   | 関連するPlayByPlayのID                             |
| player_id          | INT          | FK   | エラーを犯した選手のID                             |
| position_id        | INT          | FK   | エラー発生時の守備ポジションID（Position.position_id）           |
| error_context      | VARCHAR(50)  |      | エラー状況（batted_ball, pickoff, throwing_error） |
| created_at         | DATETIME     |      | 作成日時                                          |
| updated_at         | DATETIME     |      | 更新日時                                          |

---

## OtherEvent

| 項目名        | データ型     | 制約 | 説明                                                             |
|---------------|--------------|------|------------------------------------------------------------------|
| event_id      | INT          | PK   | その他イベントの一意識別子                                         |
| pbp_id        | INT          | FK   | 関連するPlayByPlayのID                                            |
| event_subtype | VARCHAR(50)  |      | サブタイプ（balk, wild_pitch, passed_ball, interference 等）       |
| detail        | VARCHAR(255) |      | 補足情報                                                          |
| created_at    | DATETIME     |      | 作成日時                                                          |
| updated_at    | DATETIME     |      | 更新日時                                                          |

---

## PlayerGameBattingStats

| 項目名   | データ型     | 制約 | 説明                                   |
|----------|--------------|------|----------------------------------------|
| stats_id | INT          | PK   | 統計レコードの一意識別子                 |
| game_id  | INT          | FK   | 試合ID（Game.game_id）                  |
| player_id| INT          | FK   | 選手ID（Player.player_id）              |
| PA       | INT          |      | 打席数 (Plate Appearance)               |
| AB       | INT          |      | 打数 (At Bat)                           |
| H        | INT          |      | 安打数 (Hit)                            |
| B1       | INT          |      | 一塁打数 (One-base Hit)                 |
| B2       | INT          |      | 二塁打数 (Two-base Hit)                 |
| B3       | INT          |      | 三塁打数 (Three-base Hit)               |
| HR       | INT          |      | 本塁打数 (Home Run)                     |
| R        | INT          |      | 得点 (Run)                              |
| RBI      | INT          |      | 打点 (Runs Batted In)                   |
| BB       | INT          |      | 四球 (Base on Ball/Walk)                |
| IBB      | INT          |      | 故意四球・敬遠 (Intentional Base on Ball)|
| SO       | INT          |      | 三振 (Strike Out)                       |
| HBP      | INT          |      | 死球 (Hit By Pitch)                     |
| SH       | INT          |      | 犠打 (Sacrifice Hit)                    |
| SF       | INT          |      | 犠飛 (Sacrifice Fly)                    |
| GDP      | INT          |      | 併殺打 (Grounded into Double Play)      |
| SB       | INT          |      | 盗塁 (Steal a Base)                     |
| CS       | INT          |      | 盗塁死 (Caught Stealing)                |
| created_at | DATETIME   |      | 統計レコード作成日時                     |
| updated_at | DATETIME   |      | 統計レコード更新日時                     |
> UNIQUE: (game_id, player_id)

---

## PlayerGamePitchingStats

| 項目名   | データ型     | 制約 | 説明                                   |
|----------|--------------|------|----------------------------------------|
| stats_id | INT          | PK   | 統計レコードの一意識別子                 |
| game_id  | INT          | FK   | 試合ID（Game.game_id）                  |
| player_id| INT          | FK   | 選手ID（Player.player_id）              |
| W        | INT          |      | 勝利 (Win) 0 or 1                       |
| L        | INT          |      | 敗戦 (Lose) 0 or 1                      |
| G        | INT          |      | 登板 (Game) 0 or 1                      |
| GS       | INT          |      | 先発登板 (Games Started) 0 or 1         |
| CG       | INT          |      | 完投 (Complete Game) 0 or 1              |
| ShO      | INT          |      | 完封 (Shutout) 0 or 1                    |
| SV       | INT          |      | セーブ数 (Save) 0 or 1                   |
| HLD      | INT          |      | ホールド数 (Hold) 0 or 1                 |
| outs_recorded | INT     |      | 出場アウト数（3で除してInningsPlayed換算） |
| TBF      | INT          |      | 対戦打者数 (Total Batters Faced)        |
| H        | INT          |      | 被安打数 (Hits Allowed)                 |
| R        | INT          |      | 失点 (Runs Allowed)                     |
| ER       | INT          |      | 自責点 (Earned Runs)                    |
| HR       | INT          |      | 被本塁打数 (Home Runs Allowed)          |
| BB       | INT          |      | 与四球数 (Walks and Given)              |
| IBB      | INT          |      | 故意四球・敬遠数 (Intentional BB Given) |
| HBP      | INT          |      | 与死球数 (Hit By Pitch given)           |
| WP       | INT          |      | ワイルドピッチ数 (Wild Pitch)           |
| BK       | INT          |      | ボーク数 (Balk)                         |
| SO       | INT          |      | 奪三振 (Strikeout)                      |
| SB       | INT          |      | 許盗塁 (Stolen Base Allowed)            |
| CS       | INT          |      | 盗塁刺 (Caught Stealing)                |
| Pitches  | INT          |      | 投球数 (Total Pitches)                  |
| created_at | DATETIME   |      | 統計レコード作成日時                     |
| updated_at | DATETIME   |      | 統計レコード更新日時                     |
> UNIQUE: (game_id, player_id)

---

## PlayerGameFieldingStats

| 項目名   | データ型     | 制約 | 説明                                   |
|----------|--------------|------|----------------------------------------|
| stats_id | INT          | PK   | 統計レコードの一意識別子                 |
| game_id  | INT          | FK   | 試合ID（Game.game_id）                  |
| player_id| INT          | FK   | 選手ID（Player.player_id）              |
| G        | INT          |      | 試合数 (Games)                          |
| GS       | INT          |      | 先発出場 (Games Started)                |
| outs_recorded | INT     |      | 出場アウト数（3で除してInningsPlayed換算） |
| E        | INT          |      | 失策 (Errors)                           |
| SB       | INT          |      | 許盗塁 (Stolen Base Allowed) 捕手        |
| CS       | INT          |      | 盗塁刺 (Caught Stealing) 捕手            |
| WP       | INT          |      | 暴投・ワイルドピッチ (Wild Pitch) 捕手   |
| PB       | INT          |      | 捕逸・パスボール (Passed Ball) 捕手      |
| created_at | DATETIME   |      | 統計レコード作成日時                     |
| updated_at | DATETIME   |      | 統計レコード更新日時                     |
> UNIQUE: (game_id, player_id)

---
---

## Position

| 項目名       | データ型     | 制約 | 説明                                  |
|--------------|--------------|------|---------------------------------------|
| position_id  | INT          | PK   | ポジションID                           |
| position_name| VARCHAR(20)  |      | ポジション名 (P, C, 1B, 2B, 3B, SS, LF, CF, RF, DH, PH, PR) |
| created_at   | DATETIME     |      | 作成日時                               |
| updated_at   | DATETIME     |      | 更新日時                               |

---

## 制約・設計メモ（er.pu の note 反映）

- **PlayByPlay**: UNIQUE (game_id, inning, top_bottom, pbp_sequence)
- **PitchEvent**: UNIQUE (pbp_id) — PBP 1件に最大1
- **PlayerGameBattingStats**: UNIQUE (game_id, player_id)
- **PlayerGamePitchingStats**: UNIQUE (game_id, player_id)
- **PlayerGameFieldingStats**: UNIQUE (game_id, player_id)
- **TeamGameBattingStats**: UNIQUE (game_id, team_id)
- **TeamGamePitchingStats**: UNIQUE (game_id, team_id)
- **TeamGameFieldingStats**: UNIQUE (game_id, team_id)
- **PlayerNameHistory**: UNIQUE (player_id, name_type, effective_date)
- **PlayerGameAppearance**: UNIQUE (game_id, player_id, start_inning, position_id)
- **SubstitutionEvent**: UNIQUE (appearance_id)
- **ClubMembership**: 期間重複はアプリ/ETLで検知（DBでは困難）

---
