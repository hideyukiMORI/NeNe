# Phan Suppression Cheatsheet

Quick reference for `@phan-suppress-next-line` annotations used in this codebase.
Run `composer analyze:file <file>` to check locally before pushing.

---

## よく出るエラーと使いどころ

### `PhanTypeArraySuspiciousNullable`

**出る場面**: `?array` 型の変数でキーアクセスするとき。  
`assertNotNull($row)` の後でも Phan は型を絞り込まないため毎回必要。

```php
$row = $this->cb->get($id);
$this->assertNotNull($row);
// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
$this->assertSame('hero', $row['block_type']);
```

---

### `PhanTypeMismatchArgumentNullable`

**出る場面**: `?T` 型の変数を `T` を期待するユーザー定義関数・メソッドに渡すとき。

```php
$id = $this->lock->acquire('resource', 'owner', 60); // returns ?int
$this->assertNotNull($id);
// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
$this->lock->extend($id, 60);  // extend(int $id, ...) expects non-null int
```

---

### `PhanTypeMismatchArgumentNullableInternal`

**出る場面**: `?T` 型を PHP **組み込み関数**（`strlen`, `json_decode`, `strtotime` など）に渡すとき。  
`PhanTypeMismatchArgumentNullable` と名前が似ているが **Internal** がつく点に注意。

```php
$secret = $this->aw->rotateSecret($id); // returns ?string
$this->assertNotNull($secret);
// @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal
$this->assertSame(64, strlen($secret));
```

---

### `PhanTypeMismatchDeclaredParamNullable`

**出る場面**: メソッドの実装が `?T` を受けるのに PHPDoc が `@param T` と書いているとき。

```php
// Bad: @param bool $suppress  but signature is ?bool $suppress
// Fix:
/** @param bool|null $suppress */
public function record(string $email, string $type, ?bool $suppress = null): int
```

---

### `PhanTypeMismatchDeclaredReturn`

**出る場面**: 実装の戻り値型と PHPDoc の `@return` が不一致。

```php
// Bad: @return array<string,int>  but returns int
// Fix:
/** @return int */
public function countByType(string $type): int
```

---

### `PhanTypeComparisonFromArray`

**出る場面**: `fetchAll()` の戻り値（`array|false`）を `=== false` と比較するとき。

```php
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
// @phan-suppress-next-line PhanTypeComparisonFromArray
if ($rows === false) {
    return [];
}
```

---

## 使い分けフローチャート

```
対象の型が ?T ?
├─ YES → PHP組み込み関数に渡す？
│         ├─ YES → PhanTypeMismatchArgumentNullableInternal
│         └─ NO  → PhanTypeMismatchArgumentNullable
├─ NO → ?array のキーアクセス？
│        └─ YES → PhanTypeArraySuspiciousNullable
├─ NO → fetchAll()=== false 比較？
│        └─ YES → PhanTypeComparisonFromArray
├─ NO → PHPDoc @param と実装シグネチャの nullable 不一致？
│        └─ YES → PhanTypeMismatchDeclaredParamNullable
└─ NO → PHPDoc @return と実装戻り値型の不一致？
          └─ YES → PhanTypeMismatchDeclaredReturn
```

---

## ローカル実行

```bash
# 新規クラス + テストを書いたら push 前に:
composer analyze:file -- class/xion/Foo.php tests/Unit/Xion/FooTest.php

# 全体チェック（CI と同等、約40秒）:
composer analyze
```
