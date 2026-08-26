<?php /* 資料タブ（アップロード / AIで作成）。呼び出し側で $active = 'upload'|'compose' を定義 */ ?>
<?php $active = $active ?? 'upload'; ?>
<div style="display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:24px">
  <a href="knowledge.php" style="padding:8px 16px;font-size:14px;font-weight:500;text-decoration:none;border-bottom:2px solid <?= $active === 'upload' ? '#2563eb' : 'transparent' ?>;color:<?= $active === 'upload' ? '#1d4ed8' : '#6b7280' ?>">📤 アップロード</a>
  <a href="compose.php" style="padding:8px 16px;font-size:14px;font-weight:500;text-decoration:none;border-bottom:2px solid <?= $active === 'compose' ? '#2563eb' : 'transparent' ?>;color:<?= $active === 'compose' ? '#1d4ed8' : '#6b7280' ?>">✍️ AIで作成</a>
</div>
