---
# Qiita 投稿用メタ（リポジトリ内メモ — Qiita には貼らない）
title: "NeNeで固定ページとREST APIを追加する"
tags: ["PHP", "Docker", "OpenAPI", "フレームワーク", "NeNe"]
publish_target: "2026-05-27 Tue 09:00 JST"
related_issue: "#178"
prerequisite: "docs/articles/zenn-renovating-legacy-php-framework.md"
---

## はじめに

前回、[10年以上前の自作PHPフレームワークを、PHP 8.4時代向けにリフォームした話](https://zenn.dev/xioncc/articles/a2709df3e0de3b)（Zenn）で、**NeNe** のリフォーム方針とインストール手順を書きました。

この記事はその **実装編** です。

- ブラウザ向けの **固定HTMLページ** を1枚足す
- フロントエンドや外部ツール向けの **JSON REST API** を1本足す
- 公開契約として **OpenAPI** に追記する

NeNe は Laravel / Symfony の代替ではありません。URL を見れば Controller が分かる、昔ながらの小さな MVC を残しつつ、OpenAPI や CSRF など現代的な境界を足したフレームワークです。詳しい背景は Zenn 記事を読んでください。

---

## この記事のゴール

| 種類 | URL | 役割 |
| --- | --- | --- |
| HTML | `GET /page/about` | Smarty テンプレートで About ページ |
| JSON | `GET /ping/index` | 認証なしの疎通確認 API |

DB や Mapper を使った CRUD は **今回の範囲外** です。Mapper を含む続きは、末尾「次に読むもの」の **GitHub チュートリアル**（英語）を参照してください。

---

## 前提：環境が動いていること

Zenn 記事の「実際に動くところまで整えた」を済ませた状態を想定します。

NeNe を **フレームワークとして使って実装していく** 場合、次の2つをセットで使うのが基本です。

| 役割 | どこで動かす | 用途 |
| --- | --- | --- |
| **PHP / Composer / PHPUnit** | **ホスト**（WSL や Mac のターミナル） | コード編集、依存関係、単体テスト、静的解析 |
| **Apache / MySQL** | **Docker Compose** | ブラウザや curl での HTTP 確認、DB |

`composer.json` とソースはホストとコンテナで **同じファイル** です。一方 `vendor/` はホスト用とコンテナ用で **別** なので、依存パッケージを増やしたときは `composer install` をホストでもコンテナでも実行して揃えます（後述）。

```bash
git clone https://github.com/hideyukiMORI/NeNe.git
cd NeNe
composer install
docker volume create nene_composer_cache   # 初回だけ（無いと compose up が止まることがある）
docker compose up --build
```

別ターミナルでヘルスチェック:

```bash
curl -sS http://localhost:8080/health/index
```

JSON が1行で返れば OK です。見やすく整形したいときだけ、任意で `jq` を使います（`| jq .`）。`jq` は NeNe には不要で、ホスト側に無ければ `sudo apt install jq` などで入れてください。パイプせず `curl` だけでも動作確認はできます。

`healthStatus: ok` または `degraded` が返れば API 自体は起動しています。`degraded` の場合は DB 接続を確認してください。

Swagger UI: `http://localhost:8080/api-docs/`

以降、**Controller やテンプレートの編集・テスト実行はホスト**、**HTTP の疎通確認は Docker 上の NeNe** という分担で進めます。

---

## 1. 固定ページ `GET /page/about`

NeNe では URL `/page/about` が `PageController::aboutAction()` に解決されます。  
昔ながらのフロントコントローラー系と同じで、1段目は `page` のような **小文字1単語** が基本です。`private-note` のようなハイフン区切りは使えず、複数語は `privatenote` とつなげます。

### 1-1. Controller を追加

`class/controller/PageController.php`:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Xion\ControllerBase;

class PageController extends ControllerBase
{
    protected function preAction(): void
    {
        // ログイン不要の公開ページ
        $this->SESSION_CHECK = false;
    }

    public function aboutAction(): void
    {
        $this->setTitle('About NeNe');
        $this->VIEW->setString('t_heading', 'About NeNe');
        $this->VIEW->setString('t_body', 'A small legacy PHP framework for reviewable services.');
    }
}
```

`ControllerBase` は `view/source/page/about.tpl` が存在すれば自動でそのテンプレートを選びます。

### 1-2. Smarty テンプレート

`view/source/page/about.tpl`:

```smarty
{extends file='layout/app.tpl'}
{block name='content'}
                <section class="page-about">
                    <h1>{$t_heading}</h1>
                    <p>{$t_body}</p>
                </section>
{/block}
```

### 1-3. 確認

ブラウザで `http://localhost:8080/page/about` を開きます。  
タイトルと本文が layout 内に表示されれば OK です。

---

## 2. REST API `GET /ping/index`

HTML 用は `aboutAction()`、JSON 用は **HTTP メソッド名入り** の `indexGetRest()` を使います。  
NeNe では新規 REST は原則 `indexGetRest` / `indexPostRest` のように **メソッド別メソッド名** が推奨です（`indexRest()` のような旧来形は避ける）。

### 2-1. Controller を追加

`class/controller/PingController.php`:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Xion\ControllerBase;

class PingController extends ControllerBase
{
    protected function preAction(): void
    {
        $this->SESSION_CHECK = false;
    }

    public function indexGetRest(): array
    {
        return $this->API_RESPONSE->success([
            'message' => 'pong',
            'service' => 'NeNe',
        ]);
    }
}
```

ルーティング対応:

```text
GET /ping/index  →  PingController::indexGetRest()
```

### 2-2. curl で確認

```bash
curl -sS http://localhost:8080/ping/index
```

（任意）見やすくする: `curl -sS http://localhost:8080/ping/index | jq .`

成功時のイメージ（NeNe の共通 JSON エンベロープ）:

```json
{
  "Result": true,
  "Data": {
    "status": "success",
    "errorCode": "",
    "message": "pong",
    "service": "NeNe"
  }
}
```

`401 SESSION-CLOSED` になった場合は `preAction()` で `SESSION_CHECK = false` になっているか確認してください。

---

## 3. OpenAPI に追記する

公開 REST は `docs/api/openapi.yaml` が正本です。Swagger UI はこのファイルから生成されます。

`paths:` に次を追加します（既存のインデントに合わせてください）:

```yaml
  /ping/index:
    get:
      tags:
        - Ping
      summary: Public ping endpoint for connectivity checks
      operationId: ping
      responses:
        "200":
          description: Ping succeeded.
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/PingSuccessEnvelope"
              examples:
                ok:
                  value:
                    Result: true
                    Data:
                      status: success
                      errorCode: ""
                      message: pong
                      service: NeNe
```

`components/schemas` にエンベロープと Data 部分を足します（既存の `HealthSuccessEnvelope` などと同じ形）:

```yaml
    PingSuccessEnvelope:
      type: object
      required: [Result, Data]
      properties:
        Result:
          type: boolean
        Data:
          $ref: "#/components/schemas/PingSuccessData"
    PingSuccessData:
      type: object
      required: [status, errorCode, message, service]
      properties:
        status:
          type: string
          enum: [success]
        errorCode:
          type: string
        message:
          type: string
        service:
          type: string
```

`tags:` セクションに `- name: Ping` も追加します。

保存後、`http://localhost:8080/api-docs/` を再読み込みし、`GET /ping/index` が載っていることを確認します。

---

## 4. テストを1本足す（任意だが推奨）

さきほど curl で `/ping/index` を手動確認しました。同じ内容を PHPUnit に残しておくと、あとからルーティングや JSON 形状を壊しても気づきやすくなります。

NeNe では HTTP 向けのテストを `tests/Http/` に置くのが流儀です。最小例として `tests/Http/PingRuntimeTest.php` を追加します。

```php
<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use PHPUnit\Framework\TestCase;

final class PingRuntimeTest extends TestCase
{
    public function testPingReturnsPong(): void
    {
        $baseUrl = getenv('NENE_HTTP_BASE_URL') ?: 'http://localhost:8080';
        $json = file_get_contents($baseUrl . '/ping/index');
        self::assertIsString($json);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pong', $payload['Data']['message'] ?? null);
    }
}
```

テスト本体は、curl と同じく **起動中の NeNe に HTTP でアクセス** します。違いは、返ってきた JSON の `message` が `pong` かどうかを PHPUnit が自動で見てくれる点だけです。

NeNe の HTTP テストは、叩き先 URL を環境変数 **`NENE_HTTP_BASE_URL`** で渡します。Docker が `8080` を公開しているなら、**ホストのターミナル**（`docker compose up` はそのまま）で次を実行します。

```bash
NENE_HTTP_BASE_URL=http://localhost:8080 vendor/bin/phpunit tests/Http/PingRuntimeTest.php
```

ここでの PHPUnit は、前提で入れた **ホスト側の `vendor/bin/phpunit`** です。curl と同じく「ホストから Docker 上の NeNe を叩く」形になります。

PR を出す前は、ホストで次も回しておきましょう。

```bash
composer test
NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http
composer analyze
```

`composer test:http` は HTTP スモーク一式（今回足した Ping テストも含む）を走らせます。PHP をホストに入れず Docker だけで完結させたい場合は、同じコマンドを `docker compose exec app` 経由に置き換えればよく、そのときだけ URL はコンテナ内の `http://localhost:80` になります。

---

## 5. 実装の整理

NeNe で機能を足すときの典型順序は次のとおりです。

1. **Controller** — HTTP 境界（HTML は `*Action`、JSON は `*GetRest` / `*PostRest`）
2. **Template / 静的応答** — Smarty または `API_RESPONSE`
3. **Service / Mapper** — ビジネスルールや SQL（今回は省略）
4. **error_codes.php** — 失敗コードと HTTP ステータス
5. **openapi.yaml** — 公開契約
6. **tests** — ルーティングと JSON 形状

フレームワークコア（`class/xion/`）は触らず、アプリ側（`class/controller/`、`view/source/`、`docs/api/`）だけで完結させるのが NeNe の想定です。

---

## 次に読むもの

- 一覧・作成・404 まで含む REST + Mapper 例: [Building a Service with NeNe](https://github.com/hideyukiMORI/NeNe/blob/main/docs/tutorials/building-a-service.md)
- 外部クライアント向け CSRF / Cookie: [API reference client](https://github.com/hideyukiMORI/NeNe/blob/main/docs/api/reference-client.md)
- リフォームの背景: [Zenn 記事](https://zenn.dev/xioncc/articles/a2709df3e0de3b)

---

## まとめ

- **固定ページ** — `PageController` + `view/source/page/about.tpl`、URL から Controller が追える
- **REST API** — `PingController::indexGetRest()`、メソッド名で HTTP が明示される
- **OpenAPI** — 公開 JSON は yaml 更新がセット

NeNe は「魔法のルーティング」より **レビューしやすい形** を優先した小さなフレームワークです。まずは1ページと1 API を足して、自分のサービス用に広げてみてください。

---

## リンク

- リポジトリ: https://github.com/hideyukiMORI/NeNe
- デモ: https://nene-php.com/
- リリース: https://github.com/hideyukiMORI/NeNe/releases/tag/v0.2.0

フィードバックは GitHub Issues へ歓迎します。
