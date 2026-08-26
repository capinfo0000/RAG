<?php
declare(strict_types=1);

namespace App\Knowledge;

use App\Llm\LlmFactory;
use App\Llm\LlmMessage;

/**
 * アップロードされた資料の本文をAIが読み、適切なタイトルとカテゴリを提案する。
 * 失敗しても例外は投げず、フォールバック（ファイル名 / カテゴリなし）を返す
 * ＝アップロード自体は止めない。
 */
final class DocumentClassifier
{
    /**
     * @param string[] $existingCategories 既存カテゴリ（あれば優先的に再利用させる）
     * @return array{title:string, category:?string}
     */
    public static function suggest(string $text, array $existingCategories = [], string $fallbackTitle = ''): array
    {
        $excerpt = mb_substr(trim($text), 0, 3000);
        if ($excerpt === '') {
            return ['title' => $fallbackTitle, 'category' => null];
        }

        $catList = $existingCategories === []
            ? '（既存カテゴリはまだありません）'
            : implode(' / ', array_slice($existingCategories, 0, 30));

        $system = <<<PROMPT
あなたは社内資料の司書です。渡された資料の本文を読み、内容にふさわしい「タイトル」と「カテゴリ」を決めてください。

【ルール】
- title: 資料の内容を端的に表す日本語の見出し。最大30文字。ファイル名・拡張子・記号の羅列は禁止。
- category: 資料の分類を表す短い日本語（最大12文字）。
- カテゴリは、内容が本当に一致する既存カテゴリ [{$catList}] があればその表記をそのまま再利用する。適切な既存カテゴリが無ければ、内容に合った短い新カテゴリ名を付ける（無理に既存へ当てはめない）。
- 本文に無い情報を創作しない。

【出力形式】
次のJSONのみを出力（前置き・コードブロック禁止）:
{"title": "タイトル", "category": "カテゴリ"}
PROMPT;

        try {
            $llm = LlmFactory::create();
            $resp = $llm->generate(
                messages: [LlmMessage::user("資料の本文:\n" . $excerpt)],
                options: [
                    'system' => $system,
                    'temperature' => 0.2,
                    'max_tokens' => 2048,
                    'thinking_budget' => 256,
                    'response_json' => true,
                ],
            );
            $data = self::parseJson($resp->content);
            $title = trim((string) ($data['title'] ?? ''));
            $category = trim((string) ($data['category'] ?? ''));

            $title = $title !== '' ? mb_substr($title, 0, 200) : $fallbackTitle;
            $category = $category !== '' ? mb_substr($category, 0, 100) : null;

            return ['title' => $title, 'category' => $category];
        } catch (\Throwable) {
            // AI失敗時はフォールバック（アップロードは継続）
            return ['title' => $fallbackTitle, 'category' => null];
        }
    }

    /** @return array<string, mixed> */
    private static function parseJson(string $content): array
    {
        $text = trim($content);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }
}
