<?php
declare(strict_types=1);

namespace App\Llm;

use App\Config;

/**
 * 複数の Gemini APIキーを順番に使うプール（フェイルオーバー／冗長化）。
 * ★本番は「課金を有効にした有料枠」のキーを使う（有料枠は入出力を学習に使われない）。
 *   有料キーを複数持つのは冗長化・上限緩和として正当。無料枠の複数アカウント分散は
 *   ToS上グレーなので検証時のみ。
 *
 *   - 429（利用上限）を返したキーは一定時間クールダウンに入れ、次のキーへ自動で回す。
 *   - クールダウン状態は storage/cache に「キーのSHA-256ハッシュ」だけで永続化する。
 *     ★キー本体はメモリ上のみ。ログ・状態ファイルへ平文で残さない（流出対策）。
 *
 * キーの読み込み元（この順で連結・重複除去・空除去）:
 *   1. 呼び出し側から渡された primaryKey（Factory / DB 解決値）
 *   2. .env GEMINI_API_KEY, GEMINI_API_KEY_2 .. GEMINI_API_KEY_10
 *   3. .env GEMINI_API_KEYS（カンマ区切りの一括指定）
 *
 * 注意: 本番は有料枠キー（学習非利用）。無料枠の複数アカウント分散はToS上グレー＝検証用に留める。
 */
final class GeminiKeyPool
{
    private const STATE_FILE = '/storage/cache/gemini_key_cooldown.json';
    private const START_FILE = '/storage/cache/gemini_key_start.json';
    private const MAX_NUMBERED = 10;

    /**
     * 429以外の「一時的/キー固有」失敗（401/403/5xx/接続不可）でキーを一旦休ませる秒数。
     * 短めにして、次リクエストで復帰を試せるようにする（無効キーでも自己修復する）。
     */
    public const TRANSIENT_COOLDOWN = 60;

    /** @var string[] 実キー（メモリ上のみ・順序付き） */
    private array $keys;

    /** @param string[] $keys */
    private function __construct(array $keys)
    {
        $this->keys = $keys;
    }

    public static function fromConfig(string $primaryKey = ''): self
    {
        $keys = [];
        $add = static function (string $k) use (&$keys): void {
            $k = trim($k);
            if ($k !== '' && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        };

        if ($primaryKey !== '') {
            $add($primaryKey);
        }
        $add((string) Config::get('GEMINI_API_KEY', ''));
        for ($i = 2; $i <= self::MAX_NUMBERED; $i++) {
            $add((string) Config::get('GEMINI_API_KEY_' . $i, ''));
        }
        $csv = (string) Config::get('GEMINI_API_KEYS', '');
        if ($csv !== '') {
            foreach (explode(',', $csv) as $k) {
                $add($k);
            }
        }

        return new self($keys);
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    public function total(): int
    {
        return count($this->keys);
    }

    /**
     * クールダウン中でない（＝いま使える）キーを「ラウンドロビン順」で返す。
     *
     * 前回成功したキーの次から始め、末尾まで行ったら先頭へ回り込む（1→2→3→1…）。
     * クールダウン中のキーは飛ばす。全キーを最大1周だけ巡り、使い切ったら生成は終わる。
     * yield のキーは「元の登録インデックス」なので、成功時に advanceAfter() へ渡せる。
     *
     * @return \Generator<int, string> index => key
     */
    public function available(): \Generator
    {
        $n = count($this->keys);
        if ($n === 0) {
            return;
        }
        $state = $this->loadState();
        $now = time();
        $start = $this->loadStart() % $n;
        for ($i = 0; $i < $n; $i++) {
            $idx = ($start + $i) % $n;
            $key = $this->keys[$idx];
            $until = $state[self::hash($key)] ?? 0;
            if ($until <= $now) {
                yield $idx => $key;
            }
        }
    }

    /**
     * 成功したキーの「次」を次回の開始位置として記録する（負荷を全キーへ分散＝ラウンドロビン）。
     */
    public function advanceAfter(int $index): void
    {
        $n = count($this->keys);
        if ($n === 0) {
            return;
        }
        $this->saveStart(($index + 1) % $n);
    }

    /**
     * このレスポンスなら「次のキーへ回す」べきか（＝そのキー固有の問題か）。
     *   - null（接続失敗/タイムアウト）・429（レート/クォータ）・401/403（認証/権限）・5xx（一時的サーバ障害）→ 回す
     *   - 400 でも本文が「APIキー無効」を示す場合（Geminiは無効キーを 400/API_KEY_INVALID で返す）→ 回す
     *   - それ以外の 400/404 等（リクエスト不正・モデル無し等、全キーで同じ結果）→ 回さず即エラー（真因を隠さない）
     *
     * @param string|null $body レスポンス本文（400の切り分けに使う）
     */
    public static function shouldRotate(?int $status, ?string $body = null): bool
    {
        if ($status === null) {
            return true;
        }
        if ($status === 429 || $status === 401 || $status === 403) {
            return true;
        }
        if ($status >= 500 && $status <= 599) {
            return true;
        }
        if ($status === 400 && $body !== null
            && preg_match('/API_KEY_INVALID|api key not valid|API key expired|invalid authentication/i', $body) === 1) {
            return true; // 無効/期限切れキー → 次のキーへ
        }
        return false;
    }

    /** キーはあるが全てクールダウン中か（＝実質「全枠到達」）。 */
    public function allCoolingDown(): bool
    {
        if ($this->keys === []) {
            return false;
        }
        foreach ($this->available() as $_) {
            return false;
        }
        return true;
    }

    /** 指定キーをクールダウンに登録（本体でなくハッシュで記録）。 */
    public function markCooldown(string $key, int $seconds): void
    {
        $state = $this->loadState();
        $state[self::hash($key)] = time() + max(1, $seconds);
        $this->saveState($state);
    }

    /**
     * 429レスポンス本文からクールダウン秒数を決める。
     * 日次上限(PerDay)は長め、分あたり等は短めに休ませる。
     */
    public static function cooldownFor(?string $body): int
    {
        if ($body !== null && preg_match('/per\s*[-_]?\s*day/i', $body) === 1) {
            return 6 * 3600; // 日次枠: 6時間休ませる（枠リセット待ち）
        }
        return 90; // 分あたり等: 短時間で復帰
    }

    private static function hash(string $key): string
    {
        return substr(hash('sha256', $key), 0, 24);
    }

    /** ラウンドロビンの開始インデックス（前回成功キーの次）を読む。 */
    private function loadStart(): int
    {
        $path = dirname(__DIR__, 2) . self::START_FILE;
        if (!is_file($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return 0;
        }
        $i = (int) trim($raw);
        return $i >= 0 ? $i : 0;
    }

    private function saveStart(int $index): void
    {
        $path = dirname(__DIR__, 2) . self::START_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, (string) $index, LOCK_EX);
    }

    private function stateFilePath(): string
    {
        // src/Llm -> demo ルート
        return dirname(__DIR__, 2) . self::STATE_FILE;
    }

    /** @return array<string, int> hash => 有効期限(unixtime) */
    private function loadState(): array
    {
        $path = $this->stateFilePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        // 期限切れは捨てる
        $now = time();
        $out = [];
        foreach ($data as $h => $until) {
            if (is_string($h) && is_int($until) && $until > $now) {
                $out[$h] = $until;
            }
        }
        return $out;
    }

    /** @param array<string, int> $state */
    private function saveState(array $state): void
    {
        $path = $this->stateFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($state, JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
