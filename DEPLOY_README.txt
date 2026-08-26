========================================================
CoreServer 展開＝完成ZIP（リネーム・移動 不要）
作成日: 2026-07-14  ドメイン: rag.engineer.v2008.coreserver.jp
========================================================

■ やること（このZIPの中身を、ドメイン直下にそのまま置くだけ）
  展開先 = /domains/rag.engineer.v2008.coreserver.jp/
  → 展開後、直下がこうなればOK：
     /domains/rag.engineer.v2008.coreserver.jp/
       ├─ public_html/   ← 公開領域（docroot）。中に chat/ admin/ api/ assets/ bootstrap.php embed.js index.php sync-run.php
       ├─ .env            ← ★このあと自分で作成（下記）
       ├─ vendor/ src/ vault/ storage/ sql/ scripts/
       ├─ composer.json / composer.lock / .htaccess
       └─ *.md（ドキュメント）
  ※ リネームも移動も不要。public_html の“中身”と、その外に置く本体が、最初から正しい階層で入っています。

■ 事前に（現状の掃除）
  サーバの /domains/rag.engineer.v2008.coreserver.jp/ に今ある
  「RAG_full_bundle_2026-07-13」フォルダ（＝間違って丸ごと上げた物）は削除してください。
  そのうえで、このZIPの中身を同ディレクトリへ展開/アップロードします。
  ・ファイルマネージャ: ZIPをアップ→展開 が使えるならそれが速い
  ・WinSCP: ローカルで展開してから中身を丸ごとアップでもOK

■ このZIPに“入っていない”もの（＝あなたが用意）
  1) .env … ローカルの PRODUCTION.env を「.env」という名前で
     /domains/rag.engineer.v2008.coreserver.jp/ 直下（public_html の外）へアップ。
     ・顧客ロールを使うなら CUSTOMER_USERNAME / CUSTOMER_PASSWORD_HASH を追記。
     ・（.env.example を同梱。中身の項目はこれを参照）
  2) 同期トークン … public_html/sync-run.php の
     <REPLACE_WITH_YOUR_SYNC_TOKEN> を強いトークンに置換。

■ 仕上げ
  3) DBスキーマ適用（sql/ 参照）
  4) storage/ に書込権限
  5) https://rag.engineer.v2008.coreserver.jp/sync-run.php?token=（設定したトークン）
     を1回叩いて vault(ナレッジ) をDBへ取込
  6) 動作確認: https://rag.engineer.v2008.coreserver.jp/chat/ が開く

■ セキュリティ（この配置で担保される点）
  ・.env / vault / src / vendor は public_html の“外”なので、ブラウザから直接読めません。
  ・実APIキー・パスワードハッシュ・同期トークンはZIPに含めていません（自分で投入）。
  ・vault の 16_ビジネスモデル / 19_APIキー費用 は「_」付きで同期対象外＝BOTは答えません。

■ 補足
  ・demo-sample-site（設置デモ用サンプル）はサーバ配信不要のため本ZIPには含めていません
    （必要なら別途、任意の場所で index.html を開いて確認）。
  ・DEPLOY_README.txt / *.md / .htaccess はドメイン直下（docrootの外）なので公開されません。
