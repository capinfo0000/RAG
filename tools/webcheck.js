#!/usr/bin/env node
/**
 * webcheck.js — クラウドで公開URLを動作確認するヘルパー（ヘッドレスChromium）
 * 使い方: node webcheck.js <URL> [出力スクショパス]
 * - ページを開く / HTTPステータス / タイトル / console エラー / ページ例外 / スクショ
 * - 埋め込みウィジェット（.ragw-btn / .ragw-panel）の有無・自動オープンも確認
 */
'use strict';
const { chromium } = require('playwright-core');

function findChrome() {
  const fs = require('fs');
  const cands = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium/chrome-linux/chrome',
  ];
  for (const c of cands) { try { if (fs.existsSync(c)) return c; } catch {} }
  // フォールバック: 探索
  try {
    const { execSync } = require('child_process');
    const p = execSync('ls /opt/pw-browsers/chromium*/chrome-linux/chrome 2>/dev/null | head -1').toString().trim();
    if (p) return p;
  } catch {}
  return undefined;
}

(async () => {
  const url = process.argv[2];
  const shot = process.argv[3] || 'webcheck.png';
  if (!url) { console.error('usage: node webcheck.js <URL> [shot.png]'); process.exit(2); }

  // クラウドは送信が専用プロキシ経由。Playwright にもプロキシを教える。
  const proxyServer = process.env.HTTPS_PROXY || process.env.HTTP_PROXY || '';
  const args = ['--no-sandbox', '--ignore-certificate-errors',
    '--disable-quic', '--disable-features=UseDnsHttpsSvcb,AsyncDns'];
  if (proxyServer) args.push('--proxy-server=' + proxyServer, '--proxy-bypass-list=<-loopback>');
  const launchOpts = { executablePath: findChrome(), args };
  if (proxyServer) launchOpts.proxy = { server: proxyServer };
  const browser = await chromium.launch(launchOpts);
  // プロキシは自CAでTLS終端するため、ブラウザ視点では証明書を受理する必要がある
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const consoleMsgs = [];
  const pageErrors = [];
  page.on('console', m => { if (['error', 'warning'].includes(m.type())) consoleMsgs.push(`[${m.type()}] ${m.text()}`); });
  page.on('pageerror', e => pageErrors.push(String(e)));

  let status = null, ok = false;
  try {
    const resp = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
    status = resp ? resp.status() : null;
    ok = resp ? resp.ok() : false;
  } catch (e) {
    console.log('NAV_ERROR: ' + e.message);
  }
  await page.waitForTimeout(1200);

  const title = await page.title().catch(() => '');
  const bodyText = (await page.evaluate(() => document.body ? document.body.innerText : '').catch(() => '')).slice(0, 600);
  const widget = await page.evaluate(() => {
    const btn = document.querySelector('.ragw-btn');
    const panel = document.querySelector('.ragw-panel');
    return {
      hasButton: !!btn,
      hasPanel: !!panel,
      panelOpen: panel ? panel.className.includes('ragw-open') : false,
    };
  }).catch(() => ({}));

  await page.screenshot({ path: shot, fullPage: false }).catch(() => {});
  await browser.close();

  console.log('URL          : ' + url);
  console.log('HTTP status  : ' + status + (ok ? ' (OK)' : ''));
  console.log('Title        : ' + title);
  console.log('Widget button: ' + (widget.hasButton ? 'あり' : 'なし') +
    ' / panel: ' + (widget.hasPanel ? 'あり' : 'なし') +
    ' / 自動オープン: ' + (widget.panelOpen ? 'はい' : 'いいえ'));
  console.log('Console err/warn: ' + (consoleMsgs.length ? consoleMsgs.length + '件' : 'なし'));
  consoleMsgs.slice(0, 8).forEach(m => console.log('  ' + m));
  console.log('Page exceptions : ' + (pageErrors.length ? pageErrors.length + '件' : 'なし'));
  pageErrors.slice(0, 5).forEach(m => console.log('  ' + m));
  console.log('--- 本文抜粋 ---\n' + bodyText.replace(/\n{2,}/g, '\n'));
  console.log('--- screenshot: ' + shot + ' ---');
})().catch(e => { console.error('FATAL: ' + e.message); process.exit(1); });
