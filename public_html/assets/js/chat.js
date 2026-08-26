/**
 * RAG Chatbot Demo - チャットフロントエンド
 *
 * Alpine.js コンポーネント。fetch streaming で SSE を受信。
 * marked + DOMPurify で安全な Markdown 描画、引用 [N] にホバー説明を付与。
 */

// marked の設定
if (typeof marked !== 'undefined') {
  marked.setOptions({
    breaks: true,
    gfm: true,
  });
}

function chatApp(initialConfig) {
  return {
    productName: initialConfig.productName,
    welcomeMessage: initialConfig.welcomeMessage,
    quickReplies: initialConfig.quickReplies || [],
    // API のベースパス。iframe埋め込み(同一オリジン)では相対 '../api' で足りる。
    // 直接埋め込み等で別オリジンを叩く場合はサーバ側から絶対URLを渡す。
    apiBase: (initialConfig.apiBase || '../api').replace(/\/$/, ''),
    messages: [],          // 各要素: {role, content, citations?, message_id?, feedbackGiven?, surveyType?, surveyReason?, surveyComment?}
    input: '',
    isLoading: false,
    isThinking: false,     // 検索中（最初の token が来るまで true）
    sessionUuid: '',
    _uidSeq: 0,            // メッセージの安定キー用シーケンス（x-for :key に使用）
    // アンケート理由の選択肢
    resolvedReasons: ['知りたいことが分かった', '出典が役に立った', '対応が早かった'],
    unresolvedReasons: ['知りたい情報がなかった', '回答が分かりにくかった', '状況に合っていなかった', '出典が不十分だった', 'その他'],

    init() {
      // sessionStorage に session_uuid があれば復元
      const saved = sessionStorage.getItem('rag_session_uuid');
      if (saved) this.sessionUuid = saved;
      this.$nextTick(() => this.scrollToBottom());
    },

    renderMarkdown(text) {
      if (!text) return '';
      // DOMPurify / marked が CDN障害で未ロードなら、プレーンテキストとして escape して返す（XSS防止）
      if (typeof marked === 'undefined' || typeof DOMPurify === 'undefined') {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
      // citation 番号は 1〜99 のみ（年号などの大きな数字を誤検出しない）
      const decorated = text.replace(/\[(\d{1,2}(?:\s*,\s*\d{1,2})*)\]/g, (m, group) => {
        return group.split(/\s*,\s*/)
          .map(n => `<span class="cite-ref" data-cite="${n}">[${n}]</span>`)
          .join('');
      });
      const html = marked.parse(decorated);
      return DOMPurify.sanitize(html, {
        FORBID_ATTR: ['style', 'onerror', 'onload', 'onclick'],
      });
    },

    onEnter(ev) {
      if (ev.shiftKey) {
        // 改行を許可
        const start = ev.target.selectionStart;
        const end = ev.target.selectionEnd;
        this.input = this.input.slice(0, start) + '\n' + this.input.slice(end);
        this.$nextTick(() => { ev.target.selectionStart = ev.target.selectionEnd = start + 1; });
      } else {
        this.onSubmit();
      }
    },

    onSubmit() {
      const txt = this.input.trim();
      if (!txt || this.isLoading) return;
      this.input = '';
      this.send(txt);
    },

    // 会話をクリアして初期画面（あいさつ＋候補質問）に戻す。次の送信で新しい会話が始まる。
    resetChat() {
      if (this.isLoading) return;
      this.messages = [];
      this.input = '';
      this.isThinking = false;
      this.sessionUuid = '';
      try { sessionStorage.removeItem('rag_session_uuid'); } catch (e) {}
      this.$nextTick(() => this.scrollToBottom());
    },

    async send(message) {
      this.messages.push({ uid: ++this._uidSeq, role: 'user', content: message });
      this.messages.push({
        uid: ++this._uidSeq,
        role: 'assistant', content: '', citations: [],
        feedbackGiven: false, surveyType: null, surveyReason: '', surveyComment: '',
      });
      // assistant の index（push 後の length-1）。これまで botIdx = length（push前）の値を取って
      // user メッセージ側に token が追記されるオフセットバグがあったため修正。
      const botIdx = this.messages.length - 1;
      this.isLoading = true;
      this.isThinking = true;
      this.$nextTick(() => this.scrollToBottom());

      try {
        const res = await fetch(`${this.apiBase}/chat.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            message: message,
            session_uuid: this.sessionUuid,
          }),
        });
        if (!res.ok || !res.body) {
          throw new Error(`HTTP ${res.status}`);
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });

          let frameEnd;
          while ((frameEnd = buffer.indexOf('\n\n')) !== -1) {
            const frame = buffer.slice(0, frameEnd);
            buffer = buffer.slice(frameEnd + 2);
            this.handleSseFrame(frame, botIdx);
          }
        }
        // 残り
        if (buffer.trim() !== '') {
          this.handleSseFrame(buffer, botIdx);
        }
      } catch (e) {
        this.messages[botIdx].content = `❌ エラーが発生しました: ${e.message}`;
      } finally {
        this.isLoading = false;
        this.isThinking = false;
        this.$nextTick(() => this.scrollToBottom());
      }
    },

    handleSseFrame(frame, botIdx) {
      let event = 'message';
      let dataLines = [];
      for (const line of frame.split('\n')) {
        if (line.startsWith('event:')) event = line.slice(6).trim();
        else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
      }
      if (dataLines.length === 0) return;
      const dataStr = dataLines.join('');
      let data;
      try { data = JSON.parse(dataStr); } catch { return; }

      switch (event) {
        case 'session':
          this.sessionUuid = data.session_uuid;
          sessionStorage.setItem('rag_session_uuid', this.sessionUuid);
          break;
        case 'sources':
          // 何もしない（情報量過多なのでUIには出さない）
          break;
        case 'token':
          if (this.isThinking) this.isThinking = false;
          this.messages[botIdx].content += data.delta || '';
          this.$nextTick(() => this.scrollToBottom());
          break;
        case 'refusal':
          this.isThinking = false;
          this.messages[botIdx].content = data.message || 'お答えできません。';
          break;
        case 'done':
          this.messages[botIdx].message_id = data.message_id;
          this.messages[botIdx].citations = data.citations || [];
          // usage(トークン数)はサーバから受け取らない（原価/利益率の逆算防止）
          break;
        case 'error':
          this.isThinking = false;
          this.messages[botIdx].content = `❌ ${data.message || 'エラー'}`;
          break;
        case 'end':
          // SSE 終端
          break;
      }
    },

    // 「解決した／解決しなかった」→ アンケートパネルを開く
    openSurvey(msg, type) {
      if (!msg.message_id) return;
      msg.surveyReason = '';
      msg.surveyComment = '';
      msg.surveyType = type; // 'resolved' | 'unresolved'
    },

    cancelSurvey(msg) {
      msg.surveyType = null;
    },

    // アンケート送信。理由チップ未選択でも送信可（理由は任意）
    async submitSurvey(msg) {
      const rating = msg.surveyType === 'unresolved' ? 'unresolved' : 'resolved';
      // パネルを即座に閉じて送信ボタンの二重押下を防ぐ（失敗時は feedbackGiven が立たず再送可能）
      msg.surveyType = null;
      await this.postFeedback(msg, rating, msg.surveyReason || '', msg.surveyComment || '');
    },

    async postFeedback(msg, rating, reason, comment) {
      if (!msg.message_id) return;
      try {
        const res = await fetch(`${this.apiBase}/feedback.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            message_id: msg.message_id,
            rating: rating,
            reason: reason || '',
            comment: comment || '',
            session_uuid: this.sessionUuid,
          }),
        });
        if (res.ok) {
          msg.feedbackGiven = true;
        }
      } catch (e) {
        console.error('feedback failed', e);
      }
    },

    scrollToBottom() {
      const el = this.$refs.scrollArea;
      if (el) el.scrollTop = el.scrollHeight;
    },
  };
}

// Alpine.js が読み込まれたらグローバルに公開
window.chatApp = chatApp;
