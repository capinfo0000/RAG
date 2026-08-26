# ローカル開発環境セットアップ（Windows + XAMPP）

## 現状の確認

```
PHP 7.4.33 (cli) ← C:\pleiades\2022-12\xampp\php\php.exe
Composer 2.9.5
```

PHP 7.4 では本デモは動作しません（`readonly` / `match` 式 / `str_starts_with` などPHP 8.2機能を使用）。
**最新 XAMPP（PHP 8.2+）の追加インストール** が必要です。

---

## ステップ1: 最新版XAMPPダウンロード

公式: https://www.apachefriends.org/jp/download.html

- **XAMPP for Windows 8.2.12 以上** を選ぶ（PHP 8.2 同梱版）
- 32ビット/64ビットは OS に合わせて（Windows 11 64bit → 64bit版）

---

## ステップ2: インストール

1. ダウンロードした `xampp-windows-x64-X.X.X-X-VS16-installer.exe` を**管理者として実行**
2. **インストール先** は既存の pleiades と分けるため `C:\xampp` を推奨
   - pleiades の XAMPP（C:\pleiades\2022-12\xampp）はそのまま温存できる
3. コンポーネント選択
   - ✅ Apache
   - ✅ MySQL
   - ✅ PHP
   - ✅ phpMyAdmin
   - その他（Mercury / Tomcat / Perl）は **チェック外す** で軽量化
4. インストール完了後、コントロールパネルから Apache と MySQL を起動

---

## ステップ3: PATH 設定（重要）

**新しい PHP 8 を CLI で使えるようにする。** pleiades の PHP 7.4 より優先させる必要がある。

### 方法A: システム環境変数を直接編集（推奨）

1. スタートメニュー → 「環境変数を編集」
2. 「ユーザー環境変数」または「システム環境変数」の **Path** を編集
3. **`C:\xampp\php` を先頭に追加**（pleiades の PATH エントリより上）
4. 既存ターミナルを閉じて、新しいターミナルを開く

### 方法B: プロジェクト内シェルだけ切替

`demo/use-xampp82.bat` を実行してから作業：

```bat
@echo off
set PATH=C:\xampp\php;%PATH%
echo PHP version:
php -v
echo.
echo Composer version:
composer --version
cmd /k
```

---

## ステップ4: 動作確認

新しいターミナルで:

```powershell
php -v
# → PHP 8.2.x または 8.3.x が表示されればOK

composer --version
# → Composer 2.x

cd C:\work\claude\案件マッチングサイト\【リモート可】【東京】AIチャットボットソリューション提案のご相談\demo
composer install
# → vendor/ ディレクトリが作成され、依存パッケージがインストールされる
```

---

## ステップ5: Gemini 動作確認

```powershell
php scripts/test_gemini.php
```

期待出力（例）:

```
==========================================
 Gemini Provider 動作確認
==========================================
Model: gemini-2.5-flash

▼ generate() テスト
Q: あなたは何ができるAIですか？1文で簡潔に答えて。
A: 私は質問への回答、文章生成、要約、翻訳などができるAIです。
  tokens: in=23 out=42 | finish=STOP | 850ms

▼ stream() テスト
... （ストリーミングで文字が逐次出力される）

▼ system instruction + 多ターン テスト
A: 「RAG Chatbot Demo」の料金は月額10万円です。

==========================================
 ✅ すべてのテスト成功
==========================================
```

---

## ステップ6: MySQL データベース作成

XAMPP の MySQL（MariaDB）を起動した状態で：

```powershell
# phpMyAdmin: http://localhost/phpmyadmin/ にアクセス、または下記コマンド
"C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE rag_chatbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"C:\xampp\mysql\bin\mysql.exe" -u root rag_chatbot < sql\schema.sql
```

`demo/.env` の `DB_USER` / `DB_PASSWORD` を XAMPP のものに合わせる（デフォルトは `root` / 空文字列）。

---

## トラブルシュート

### `php` が依然として 7.4 を指す

- PATH の順序を確認: `where php` で先頭が `C:\xampp\php\php.exe` になっていればOK
- 古いターミナルでは PATH が更新されない。完全に閉じてから開き直す
- 念のため再起動

### `composer install` で `php >= 8.2 required` エラー

- `php -v` で 7.4 が出ているはず → PATH 設定を見直す

### XAMPPのApacheが起動しない（ポート競合）

- pleiades の XAMPP がポート80を使っていないか確認、競合していたら片方止める
- もしくは httpd.conf でポートを 8080 等に変更

---

## 完了したら

「XAMPPインストール完了」と教えてください。`composer install` → `test_gemini.php` 実行までこちらで進めます。
