<?php
declare(strict_types=1);

/**
 * チャット e2e（実フロー）検証。Generator::generate() を実際に流し、
 * 全文書RAGで「どの文書が丸ごと文脈に入ったか」と最終回答・引用を確認する。
 * トークンは逐次表示せず集約のみ表示。
 *
 * 実行: cd demo && C:\Users\yonekura\xampp\php\php.exe scripts/test_e2e_chat.php "質問文"
 * 注意: ローカルLLM(qwen3.6 思考モデル)のため数十秒〜数分かかる。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Models\Conversation;
use App\Models\Database;
use App\Rag\Generator;

Config::load(__DIR__ . '/..');

$question = $argv[1] ?? '育児休業は最長でどれくらい取得できますか？';
echo "==========================================\n";
echo " チャット e2e 検証（全文書RAG・実フロー）\n";
echo "==========================================\n";
echo "Q: {$question}\n\n";

$conv = Conversation::create('e2e-test');
$conversationId = (int) $conv['id'];

$generator = Generator::fromConfig();
$start = microtime(true);
$answer = '';
foreach ($generator->generate($question, $conversationId, []) as $event) {
    switch ($event['type'] ?? '') {
        case 'sources':
            echo "▼ 文脈に入った出典（[N]=文書）\n";
            foreach ($event['chunks'] as $c) {
                printf(
                    "  [%d] doc=%s「%s」 excerpt=%d字\n",
                    $c['index'],
                    $c['document_id'] ?? '-',
                    $c['document_title'] ?? '-',
                    mb_strlen((string) ($c['excerpt'] ?? '')),
                );
            }
            echo "\n";
            break;
        case 'token':
            $answer .= $event['delta'] ?? '';
            break;
        case 'refusal':
            echo "▼ ガードレール拒否: " . ($event['message'] ?? '') . "\n";
            break;
        case 'error':
            echo "▼ エラー: " . ($event['message'] ?? '') . "\n";
            break;
        case 'done':
            $elapsed = (int) ((microtime(true) - $start) * 1000);
            echo "▼ 回答（" . mb_strlen($answer) . "字 / {$elapsed}ms）\n";
            echo rtrim($answer) . "\n\n";
            echo "▼ 引用: " . count($event['citations'] ?? []) . " 件\n";
            foreach ($event['citations'] ?? [] as $cit) {
                printf("  [%s] doc=%s「%s」\n", $cit['index'] ?? '?', $cit['document_id'] ?? '-', $cit['document_title'] ?? '-');
            }
            $u = $event['usage'] ?? null;
            if ($u) {
                printf("  tokens: in=%s out=%s\n", $u['input'] ?? '-', $u['output'] ?? '-');
            }
            break;
    }
}

// テスト会話は後片付け（messages → conversation の順で削除）
$pdo = Database::pdo();
$pdo->prepare('DELETE FROM messages WHERE conversation_id = :id')->execute([':id' => $conversationId]);
$pdo->prepare('DELETE FROM conversations WHERE id = :id')->execute([':id' => $conversationId]);
echo "\n==========================================\n";
echo " ✅ e2e 完了（テスト会話 id={$conversationId} は削除済み）\n";
echo "==========================================\n";
