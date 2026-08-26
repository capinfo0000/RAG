<?php /* shared admin nav bar */ ?>
<nav class="bg-white border-b border-gray-200 sticky top-0 z-10">
  <div class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
    <div class="flex items-center gap-6">
      <a href="index.php" class="font-bold text-blue-700">RAG Chatbot 管理</a>
      <a href="index.php" class="text-sm text-gray-700 hover:text-blue-700">ダッシュボード</a>
      <a href="appearance.php" class="text-sm text-gray-700 hover:text-blue-700">🎨 表示設定</a>
      <a href="knowledge.php" class="text-sm text-gray-700 hover:text-blue-700">📚 資料</a>
      <a href="insights.php" class="text-sm text-gray-700 hover:text-blue-700">💡 改善提案</a>
    </div>
    <div class="text-sm text-gray-500">
      <?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?>
      <a href="logout.php" class="ml-3 text-red-500 hover:underline">ログアウト</a>
    </div>
  </div>
</nav>
