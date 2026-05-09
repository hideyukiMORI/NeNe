---
title: "10年以上前の自作PHPフレームワークを、PHP 8.4時代向けにリフォームした話"
emoji: "🛠️"
type: "tech"
topics: ["php", "framework", "legacy", "openapi", "docker"]
published: true
---

## はじめに

10年以上前に作った小さなPHPフレームワークを、今のPHP開発で使える形にリフォームしました。

名前は **NeNe** です。

NeNe は Laravel や Symfony の代わりを目指すフレームワークではありません。むしろ逆で、昔ながらのPHPフレームワークに慣れた人が、URLを見てControllerを探し、Actionを読んで、MapperでSQLを追えるような、見通しの良い小さな構造を残すことを大事にしています。

今回やったことは、古い作法を全部捨てることではありません。

古い構造の中にも、今でも価値があるものがあります。
一方で、当時のままだと危ない部分、テストしにくい部分、今の開発フローに合わない部分もあります。

そこで、NeNe では次のような方針でリフォームしました。

- URLルーティング、Controller、Smarty、軽量Mapperのような分かりやすい構造は残す。
- Composer、Docker、PHPUnit、OpenAPI、CSRF、パスワードハッシュなど、今必要な安全性と開発体験を足す。
- 大きなフレームワークに置き換えず、小さなサービスをレビューしやすく作れる形にする。

この記事では、NeNe をどういう考えでリフォームしたかを書きます。

## レビューしやすさを重視した理由

個人的にも、大規模な開発現場でレビューがボトルネックになり、苦労した経験があります。

コードそのものの良し悪し以前に、まず「この実装はどういう設計思想で書かれているのか」「どこに責務が分かれているのか」を読み解くところから始まると、レビューの負荷はかなり高くなります。

もちろん、大規模開発やモダンな設計パターンが悪いという話ではありません。むしろ、それらが必要な場面はたくさんあります。

ただ、小さなサービスや少人数の開発では、実装の自由度を少し狭めて、誰が書いても似た形になるようにする方が、結果的に変更を安全に見やすくなることがあります。

NeNe のリフォームでは、この「同じ形で書けること」をかなり大事にしました。

## 古い作法は、本当に全部捨てるべきなのか

今のPHP開発では、Laravel や Symfony のような大きなフレームワークを使うのが自然な選択肢です。

ルーティング、DI、ORM、バリデーション、キュー、イベント、認可、テスト支援など、必要なものが一通り揃っています。新規サービスや大規模なチーム開発では、そうした選択が正しい場面も多いと思います。

ただ、すべてのプロジェクトが大きなフルスタックフレームワークを必要としているわけではありません。

たとえば、小さな業務ツール、社内向けの管理画面、少人数で保守するWebサービス、既存の古いPHP資産に近い感覚で扱いたいコードベースでは、もっと素朴な構造の方が読みやすいことがあります。

昔ながらのPHP MVCには、単純さがあります。

```text
/{controller}/{action}
```

このURLを見れば、だいたいどのControllerのどのActionを読めばいいか分かる。
これは古い作法ですが、小さなサービスでは今でもかなり強い性質です。

NeNe では、この分かりやすさを残すことにしました。

## NeNeで残したもの

NeNe はリライトではなく、リフォームです。

残したものは、主に次のようなものです。

- フロントコントローラー
- `/{controller}/{action}` のURLルーティング
- `indexAction()` のようなController Action
- Smartyによるサーバレンダリング
- 軽量なMapper / Model
- 小さく読み切れるコード量

Smartyを残したのは、既存のサーバレンダリング資産や昔ながらのPHP開発の感覚を活かしつつ、必要な部分だけReactやRESTで補える形にしたかったからです。

ここでいうMapperは、EloquentやDoctrineのような重いORMではなく、SQLと行データの変換を小さく閉じ込めるための軽量なデータアクセス層です。

たとえば `/todo/index` というURLなら、`TodoController` の `index` 系のメソッドを読みに行けばいい、という感覚です。

HTMLページなら:

```php
public function indexAction(): void
```

REST APIなら:

```php
public function indexGetRest(): array
public function indexPostRest(): array
```

のように、Controllerのメソッド名に役割が出ます。

これは最新のルーティングライブラリや属性ベースの定義と比べると、かなり素朴です。ですが、ルート定義を別ファイルで探さなくても、URL、Controller、Actionの対応が見えやすいという利点があります。

NeNeでは、この「見れば分かる」感覚をプロジェクトの中心に置いています。

## 現代化したもの

一方で、古いコードをそのまま残したわけではありません。

今のPHP開発で最低限必要だと思うものは、かなり足しました。

主なものは次の通りです。

- PHP 8.4 をDocker開発ターゲットにする
- Composer / PSR-4 autoload
- Docker Compose によるローカル環境
- MySQL 8.4 と SQLite fallback
- phpMyAdmin
- PHPUnit
- Phan
- PHP CS Fixer
- OpenAPI 3.1
- Swagger UI
- セッションCookie属性の明示
- `password_hash()` / `password_verify()`
- CSRF token
- JSON-only REST response
- エラーコードカタログ
- `HttpResponse` / `HttpEmitter` / `HttpTermination`
- `TransactionManager`

方向性としては、次の一文に近いです。

> 構造は昔ながら、境界と安全性は現代的にする。

たとえば、RESTレスポンスはControllerが直接 `header()` や `exit()` を呼ぶ形ではなく、レスポンス境界へ寄せました。
エラーもControllerがHTTPステータスやメッセージをその場で書くのではなく、サーバ側のエラーカタログに寄せています。

HTTPレスポンス周りは、現時点ではPSR-7やPSR-15を導入せず、NeNeの規模に合わせた小さな独自境界に留めています。将来、他ライブラリとの連携が必要になったときに、IssueとADRで標準インターフェース採用を検討する方針です。

静的解析には Phan を使っています。PHPStan や Psalm が主流であることは理解しつつ、まずは既存コードに少しずつ型と解析の足場を作る目的で、ベースライン運用しやすい形から始めています。古いコードでは一度に理想形へ寄せるより、既存の警告を棚卸ししながら、新しい変更で悪化させない運用を先に作る方が現実的でした。

古い作法を残すと言っても、危ない実装まで残したいわけではありません。

## 現代化の一例: RESTはメソッド名で明示する

NeNeでは、REST APIのControllerメソッド名にHTTPメソッドを入れます。

例:

```php
public function loginPostRest(): array
public function indexGetRest(): array
public function indexPostRest(): array
public function itemDeleteRest(): array
```

この形にすると、Controllerを見たときに「このActionはどのHTTPメソッドを受け付けるのか」が分かりやすくなります。

以前の古い作法として `{action}Rest()` のような、HTTPメソッドを問わず受ける形もありました。
しかし新規実装では、原則として `indexGetRest()` や `indexPostRest()` のようなメソッド別の書き方を推奨しています。

これは「誰が書いても似た形になる」ことを重視しているためです。

小さなサービスでは、実装者ごとの好みで構造が揺れすぎると、レビュー時にまず「この人はどういう設計思想で書いたのか」を読む必要が出てきます。

NeNeでは、ControllerはHTTP境界、Serviceはユースケース、MapperはSQL境界、OpenAPIは契約、テストは確認、という分担をなるべく揃えます。

## AI時代に効くのは、魔法ではなく見通しの良さ

NeNeでは最近、「AI-readable」という言葉も使っています。

ただし、これは「AIが全部うまく作ってくれる」という意味ではありません。
AI支援で書いたコードでも、人間がレビューしやすい形に揃えたい、という意味です。

AIにコードを書かせると、動きそうなコードはすぐ出てきます。
でも、そのコードがプロジェクトの作法に合っているか、レビューしやすいか、保守しやすいかは別問題です。

NeNeが狙っているのは、次のような状態です。

- URLを見ればControllerに辿れる。
- Controllerを見ればHTTP入力とレスポンスが分かる。
- Serviceを見ればユースケースのルールが分かる。
- Mapperを見ればSQLとDB境界が分かる。
- OpenAPIを見れば外部契約が分かる。
- テストを見れば期待する振る舞いが分かる。

つまり、AIが書いたとしても、人間がレビューするときの視線の流れが変わらないようにしたい、ということです。

これは派手な仕組みではありません。
むしろ、実装の自由度を少し狭めて、普通の変更が同じ形に見えるようにする考え方です。

## 実際に動くところまで整えた

![NeNe sample page](https://static.zenn.studio/user-upload/a6e11333226e-20260509.png)

*NeNe sample page*

今回のリフォームでは、思想だけで終わらせず、実際に動くところまで整えました。

ローカルでは:

```sh
docker compose up --build
```

で、アプリ、MySQL、phpMyAdmin、Swagger UI を含む開発環境が立ち上がります。

また、従来型のApache/PHPサーバに `git clone` して動かす手順もドキュメント化しました。

```sh
git clone git@github.com:hideyukiMORI/NeNe.git
cd NeNe
composer install --no-dev --optimize-autoloader
php cli/setupDatabase.php --env=.env --yes
```

公開サンプルとして、[nene-php.com](https://nene-php.com/) でも動かしています。

現在のリリースは `v0.2.0` です。

`v0.1.0` では、まず clone して動かせる基盤、Docker、OpenAPI、テスト、サーバインストール手順を整えました。
`v0.2.0` では、MITライセンス化、phpMyAdmin、CSRF判定境界、TransactionManager、レビューしやすい小規模サービス開発の方向性を整理しました。

## NeNeが目指していないもの

NeNeは、何でもできるフレームワークを目指していません。

:::message
NeNeは Laravel / Symfony の代替ではありません。
現時点では、`git clone` して小さなサービスの土台にする使い方を想定しています。
:::

明確に、やらないこともあります。

- Laravel / Symfony の代替にはしない。
- 大規模エンタープライズ向けのフルスタックフレームワークにはしない。
- ORMやプラグインエコシステムを急いで足さない。
- SPA-firstの構成にはしない。
- URLルーティングを隠すような大きな抽象化はしない。
- 既存アプリに `composer require` して使うライブラリとしては、現時点では位置づけない。

このあたりを曖昧にすると、読む人に誤解されやすくなります。
だからこそ、NeNeでは「何ではないか」もREADMEやドキュメントに書くようにしています。

## これから

今後は、Controller / Service / Mapper / OpenAPI / Test の作法が一通り見える参照実装を整えていく予定です。

目的は、機能を増やすことそのものではありません。
小さなサービスを追加するときに、どこに何を書けばよいか、どこをレビューすればよいかを、もっと分かりやすくすることです。

古いPHPの作法は、全部捨てる必要はないと思っています。

URLを見て、Controllerを見て、Actionを読んで、MapperでSQLを確認する。
この素朴な流れには、今でも価値があります。

そのうえで、危ない部分と開発体験だけを現代化する。

NeNeは、そのための小さなリフォームプロジェクトです。

## リンク

@[card](https://github.com/hideyukiMORI/NeNe)

@[card](https://nene-php.com/)

@[card](https://github.com/hideyukiMORI/NeNe/releases/tag/v0.2.0)

@[card](https://github.com/hideyukiMORI/NeNe/blob/main/docs/tutorials/building-a-service.md)
