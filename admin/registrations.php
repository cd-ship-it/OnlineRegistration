<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_admin();

$status_filter = $_GET['status'] ?? '';
$q = "SELECT r.*, (SELECT COUNT(*) FROM registration_kids k WHERE k.registration_id = r.id) AS kid_count FROM registrations r WHERE 1=1";
$params = [];
if ($status_filter !== '') {
    $q .= " AND r.status = ?";
    $params[] = $status_filter;
}
$q .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($q);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csv = isset($_GET['export']) && $_GET['export'] === 'csv';
if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vbs-registrations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Parent name', 'Email', 'Phone', 'Kids', 'Total (cents)', 'Status', 'Created']);
    foreach ($registrations as $r) {
        fputcsv($out, [
            $r['id'],
            $r['parent_first_name'] . ' ' . $r['parent_last_name'],
            $r['email'],
            $r['phone'],
            $r['kid_count'],
            $r['total_amount_cents'],
            $r['status'],
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

layout_head('Admin – Registrations');
?>
<div class="max-w-5xl mx-auto px-4 py-8">
  <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Registrations</h1>
    <nav class="flex gap-4 items-center">
      <a href="<?= APP_URL ?>/admin/settings" class="text-indigo-600 hover:underline">Settings</a>
      <a href="<?= APP_URL ?>/admin/logout" class="text-gray-600 hover:underline">Logout</a>
      <a href="?export=csv<?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>" class="btn-secondary text-sm">Export CSV</a>
    </nav>
  </div>

  <div class="mb-4 flex gap-2 flex-wrap">
    <a href="?" class="px-3 py-1 rounded <?= $status_filter === '' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">All</a>
    <a href="?status=paid" class="px-3 py-1 rounded <?= $status_filter === 'paid' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Paid</a>
    <a href="?status=draft" class="px-3 py-1 rounded <?= $status_filter === 'draft' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Draft</a>
  </div>

  <div class="card overflow-x-auto p-0">
    <table class="w-full text-left">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">Parent</th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">Email</th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">Kids</th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">Total</th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">Status</th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($registrations as $r): ?>
        <tr class="border-b border-gray-100 hover:bg-gray-50/50">
          <td class="px-4 py-3"><?= htmlspecialchars($r['parent_first_name'] . ' ' . $r['parent_last_name']) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($r['email']) ?></td>
          <td class="px-4 py-3"><?= (int) $r['kid_count'] ?></td>
          <td class="px-4 py-3"><?= format_money($r['total_amount_cents']) ?></td>
          <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs <?= $r['status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($r['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($registrations)): ?>
    <p class="px-4 py-8 text-gray-500 text-center">No registrations found.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
