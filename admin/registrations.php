<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_admin();

$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'date';
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';

$order_columns = [
    'parent' => 'r.parent_last_name ' . $dir . ', r.parent_first_name ' . $dir,
    'email'  => 'r.email ' . $dir,
    'kids'   => 'kid_count ' . $dir,
    'photo'  => 'r.photo_consent ' . $dir,
    'status' => 'r.status ' . $dir,
    'date'   => 'r.created_at ' . $dir,
];
$sort_key = isset($order_columns[$sort]) ? $sort : 'date';
$order_sql = $order_columns[$sort_key];

$q = "SELECT r.*, (SELECT COUNT(*) FROM registration_kids k WHERE k.registration_id = r.id) AS kid_count FROM registrations r WHERE 1=1";
$params = [];
if ($status_filter !== '') {
    $q .= " AND r.status = ?";
    $params[] = $status_filter;
}
$q .= " ORDER BY " . $order_sql;
$stmt = $pdo->prepare($q);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

function sort_url($list_url, $status_filter, $sort, $dir, $column) {
    $params = [];
    if ($status_filter !== '') $params['status'] = $status_filter;
    $params['sort'] = $column;
    $params['dir'] = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    return $list_url . '?' . http_build_query($params);
}

$csv = isset($_GET['export']) && $_GET['export'] === 'csv';
if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vbs-registrations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Parent name', 'Email', 'Phone', 'Kids', 'Photo consent', 'Payment Status', 'Created']);
    foreach ($registrations as $r) {
        fputcsv($out, [
            $r['id'],
            $r['parent_first_name'] . ' ' . $r['parent_last_name'],
            $r['email'],
            $r['phone'],
            $r['kid_count'],
            $r['photo_consent'] ?? '',
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
      <a href="<?= APP_URL ?>/admin/assigngroups" class="text-indigo-600 hover:underline">Assign Groups</a>
      <a href="<?= APP_URL ?>/admin/settings" class="text-indigo-600 hover:underline">Settings</a>
      <a href="<?= APP_URL ?>/admin/logout" class="text-gray-600 hover:underline">Logout</a>
      <a href="?export=csv<?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>" class="btn-secondary text-sm">Export CSV</a>
    </nav>
  </div>

  <div class="mb-4 flex gap-2 flex-wrap">
    <?php $list_url = APP_URL . '/admin/registrations'; ?>
    <a href="?" class="px-3 py-1 rounded <?= $status_filter === '' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">All</a>
    <a href="?status=paid" class="px-3 py-1 rounded <?= $status_filter === 'paid' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Paid</a>
    <a href="?status=draft" class="px-3 py-1 rounded <?= $status_filter === 'draft' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Draft</a>
  </div>

  <div class="card overflow-x-auto p-0">
    <table class="w-full text-left">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'parent') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Parent<?= $sort_key === 'parent' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'email') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Email<?= $sort_key === 'email' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'kids') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Kids<?= $sort_key === 'kids' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'photo') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Photo consent<?= $sort_key === 'photo' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'status') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Payment Status<?= $sort_key === 'status' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'date') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Registered Date<?= $sort_key === 'date' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($registrations as $r): ?>
        <tr class="border-b border-gray-100 hover:bg-gray-50/50">
          <td class="px-4 py-3">
            <a href="<?= APP_URL ?>/admin/registrations/view?id=<?= (int) $r['id'] ?>" class="text-indigo-600 hover:underline"><?= htmlspecialchars($r['parent_first_name'] . ' ' . $r['parent_last_name']) ?></a>
          </td>
          <td class="px-4 py-3"><?= htmlspecialchars($r['email']) ?></td>
          <td class="px-4 py-3"><?= (int) $r['kid_count'] ?></td>
          <td class="px-4 py-3">
            <?php
            $pc = $r['photo_consent'] ?? '';
            $pc_yes = strtolower((string) $pc) === 'yes';
            $pc_no = strtolower((string) $pc) === 'no';
            $pc_bg = $pc_no ? 'bg-red-500 text-white' : ($pc_yes ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700');
            $pc_text = $pc_no ? 'No' : ($pc_yes ? 'Yes' : ($pc !== '' ? htmlspecialchars($pc) : '—'));
            ?>
            <span class="px-2 py-1 rounded text-xs font-medium <?= $pc_bg ?>"><?= $pc_text ?></span>
          </td>
          <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs <?= $r['status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= $r['created_at'] ? date('M j, Y g:i A', strtotime($r['created_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($registrations)): ?>
    <p class="px-4 py-8 text-gray-500 text-center">No registrations found.</p>
    <?php endif; ?>
  </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
