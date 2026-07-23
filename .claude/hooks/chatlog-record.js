#!/usr/bin/env node
/**
 * chatlog-record.js — 会話ログ自動記録フック（Stop / SessionEnd 用）
 * ------------------------------------------------------------------
 * - プロジェクトごとに `logs/<project>/<date>_<session8>_chat.md` を出力
 * - ユーザーの質問 / AI の回答を **User:** / **Assistant:** で区別
 * - tool 呼び出し・tool 結果・サイドチェーン(サブエージェント)は除外
 * - 秘密（APIキー/トークン/パスワード/DB認証/アクセス鍵）は [REDACTED] 化
 * - トランスクリプトから毎回まるごと再生成（冪等・重複なし）
 *
 * 標準入力に Stop フックの JSON（session_id / transcript_path / cwd 等）を受ける。
 * transcript_path が無ければ cwd からプロジェクトのトランスクリプトを推定する。
 */
'use strict';
const fs = require('fs');
const path = require('path');

function readStdin() {
  try { return fs.readFileSync(0, 'utf8'); } catch { return ''; }
}

// ---- 秘密の伏せ字 ----
function redact(s) {
  if (!s) return s;
  return s
    .replace(/AIza[0-9A-Za-z_\-]{20,}/g, '[REDACTED-key]')
    .replace(/AQ\.[A-Za-z0-9_\-]{20,}/g, '[REDACTED-key]')
    .replace(/sk-ant-[A-Za-z0-9_\-]{20,}/g, '[REDACTED-key]')
    .replace(/gsk_[A-Za-z0-9]{20,}/g, '[REDACTED-key]')
    .replace(/\bsk-[A-Za-z0-9]{20,}/g, '[REDACTED-key]')
    .replace(/\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}/g, '[REDACTED-hash]')
    .replace(/base64:[A-Za-z0-9+/=]{20,}/g, 'base64:[REDACTED]')
    .replace(/([?&](?:k|token|key|api[_-]?key|password|pass|secret)=)[^\s&"'#)]+/gi, '$1[REDACTED]')
    .replace(/((?:DB_PASSWORD|DB_PASS|GEMINI_API_KEY[0-9_]*|OPENAI_API_KEY|ANTHROPIC_API_KEY|GROQ_API_KEY|VOYAGE_API_KEY|COHERE_API_KEY|CHAT_ACCESS_KEY|SYNC_TOKEN|APP_ENCRYPTION_KEY|ADMIN_PASSWORD_HASH|CUSTOMER_PASSWORD_HASH|SECRET|TOKEN)\s*[=:]\s*)[^\s"'#]+/gi, '$1[REDACTED]');
}

// ---- message.content からテキストだけ抽出（tool は除外） ----
function extractText(content) {
  if (typeof content === 'string') return content.trim();
  if (!Array.isArray(content)) return '';
  const parts = [];
  for (const b of content) {
    if (!b || typeof b !== 'object') continue;
    if (b.type === 'text' && typeof b.text === 'string') parts.push(b.text);
    // tool_use / tool_result / thinking 等は記録しない
  }
  return parts.join('\n').trim();
}

function main() {
  let input = {};
  try { input = JSON.parse(readStdin() || '{}'); } catch { input = {}; }

  const cwd = input.cwd || process.cwd();
  const project = path.basename(cwd) || 'project';

  // トランスクリプト解決
  let tpath = input.transcript_path;
  if (!tpath || !fs.existsSync(tpath)) {
    const sanitized = cwd.replace(/[\/\\:]/g, '-');
    const dir = path.join(process.env.HOME || '/root', '.claude', 'projects', sanitized);
    try {
      const files = fs.readdirSync(dir).filter(f => f.endsWith('.jsonl'))
        .map(f => ({ f, m: fs.statSync(path.join(dir, f)).mtimeMs }))
        .sort((a, b) => b.m - a.m);
      if (files.length) tpath = path.join(dir, files[0].f);
    } catch { /* ignore */ }
  }
  if (!tpath || !fs.existsSync(tpath)) { process.exit(0); }

  const sessionId = input.session_id || path.basename(tpath, '.jsonl');
  const sess8 = String(sessionId).slice(0, 8);

  // JSONL パース → User/Assistant のテキスト turn を収集
  const lines = fs.readFileSync(tpath, 'utf8').split('\n');
  const turns = [];
  for (const line of lines) {
    if (!line.trim()) continue;
    let o; try { o = JSON.parse(line); } catch { continue; }
    if (o.isSidechain) continue;                 // サブエージェント除外
    if (o.type !== 'user' && o.type !== 'assistant') continue;
    const msg = o.message; if (!msg) continue;
    const text = extractText(msg.content);
    if (!text) continue;                          // tool のみの turn は空→除外
    // ツール結果だけのユーザーturnや、コマンド出力ノイズを軽く除外
    if (o.type === 'user' && /^\s*<(command-name|local-command|bash-(input|stdout))>/.test(text)) continue;
    turns.push({ role: o.type === 'user' ? 'User' : 'Assistant', text: redact(text), ts: o.timestamp });
  }
  if (!turns.length) process.exit(0);

  // 出力先: logs/<project>/<date>_<session8>_chat.md
  const date = new Date().toISOString().slice(0, 10);
  const outDir = path.join(cwd, 'logs', project);
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, `${date}_${sess8}_chat.md`);

  const header =
    `# チャットログ ${date} — ${project} (session ${sess8})\n\n` +
    `> 自動記録（Stop/SessionEnd フック）。tool 出力・サブエージェントは除外。秘密は [REDACTED] 化。\n\n---\n\n`;
  const body = turns.map(t => `**${t.role}:**\n\n${t.text}`).join('\n\n---\n\n') + '\n';

  fs.writeFileSync(outFile, header + body, 'utf8');
  // フック出力（任意）: ユーザーに一言
  process.stdout.write(JSON.stringify({ suppressOutput: true }));
  process.exit(0);
}

try { main(); } catch { process.exit(0); }  // フックは絶対に失敗でセッションを止めない
