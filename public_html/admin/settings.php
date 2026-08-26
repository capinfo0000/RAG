<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
// このページはLLM/プロバイダ/モデル/トークン等の内部情報を扱うためベンダー専用。
// 顧客がプラン原価・利益率を逆算する材料（1回/上限トークン数・モデル名）を露出させない。
admin_require_vendor();

use App\Embedding\EmbeddingFactory;
use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Models\Settings;

// プロバイダー定義（POST処理で参照するので先に定義する必要あり）
$llmProviders = [
    'groq' => [
        'name' => 'Groq',
        'subtitle' => 'Llama 3.3 70B / Mixtral',
        'free' => true,
        'default_model' => 'llama-3.3-70b-versatile',
        'help' => '完全無料・超高速・クレカ不要',
        'key_url' => 'https://console.groq.com/keys',
        'needs_api_key' => true,
        'needs_base_url' => false,
    ],
    'gemini' => [
        'name' => 'Google Gemini',
        'subtitle' => 'gemini-2.5-flash / pro',
        'free' => true,
        'default_model' => 'gemini-2.5-flash',
        'help' => '無料枠あり(15RPM/1500RPD/1M TPM)',
        'key_url' => 'https://aistudio.google.com/apikey',
        'needs_api_key' => true,
        'needs_base_url' => false,
    ],
    'anthropic' => [
        'name' => 'Anthropic Claude',
        'subtitle' => 'claude-sonnet-4-5 / opus',
        'free' => false,
        'default_model' => 'claude-sonnet-4-5',
        'help' => '本番推奨・最高精度・Citations公式対応',
        'key_url' => 'https://console.anthropic.com/settings/keys',
        'needs_api_key' => true,
        'needs_base_url' => false,
    ],
    'openai' => [
        'name' => 'OpenAI GPT',
        'subtitle' => 'gpt-4o-mini / gpt-4o',
        'free' => false,
        'default_model' => 'gpt-4o-mini',
        'help' => '汎用性高い',
        'key_url' => 'https://platform.openai.com/api-keys',
        'needs_api_key' => true,
        'needs_base_url' => false,
    ],
    'ollama' => [
        'name' => 'ローカルLLM',
        'subtitle' => 'Ollama / LM Studio / vLLM / 互換サーバ',
        'free' => true,
        'default_model' => 'llama3.2:latest',
        'help' => 'APIキー不要・Base URLが必須・機密データに最適',
        'key_url' => null,
        'needs_api_key' => false,
        'needs_base_url' => true,
    ],
];
$embeddingProviders = [
    'gemini' => [
        'name' => 'Google Gemini',
        'subtitle' => 'text-embedding-004（768次元）',
        'free' => true,
        'default_model' => 'text-embedding-004',
        'help' => '無料・LLMと同じAPIキーで動く',
        'key_url' => 'https://aistudio.google.com/apikey',
        'needs_api_key' => true,
        'shared_with_llm' => true,
    ],
    'openai' => [
        'name' => 'OpenAI',
        'subtitle' => 'text-embedding-3-small（1536次元）',
        'free' => false,
        'default_model' => 'text-embedding-3-small',
        'help' => '有料・高精度',
        'key_url' => 'https://platform.openai.com/api-keys',
        'needs_api_key' => true,
        'shared_with_llm' => true,
    ],
    'voyage' => [
        'name' => 'Voyage AI',
        'subtitle' => 'voyage-3.5-lite（1024次元）',
        'free' => false,
        'default_model' => 'voyage-3.5-lite',
        'help' => 'Anthropic公式推奨',
        'key_url' => 'https://www.voyageai.com/',
        'needs_api_key' => true,
        'shared_with_llm' => false,
    ],
    'ollama' => [
        'name' => 'ローカル (Ollama)',
        'subtitle' => 'nomic-embed-text（768次元）',
        'free' => true,
        'default_model' => 'nomic-embed-text',
        'help' => '完全ローカル・無料',
        'key_url' => null,
        'needs_api_key' => false,
        'shared_with_llm' => true,
    ],
    'none' => [
        'name' => '無効',
        'subtitle' => 'ベクトル検索なし（BM25のみで動作）',
        'free' => true,
        'default_model' => '',
        'help' => 'ベクトル検索を使わず全文検索のみで動かす',
        'key_url' => null,
        'needs_api_key' => false,
        'shared_with_llm' => false,
    ],
];

$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_llm') {
        // 入力受け取り
        $newProvider = (string) ($_POST['llm_provider'] ?? '');
        $newModel = (string) ($_POST['llm_model'] ?? '');
        $newBaseUrl = (string) ($_POST['llm_base_url'] ?? '');
        $newApiKey = (string) ($_POST['llm_api_key'] ?? '');
        $newTemp = (string) ($_POST['llm_temperature'] ?? '');
        $newMax = (string) ($_POST['llm_max_tokens'] ?? '');

        // APIキー「変更しない」場合は既存DBの暗号化キーを復号して使う
        if ($newApiKey === '' || $newApiKey === '********') {
            try {
                $effectiveKey = (string) (Settings::effectiveApiKey('llm_api_key_encrypted', '') ?? '');
            } catch (\Throwable) {
                $effectiveKey = '';
            }
        } else {
            $effectiveKey = $newApiKey;
        }

        // 必須項目チェック
        $providerInfo = $llmProviders[$newProvider] ?? null;
        if ($providerInfo === null) {
            $message = '❌ プロバイダーが選択されていません。';
            $messageType = 'error';
        } elseif ($providerInfo['needs_api_key'] && $effectiveKey === '') {
            $message = '❌ ' . $providerInfo['name'] . ' は APIキーが必須です。';
            $messageType = 'error';
        } elseif ($providerInfo['needs_base_url'] && trim($newBaseUrl) === '') {
            $message = '❌ ' . $providerInfo['name'] . ' は Base URL が必須です。';
            $messageType = 'error';
        } else {
            // 接続テスト → 成功時のみ保存
            try {
                $testProvider = LlmFactory::build(
                    providerName: $newProvider,
                    apiKey: $effectiveKey,
                    model: $newModel !== '' ? $newModel : null,
                    baseUrl: $newBaseUrl !== '' ? $newBaseUrl : null,
                );
                $resp = $testProvider->generate(
                    messages: [LlmMessage::user('OK?')],
                    options: ['temperature' => 0.0, 'max_tokens' => 5],
                );

                // ここまで来たら接続成功 → DB に保存
                Settings::set('llm_provider', $newProvider);
                Settings::set('llm_model', $newModel);
                Settings::set('llm_temperature', $newTemp);
                Settings::set('llm_max_tokens', $newMax);
                Settings::set('llm_base_url', $newBaseUrl);
                if ($newApiKey !== '' && $newApiKey !== '********') {
                    Settings::setEncrypted('llm_api_key_encrypted', $newApiKey);
                }

                $tokensUsed = ($resp->inputTokens ?? 0) + ($resp->outputTokens ?? 0);
                $message = sprintf(
                    '✅ 接続テスト成功 → 設定を保存しました [%s / %s]（テスト消費: %d tokens）',
                    $testProvider->getProviderName(),
                    $testProvider->getModelName(),
                    $tokensUsed,
                );
                $messageType = 'success';
            } catch (\Throwable $e) {
                error_log('[settings.save_llm: test failed] ' . $e::class . ': ' . \App\Llm\LlmException::sanitize($e->getMessage()));
                $rawMsg = \App\Llm\LlmException::sanitize($e->getMessage());
                $message = '❌ 接続テストに失敗したため保存しませんでした：' . $rawMsg;
                $messageType = 'error';
            }
        }

    } elseif ($action === 'save_llm2') {
        // セカンダリLLM（複雑な質問の高精度ルーティング先）＋ ルーティングトグル
        $routingEnabled = isset($_POST['llm_routing_enabled']) ? '1' : '0';
        $newProvider = (string) ($_POST['llm2_provider'] ?? '');
        $newModel = (string) ($_POST['llm2_model'] ?? '');
        $newBaseUrl = (string) ($_POST['llm2_base_url'] ?? '');
        $newApiKey = (string) ($_POST['llm2_api_key'] ?? '');

        // ルーティングON/OFFは常に保存
        Settings::set('llm_routing_enabled', $routingEnabled);

        if ($newProvider === '') {
            // セカンダリ未設定 → クリア（適応ルーティングは無効＝プライマリ単独）
            Settings::set('llm2_provider', '');
            Settings::set('llm2_model', '');
            Settings::set('llm2_base_url', '');
            $message = '✅ セカンダリLLMを未設定にしました（適応ルーティング無効・プライマリ単独運用）';
            $messageType = 'success';
        } else {
            // APIキー「変更しない」場合は既存DBの暗号化キーを使う
            if ($newApiKey === '' || $newApiKey === '********') {
                try {
                    $effectiveKey = (string) (Settings::effectiveApiKey('llm2_api_key_encrypted', '') ?? '');
                } catch (\Throwable) {
                    $effectiveKey = '';
                }
            } else {
                $effectiveKey = $newApiKey;
            }

            $providerInfo = $llmProviders[$newProvider] ?? null;
            if ($providerInfo === null) {
                $message = '❌ セカンダリのプロバイダーが不正です。';
                $messageType = 'error';
            } elseif ($providerInfo['needs_api_key'] && $effectiveKey === '') {
                $message = '❌ セカンダリ: ' . $providerInfo['name'] . ' は APIキーが必須です。';
                $messageType = 'error';
            } elseif ($providerInfo['needs_base_url'] && trim($newBaseUrl) === '') {
                $message = '❌ セカンダリ: ' . $providerInfo['name'] . ' は Base URL が必須です。';
                $messageType = 'error';
            } else {
                // 接続テスト → 成功時のみ保存
                try {
                    $testProvider = LlmFactory::build(
                        providerName: $newProvider,
                        apiKey: $effectiveKey,
                        model: $newModel !== '' ? $newModel : null,
                        baseUrl: $newBaseUrl !== '' ? $newBaseUrl : null,
                    );
                    $resp = $testProvider->generate(
                        messages: [LlmMessage::user('OK?')],
                        options: ['temperature' => 0.0, 'max_tokens' => 5],
                    );

                    Settings::set('llm2_provider', $newProvider);
                    Settings::set('llm2_model', $newModel);
                    Settings::set('llm2_base_url', $newBaseUrl);
                    if ($newApiKey !== '' && $newApiKey !== '********') {
                        Settings::setEncrypted('llm2_api_key_encrypted', $newApiKey);
                    }

                    $message = sprintf(
                        '✅ セカンダリLLM 接続テスト成功 → 保存しました [%s / %s]（ルーティング: %s）',
                        $testProvider->getProviderName(),
                        $testProvider->getModelName(),
                        $routingEnabled === '1' ? 'ON' : 'OFF',
                    );
                    $messageType = 'success';
                } catch (\Throwable $e) {
                    error_log('[settings.save_llm2: test failed] ' . $e::class . ': ' . \App\Llm\LlmException::sanitize($e->getMessage()));
                    $rawMsg = \App\Llm\LlmException::sanitize($e->getMessage());
                    $message = '❌ セカンダリの接続テストに失敗したため保存しませんでした：' . $rawMsg;
                    $messageType = 'error';
                }
            }
        }

    } elseif ($action === 'save_embedding') {
        $newProvider = (string) ($_POST['embedding_provider'] ?? '');
        $newModel = (string) ($_POST['embedding_model'] ?? '');
        $newApiKey = (string) ($_POST['embedding_api_key'] ?? '');

        $providerInfo = $embeddingProviders[$newProvider] ?? null;

        if ($newProvider === 'none') {
            // 'none' はテスト不要、即保存
            Settings::set('embedding_provider', 'none');
            Settings::set('embedding_model', '');
            $message = '✅ Embedding を無効化しました（BM25のみで動作）';
            $messageType = 'success';
        } elseif ($providerInfo === null) {
            $message = '❌ プロバイダーが選択されていません。';
            $messageType = 'error';
        } else {
            // APIキー解決（変更しない場合はDB → LLM用キーをフォールバック）
            if ($newApiKey === '' || $newApiKey === '********') {
                try {
                    $effectiveKey = (string) (Settings::effectiveApiKey('embedding_api_key_encrypted', '') ?? '');
                    if ($effectiveKey === '' && ($providerInfo['shared_with_llm'] ?? false)) {
                        $effectiveKey = (string) (Settings::effectiveApiKey('llm_api_key_encrypted', '') ?? '');
                    }
                } catch (\Throwable) {
                    $effectiveKey = '';
                }
            } else {
                $effectiveKey = $newApiKey;
            }

            if (($providerInfo['needs_api_key'] ?? false) && $effectiveKey === '') {
                $message = '❌ ' . $providerInfo['name'] . ' は APIキーが必須です（LLM側のキー流用も含めて未設定）。';
                $messageType = 'error';
            } else {
                // 接続テスト
                // ollama/local の場合は llm_base_url を流用する（LM Studio は LLM と Embedding 同居）
                $embBaseUrl = null;
                if (in_array($newProvider, ['ollama', 'local'], true)) {
                    $dbBase = (string) (Settings::get('llm_base_url', '') ?? '');
                    if (trim($dbBase) !== '') {
                        $embBaseUrl = trim($dbBase);
                    }
                }
                try {
                    $emb = \App\Embedding\EmbeddingFactory::build(
                        providerName: $newProvider,
                        apiKey: $effectiveKey,
                        model: $newModel !== '' ? $newModel : null,
                        baseUrl: $embBaseUrl,
                    );
                    if ($emb === null) {
                        throw new \RuntimeException('Embedding provider build failed');
                    }
                    $vec = $emb->embedOne('テスト', 'document');
                    if (count($vec) === 0) {
                        throw new \RuntimeException('Embedding returned empty vector');
                    }

                    Settings::set('embedding_provider', $newProvider);
                    Settings::set('embedding_model', $newModel);
                    if ($newApiKey !== '' && $newApiKey !== '********') {
                        Settings::setEncrypted('embedding_api_key_encrypted', $newApiKey);
                    }

                    $message = sprintf(
                        '✅ 接続テスト成功 → 設定を保存しました [%s / %s, %d次元]',
                        $emb->getProviderName(),
                        $emb->getModelName(),
                        count($vec),
                    );
                    $messageType = 'success';
                } catch (\Throwable $e) {
                    error_log('[settings.save_embedding: test failed] ' . $e::class . ': ' . \App\Llm\LlmException::sanitize($e->getMessage()));
                    $rawMsg = \App\Llm\LlmException::sanitize($e->getMessage());
                    $message = '❌ 接続テストに失敗したため保存しませんでした：' . $rawMsg;
                    $messageType = 'error';
                }
            }
        }

    } elseif ($action === 'test_llm') {
        try {
            $provider = LlmFactory::create();
            $resp = $provider->generate(
                messages: [LlmMessage::user('「動作確認OK」とだけ答えて。')],
                options: ['temperature' => 0.0, 'max_tokens' => 50],
            );
            $message = sprintf(
                '✅ LLM接続OK [%s / %s]: %s (in=%s, out=%s)',
                $provider->getProviderName(),
                $provider->getModelName(),
                trim($resp->content),
                $resp->inputTokens ?? '-',
                $resp->outputTokens ?? '-',
            );
            $messageType = 'success';
        } catch (\Throwable $e) {
            error_log('[settings.test_llm] ' . $e::class . ': ' . \App\Llm\LlmException::sanitize($e->getMessage()));
            $rawMsg = \App\Llm\LlmException::sanitize($e->getMessage());
            $message = '❌ LLM接続エラー: ' . $rawMsg;
            $messageType = 'error';
        }

    } elseif ($action === 'test_embedding') {
        try {
            $emb = EmbeddingFactory::create();
            if ($emb === null) {
                $message = 'Embeddingプロバイダーは "none" に設定されています（BM25のみで動作）';
                $messageType = 'info';
            } else {
                $vec = $emb->embedOne('動作確認テスト', 'document');
                $message = sprintf(
                    '✅ Embedding OK [%s / %s]: 次元=%d 先頭=[%s, …]',
                    $emb->getProviderName(),
                    $emb->getModelName(),
                    count($vec),
                    implode(', ', array_map(fn($v) => number_format((float) $v, 4), array_slice($vec, 0, 3))),
                );
                $messageType = 'success';
            }
        } catch (\Throwable $e) {
            error_log('[settings.test_embedding] ' . $e::class . ': ' . \App\Llm\LlmException::sanitize($e->getMessage()));
            $rawMsg = \App\Llm\LlmException::sanitize($e->getMessage());
            $message = '❌ Embedding接続エラー: ' . $rawMsg;
            $messageType = 'error';
        }
    }
}

// 現在の設定
$cur = [
    'llm_provider' => Settings::get('llm_provider', 'groq'),
    'llm_model' => Settings::get('llm_model', 'llama-3.3-70b-versatile'),
    'llm_temperature' => Settings::get('llm_temperature', '0.3'),
    'llm_max_tokens' => Settings::get('llm_max_tokens', '2048'),
    'llm_base_url' => Settings::get('llm_base_url', ''),
    'llm_api_key_present' => (Settings::get('llm_api_key_encrypted', '') ?? '') !== '',
    'embedding_provider' => Settings::get('embedding_provider', 'gemini'),
    'embedding_model' => Settings::get('embedding_model', 'text-embedding-004'),
    'embedding_api_key_present' => (Settings::get('embedding_api_key_encrypted', '') ?? '') !== '',
    // セカンダリLLM（複雑な質問の高精度ルーティング先）＋ルーティングトグル
    'llm_routing_enabled' => (Settings::get('llm_routing_enabled', '1') ?? '1') !== '0',
    'llm2_provider' => Settings::get('llm2_provider', '') ?? '',
    'llm2_model' => Settings::get('llm2_model', '') ?? '',
    'llm2_base_url' => Settings::get('llm2_base_url', '') ?? '',
    'llm2_api_key_present' => (Settings::get('llm2_api_key_encrypted', '') ?? '') !== '',
];

// JSへ渡すための簡略マップ（プロバイダー定義は冒頭で済み）
$llmJs = json_encode(array_map(fn($p) => [
    'default_model' => $p['default_model'],
    'help' => $p['help'],
    'key_url' => $p['key_url'],
    'needs_api_key' => $p['needs_api_key'],
    'needs_base_url' => $p['needs_base_url'],
], $llmProviders), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$embJs = json_encode(array_map(fn($p) => [
    'default_model' => $p['default_model'],
    'help' => $p['help'],
    'key_url' => $p['key_url'],
    'needs_api_key' => $p['needs_api_key'],
], $embeddingProviders), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>LLM/Embedding設定 - 管理画面</title>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-4xl mx-auto px-6 py-8">
  <h1 class="text-2xl font-bold mb-2">⚙ LLM / Embedding 動的切替</h1>
  <p class="text-sm text-gray-600 mb-6">プロバイダーを選択して、必要な情報（APIキー or Base URL）だけを入力してください。</p>

  <?php if ($message): ?>
    <div class="mb-6 p-4 rounded border <?= match ($messageType) {
        'success' => 'bg-green-50 border-green-200 text-green-800',
        'error' => 'bg-red-50 border-red-200 text-red-800',
        default => 'bg-blue-50 border-blue-200 text-blue-800',
    } ?>">
      <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- ============================ LLM ============================ -->
  <section class="bg-white rounded-lg shadow p-6 mb-8"
           x-data='llmConfig(<?= htmlspecialchars(json_encode([
               "provider" => $cur["llm_provider"],
               "model" => $cur["llm_model"],
               "temperature" => $cur["llm_temperature"],
               "maxTokens" => $cur["llm_max_tokens"],
               "baseUrl" => $cur["llm_base_url"],
               "keyPresent" => $cur["llm_api_key_present"],
               "providers" => json_decode($llmJs, true),
           ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8") ?>)'
           x-cloak>
    <h2 class="text-lg font-bold mb-1">💬 LLMプロバイダー</h2>
    <p class="text-sm text-gray-500 mb-4">回答を生成するAI（対話モデル）</p>

    <form method="post" action="settings.php">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="save_llm">

      <!-- ステップ1: プロバイダー選択 -->
      <p class="text-xs font-semibold text-gray-500 mb-2">① プロバイダーを選択</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
        <?php foreach ($llmProviders as $key => $info): ?>
          <label class="border-2 border-gray-200 rounded-lg p-3 cursor-pointer hover:bg-blue-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 transition">
            <div class="flex items-center justify-between">
              <span class="font-bold"><?= htmlspecialchars($info['name']) ?></span>
              <?php if ($info['free']): ?>
                <span class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full">無料</span>
              <?php endif; ?>
            </div>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($info['subtitle']) ?></p>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($info['help']) ?></p>
            <input type="radio" name="llm_provider" value="<?= $key ?>"
                   <?= $cur['llm_provider'] === $key ? 'checked' : '' ?>
                   x-model="provider" @change="onProviderChange()"
                   class="sr-only">
          </label>
        <?php endforeach; ?>
      </div>

      <!-- ステップ2: 入力（選んだプロバイダー次第） -->
      <p class="text-xs font-semibold text-gray-500 mb-2" x-show="provider">② 接続情報を入力</p>

      <!-- APIキー（クラウド型） -->
      <div x-show="providers[provider] && providers[provider].needs_api_key" x-cloak class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">
          APIキー
          <span x-show="keyPresent" class="text-xs text-green-700 font-normal">（設定済み・変更しない場合は空欄）</span>
          <span x-show="!keyPresent" class="text-xs text-red-600 font-normal">（未設定・必須）</span>
        </label>
        <input type="password" name="llm_api_key"
               :placeholder="keyPresent ? '********（変更しない）' : 'API キーを貼り付け'"
               class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
        <p class="text-xs text-gray-500 mt-1">
          取得先：
          <a :href="providers[provider]?.key_url" target="_blank" rel="noopener noreferrer"
             x-text="providers[provider]?.key_url" class="text-blue-600 hover:underline break-all"></a>
        </p>
        <p class="text-xs text-gray-400 mt-1">AES-256-GCM で暗号化してDBに保存。.env には残しません。</p>
      </div>

      <!-- Base URL（ローカル型） -->
      <div x-show="providers[provider] && providers[provider].needs_base_url" x-cloak class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">
          Base URL <span class="text-red-500">*</span>
        </label>
        <input type="text" name="llm_base_url" x-model="baseUrl"
               placeholder="http://192.168.0.3:13305/v1"
               class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
        <div class="text-xs text-gray-500 mt-1 space-y-0.5">
          <div>📍 例: <code class="bg-gray-100 px-1">http://localhost:11434/v1</code>（Ollama）</div>
          <div>📍 例: <code class="bg-gray-100 px-1">http://localhost:1234/v1</code>（LM Studio）</div>
          <div>📍 例: <code class="bg-gray-100 px-1">http://192.168.0.3:13305/v1</code>（LAN内サーバー）</div>
        </div>
      </div>

      <!-- モデル名（共通、プロバイダー切替時にdefault自動入力） -->
      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">モデル名</label>
        <input type="text" name="llm_model" x-model="model"
               :placeholder="providers[provider]?.default_model || ''"
               class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
        <p class="text-xs text-gray-400 mt-1" x-show="providers[provider]?.default_model">
          推奨：<code class="bg-gray-100 px-1" x-text="providers[provider].default_model"></code>
        </p>
      </div>

      <!-- 詳細設定（折りたたみ） -->
      <details class="mb-5">
        <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-900">▶ 詳細設定（Temperature・最大トークン）</summary>
        <div class="grid grid-cols-2 gap-4 mt-3 pl-4">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Temperature (0-2)</label>
            <input type="number" step="0.1" min="0" max="2" name="llm_temperature"
                   x-model="temperature"
                   class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            <p class="text-xs text-gray-400 mt-1">低いほど決定的、高いほど創造的</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">最大トークン数</label>
            <input type="number" min="100" max="16384" name="llm_max_tokens"
                   x-model="maxTokens"
                   class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            <p class="text-xs text-gray-400 mt-1">
              1回の応答の上限。<br>
              ・短い回答中心（FAQ）: <code class="bg-gray-100 px-1">1024</code> /
              ・通常Q&amp;A（社内ナレッジ）: <code class="bg-gray-100 px-1">2048</code> /
              ・手順書を全文回答: <code class="bg-gray-100 px-1">4096〜8192</code><br>
              ※ プロバイダー別の上限あり（Gemini Flash=8192 / gpt-4o-mini=16384 / Groq Llama=32768）
            </p>
          </div>
        </div>
      </details>

      <!-- アクションボタン -->
      <div class="flex flex-wrap gap-3">
        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded transition">
          ✅ 接続テストして保存
        </button>
        <button type="submit" formaction="settings.php" name="action" value="test_llm"
                class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium px-5 py-2 rounded transition">
          🧪 現在の設定で接続テストのみ
        </button>
        <a href="../chat/" class="ml-auto text-sm text-gray-500 hover:text-gray-700 self-center">
          💬 チャットで動作確認 →
        </a>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        「接続テストして保存」を押すと、入力された設定で実際に LLM に1回問い合わせて成功した場合のみ保存します。
        失敗すれば既存設定はそのまま維持されます。
      </p>
    </form>
  </section>

  <!-- ============================ セカンダリLLM / 適応ルーティング ============================ -->
  <section class="bg-white rounded-lg shadow p-6 mb-8"
           x-data='llm2Config(<?= htmlspecialchars(json_encode([
               "enabled" => $cur["llm_routing_enabled"],
               "provider" => $cur["llm2_provider"],
               "model" => $cur["llm2_model"],
               "baseUrl" => $cur["llm2_base_url"],
               "keyPresent" => $cur["llm2_api_key_present"],
               "providers" => json_decode($llmJs, true),
           ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8") ?>)'
           x-cloak>
    <h2 class="text-lg font-bold mb-1">🧭 適応ルーティング（セカンダリLLM）</h2>
    <p class="text-sm text-gray-500 mb-4">
      簡単な質問は上の<strong>プライマリ（高速）</strong>、複雑な質問だけ<strong>セカンダリ（高精度・低速）</strong>に自動で振り分けます。
      複雑さは「質問の長さ・比較/条件などの語・文脈量」＋ガードレールのLLM判定（追加API呼び出しなし）で判定します。
    </p>

    <form method="post" action="settings.php">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="save_llm2">

      <!-- ルーティングON/OFF -->
      <label class="flex items-center gap-2 mb-5 cursor-pointer">
        <input type="checkbox" name="llm_routing_enabled" value="1" x-model="enabled"
               class="w-4 h-4 rounded border-gray-300">
        <span class="text-sm font-medium text-gray-700">適応ルーティングを有効にする</span>
        <span class="text-xs text-gray-400">（OFF または セカンダリ未設定 のときはプライマリ単独）</span>
      </label>

      <!-- セカンダリ・プロバイダー選択（未設定で無効化可能） -->
      <p class="text-xs font-semibold text-gray-500 mb-2">① セカンダリ（高精度側）プロバイダーを選択</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
        <label class="border-2 border-gray-200 rounded-lg p-3 cursor-pointer hover:bg-gray-50 has-[:checked]:border-gray-400 has-[:checked]:bg-gray-50 transition">
          <div class="flex items-center justify-between">
            <span class="font-bold text-gray-700">未設定（ルーティング無効）</span>
          </div>
          <p class="text-xs text-gray-400 mt-1">プライマリLLM単独で運用する</p>
          <input type="radio" name="llm2_provider" value=""
                 <?= $cur['llm2_provider'] === '' ? 'checked' : '' ?>
                 x-model="provider" @change="onProviderChange()" class="sr-only">
        </label>
        <?php foreach ($llmProviders as $key => $info): ?>
          <label class="border-2 border-gray-200 rounded-lg p-3 cursor-pointer hover:bg-purple-50 has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50 transition">
            <div class="flex items-center justify-between">
              <span class="font-bold"><?= htmlspecialchars($info['name']) ?></span>
              <?php if ($info['free']): ?>
                <span class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full">無料</span>
              <?php endif; ?>
            </div>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($info['subtitle']) ?></p>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($info['help']) ?></p>
            <input type="radio" name="llm2_provider" value="<?= $key ?>"
                   <?= $cur['llm2_provider'] === $key ? 'checked' : '' ?>
                   x-model="provider" @change="onProviderChange()" class="sr-only">
          </label>
        <?php endforeach; ?>
      </div>

      <!-- 入力（プロバイダー選択時のみ） -->
      <template x-if="provider !== ''">
        <div>
          <!-- APIキー（クラウド型） -->
          <div x-show="providers[provider] && providers[provider].needs_api_key" x-cloak class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              APIキー
              <span x-show="keyPresent" class="text-xs text-green-700 font-normal">（設定済み・変更しない場合は空欄）</span>
              <span x-show="!keyPresent" class="text-xs text-red-600 font-normal">（未設定・必須）</span>
            </label>
            <input type="password" name="llm2_api_key"
                   :placeholder="keyPresent ? '********（変更しない）' : 'API キーを貼り付け'"
                   class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
            <p class="text-xs text-gray-500 mt-1">
              取得先：
              <a :href="providers[provider]?.key_url" target="_blank" rel="noopener noreferrer"
                 x-text="providers[provider]?.key_url" class="text-blue-600 hover:underline break-all"></a>
            </p>
          </div>

          <!-- Base URL（ローカル型） -->
          <div x-show="providers[provider] && providers[provider].needs_base_url" x-cloak class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Base URL <span class="text-red-500">*</span>
            </label>
            <input type="text" name="llm2_base_url" x-model="baseUrl"
                   placeholder="http://192.168.0.3:13305/v1"
                   class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
            <p class="text-xs text-gray-500 mt-1">例: <code class="bg-gray-100 px-1">http://192.168.0.3:13305/v1</code>（Lemonade 等のLAN内サーバー）</p>
          </div>

          <!-- モデル名 -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">モデル名</label>
            <input type="text" name="llm2_model" x-model="model"
                   :placeholder="providers[provider]?.default_model || ''"
                   class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
            <p class="text-xs text-gray-400 mt-1" x-show="providers[provider]?.default_model">
              推奨：<code class="bg-gray-100 px-1" x-text="providers[provider].default_model"></code>
            </p>
          </div>
        </div>
      </template>

      <div class="flex flex-wrap gap-3 mt-2">
        <button type="submit"
                class="bg-purple-600 hover:bg-purple-700 text-white font-medium px-5 py-2 rounded transition">
          ✅ 接続テストして保存
        </button>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        セカンダリを選んだ場合は実際に1回問い合わせて成功時のみ保存します。「未設定」を選ぶとルーティングを無効化します。
      </p>
    </form>
  </section>

  <!-- ============================ Embedding ============================ -->
  <section class="bg-white rounded-lg shadow p-6"
           x-data='embeddingConfig(<?= htmlspecialchars(json_encode([
               "provider" => $cur["embedding_provider"],
               "model" => $cur["embedding_model"],
               "keyPresent" => $cur["embedding_api_key_present"],
               "providers" => json_decode($embJs, true),
           ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8") ?>)'
           x-cloak>
    <h2 class="text-lg font-bold mb-1">🔢 Embeddingプロバイダー</h2>
    <p class="text-sm text-gray-500 mb-4">文書をベクトル化して類似度検索に使う。LLMと同じプロバイダーならAPIキー流用可能。</p>

    <form method="post" action="settings.php">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="save_embedding">

      <p class="text-xs font-semibold text-gray-500 mb-2">① プロバイダーを選択</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
        <?php foreach ($embeddingProviders as $key => $info): ?>
          <label class="border-2 border-gray-200 rounded-lg p-3 cursor-pointer hover:bg-blue-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 transition">
            <div class="flex items-center justify-between">
              <span class="font-bold"><?= htmlspecialchars($info['name']) ?></span>
              <?php if ($info['free']): ?>
                <span class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full">無料</span>
              <?php endif; ?>
            </div>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($info['subtitle']) ?></p>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($info['help']) ?></p>
            <input type="radio" name="embedding_provider" value="<?= $key ?>"
                   <?= $cur['embedding_provider'] === $key ? 'checked' : '' ?>
                   x-model="provider" @change="onProviderChange()"
                   class="sr-only">
          </label>
        <?php endforeach; ?>
      </div>

      <!-- APIキー（必要なときのみ） -->
      <div x-show="providers[provider] && providers[provider].needs_api_key" x-cloak class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">
          APIキー
          <span x-show="keyPresent" class="text-xs text-green-700 font-normal">（設定済み）</span>
          <span x-show="!keyPresent" class="text-xs text-blue-600 font-normal">（LLMと同じプロバイダーなら空欄でLLM用キーを流用）</span>
        </label>
        <input type="password" name="embedding_api_key"
               :placeholder="keyPresent ? '********（変更しない）' : 'LLM用キーと同じなら空欄でOK'"
               class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
        <p class="text-xs text-gray-500 mt-1" x-show="providers[provider]?.key_url">
          取得先：
          <a :href="providers[provider]?.key_url" target="_blank" rel="noopener noreferrer"
             x-text="providers[provider]?.key_url" class="text-blue-600 hover:underline break-all"></a>
        </p>
      </div>

      <!-- モデル名 -->
      <div x-show="provider !== 'none'" x-cloak class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">モデル名</label>
        <input type="text" name="embedding_model" x-model="model"
               :placeholder="providers[provider]?.default_model || ''"
               class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm">
      </div>

      <div class="flex flex-wrap gap-3">
        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded transition">
          ✅ 接続テストして保存
        </button>
        <button type="submit" formaction="settings.php" name="action" value="test_embedding"
                class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium px-5 py-2 rounded transition">
          🧪 現在の設定で接続テストのみ
        </button>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        「接続テストして保存」を押すと、入力された設定で実際に embedding API を1回呼んで成功した場合のみ保存します。
      </p>
    </form>
  </section>

  <p class="mt-8 text-xs text-gray-500 text-center">
    APIキーは <strong>AES-256-GCM</strong> で暗号化して DB に保存されます。<code>.env</code> の <code>APP_ENCRYPTION_KEY</code> で復号。
  </p>
</main>

<script>
function llmConfig(init) {
    return {
        provider: init.provider,
        model: init.model,
        temperature: init.temperature,
        maxTokens: init.maxTokens,
        baseUrl: init.baseUrl,
        keyPresent: init.keyPresent,
        providers: init.providers,
        _prev: init.provider,
        onProviderChange() {
            // プロバイダー切替時、モデル名が前のデフォルト or 空 ならデフォルトに更新
            const prevDef = this.providers[this._prev]?.default_model;
            if (this.model === '' || this.model === prevDef) {
                this.model = this.providers[this.provider]?.default_model || '';
            }
            // ローカルLLM以外に切替えたら Base URL をクリア
            if (!this.providers[this.provider]?.needs_base_url) {
                this.baseUrl = '';
            }
            this._prev = this.provider;
        },
    };
}
function llm2Config(init) {
    return {
        enabled: init.enabled,
        provider: init.provider,
        model: init.model,
        baseUrl: init.baseUrl,
        keyPresent: init.keyPresent,
        providers: init.providers,
        _prev: init.provider,
        onProviderChange() {
            // 「未設定」へ切替えたら入力をクリア
            if (this.provider === '') {
                this.model = '';
                this.baseUrl = '';
                this._prev = '';
                return;
            }
            // モデル名が前のデフォルト or 空ならデフォルトに更新
            const prevDef = this.providers[this._prev]?.default_model;
            if (this.model === '' || this.model === prevDef) {
                this.model = this.providers[this.provider]?.default_model || '';
            }
            // ローカルLLM以外に切替えたら Base URL をクリア
            if (!this.providers[this.provider]?.needs_base_url) {
                this.baseUrl = '';
            }
            this._prev = this.provider;
        },
    };
}
function embeddingConfig(init) {
    return {
        provider: init.provider,
        model: init.model,
        keyPresent: init.keyPresent,
        providers: init.providers,
        _prev: init.provider,
        onProviderChange() {
            const prevDef = this.providers[this._prev]?.default_model;
            if (this.model === '' || this.model === prevDef) {
                this.model = this.providers[this.provider]?.default_model || '';
            }
            this._prev = this.provider;
        },
    };
}
</script>
</body>
</html>
