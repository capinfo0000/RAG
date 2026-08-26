/**
 * 埋め込みウィジェット・ローダ
 * ============================================================
 * 顧客の既存HPに「1行」貼るだけでAIチャットを設置できる。
 *
 *   <script src="https://<ホスト>/embed.js"></script>
 *
 * 任意の data-* 属性でカスタマイズ可能:
 *   data-src      チャットURLを明示指定（省略時は embed.js と同じ場所の chat/?embed=1）
 *   data-title    ボタン/ヘッダのラベル（既定: AIアシスタント）
 *   data-color    アクセント色（既定: #2563eb）
 *   data-position "right"（既定） / "left"
 *
 * 仕組み: RAGホスト上のチャットページを iframe で読み込む。
 * → iframe内のAPI通信は同一オリジンなので CORS 不要、顧客サイトのCSSとも干渉しない。
 */
(function () {
  'use strict';

  // このスクリプト要素を取得（data-* とURL基準の解決に使う）
  var me = document.currentScript;
  if (!me) {
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (/embed\.js(\?|$)/.test(all[i].src)) { me = all[i]; break; }
    }
  }
  if (!me) return;

  // 二重読み込みガード
  if (window.__ragWidgetLoaded) return;
  window.__ragWidgetLoaded = true;

  var title = me.getAttribute('data-title') || 'AIアシスタント';
  var color = me.getAttribute('data-color') || '#2563eb';
  var position = (me.getAttribute('data-position') || 'right').toLowerCase() === 'left' ? 'left' : 'right';
  // data-open="1" で、ページ読み込み時からチャットを開いた状態にする
  var autoOpen = /^(1|true|yes)$/i.test(me.getAttribute('data-open') || '');

  // チャットURLの解決: data-src 優先、無ければ embed.js と同じディレクトリの chat/?embed=1
  var chatUrl = me.getAttribute('data-src');
  if (!chatUrl) {
    var base = me.src.replace(/[^/]*$/, ''); // embed.js を除いたディレクトリ
    chatUrl = base + 'chat/?embed=1';
  }

  var sideProp = position === 'left' ? 'left' : 'right';

  // ---- スタイル（クラス名は衝突しにくいプレフィックス） ----
  var css =
    '.ragw-btn{position:fixed;bottom:20px;' + sideProp + ':20px;width:60px;height:60px;border-radius:50%;' +
    'background:' + color + ';color:#fff;border:none;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25);' +
    'z-index:2147483000;display:flex;align-items:center;justify-content:center;transition:transform .15s ease;}' +
    '.ragw-btn:hover{transform:scale(1.06);}' +
    '.ragw-btn svg{width:28px;height:28px;fill:#fff;}' +
    '.ragw-panel{position:fixed;bottom:92px;' + sideProp + ':20px;width:390px;height:70vh;max-height:640px;' +
    'background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.28);' +
    'z-index:2147483000;display:none;flex-direction:column;border:1px solid rgba(0,0,0,.08);transition:width .2s ease,height .2s ease;}' +
    '.ragw-panel.ragw-large{width:min(760px,94vw);top:16px;bottom:16px;height:auto;max-height:none;}' +
    '.ragw-panel.ragw-open{display:flex;animation:ragw-in .18s ease;}' +
    '@keyframes ragw-in{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}' +
    '.ragw-head{background:' + color + ';color:#fff;padding:10px 14px;font:600 14px/1.2 system-ui,sans-serif;' +
    'display:flex;align-items:center;justify-content:space-between;}' +
    '.ragw-actions{display:flex;align-items:center;gap:2px;}' +
    '.ragw-expand,.ragw-close{background:none;border:none;color:#fff;line-height:1;cursor:pointer;padding:2px 6px;border-radius:6px;}' +
    '.ragw-expand:hover,.ragw-close:hover{background:rgba(255,255,255,.18);}' +
    '.ragw-expand{font-size:15px;}.ragw-close{font-size:20px;}' +
    '.ragw-frame{border:none;width:100%;flex:1;background:#fff;}' +
    '@media(max-width:480px){.ragw-panel,.ragw-panel.ragw-large{width:100vw;height:100vh;top:0;bottom:0;' + sideProp + ':0;border-radius:0;max-height:none;}' +
    '.ragw-btn{bottom:16px;' + sideProp + ':16px;}.ragw-expand{display:none;}}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  // ---- パネル（iframeは開くまで生成しない=初期表示を軽く） ----
  var panel = document.createElement('div');
  panel.className = 'ragw-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', title);

  var head = document.createElement('div');
  head.className = 'ragw-head';
  var span = document.createElement('span');
  span.textContent = title;
  var expandBtn = document.createElement('button');
  expandBtn.className = 'ragw-expand';
  expandBtn.setAttribute('aria-label', '拡大');
  expandBtn.setAttribute('title', '拡大 / 縮小');
  expandBtn.innerHTML = '&#10530;'; // ⤢
  var closeBtn = document.createElement('button');
  closeBtn.className = 'ragw-close';
  closeBtn.setAttribute('aria-label', '閉じる');
  closeBtn.innerHTML = '&times;';
  var actions = document.createElement('div');
  actions.className = 'ragw-actions';
  actions.appendChild(expandBtn);
  actions.appendChild(closeBtn);
  head.appendChild(span);
  head.appendChild(actions);
  panel.appendChild(head);

  var frame = null;
  function ensureFrame() {
    if (frame) return;
    frame = document.createElement('iframe');
    frame.className = 'ragw-frame';
    frame.setAttribute('title', title);
    frame.src = chatUrl;
    panel.appendChild(frame);
  }

  // ---- 起動ボタン ----
  var btn = document.createElement('button');
  btn.className = 'ragw-btn';
  btn.setAttribute('aria-label', title + 'を開く');
  btn.innerHTML =
    '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
    '<path d="M12 3C6.48 3 2 6.94 2 11.5c0 2.3 1.16 4.36 3.03 5.83L4 21l4.28-1.72c1.13.34 2.4.53 3.72.53 5.52 0 10-3.94 10-8.5S17.52 3 12 3z"/></svg>';

  var open = false;
  function toggle(next) {
    open = (typeof next === 'boolean') ? next : !open;
    if (open) {
      ensureFrame();
      panel.classList.add('ragw-open');
    } else {
      panel.classList.remove('ragw-open');
    }
  }
  btn.addEventListener('click', function () { toggle(); });
  closeBtn.addEventListener('click', function () { toggle(false); });

  // 拡大 / 縮小トグル
  var large = false;
  expandBtn.addEventListener('click', function () {
    large = !large;
    panel.classList.toggle('ragw-large', large);
    expandBtn.innerHTML = large ? '&#10529;' : '&#10530;'; // ⤡ / ⤢
    expandBtn.setAttribute('aria-label', large ? '縮小' : '拡大');
  });

  function mount() {
    document.body.appendChild(panel);
    document.body.appendChild(btn);
    if (autoOpen) { toggle(true); }
  }
  if (document.body) {
    mount();
  } else {
    document.addEventListener('DOMContentLoaded', mount);
  }

  // 外部から制御したい場合のフック
  window.ragWidget = { open: function () { toggle(true); }, close: function () { toggle(false); }, toggle: toggle };
})();
