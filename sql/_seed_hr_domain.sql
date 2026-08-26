-- 社内規程ドメイン向けにデモ表示設定を更新（DB再構築後の上書き用）
UPDATE settings SET setting_value='社内規程アシスタント' WHERE setting_key='product_name';
UPDATE settings SET setting_value='こんにちは！就業規則・休暇・手当・出張旅費など、社内規程について何でもお尋ねください。' WHERE setting_key='welcome_message';
UPDATE settings SET setting_value='["有給休暇は何日もらえる？","育児休業は最長いつまで取れる？","出張旅費の精算方法は？","入社祝い金の条件は？"]' WHERE setting_key='opening_quick_replies';
UPDATE settings SET setting_value='このチャットボットは当社の社内規程・人事制度（就業規則、勤怠、休暇、育児・介護休業、出張旅費、各種手当・祝い金・報奨金制度など）に関する質問に回答します。' WHERE setting_key='topic_description';
