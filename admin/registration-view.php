<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    header('Location: ' . APP_URL . '/admin/registrations', true, 302);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ?");
$stmt->execute([$id]);
$reg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reg) {
    header('Location: ' . APP_URL . '/admin/registrations', true, 302);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM registration_kids WHERE registration_id = ? ORDER BY sort_order, id");
$stmt->execute([$id]);
$kids = $stmt->fetchAll(PDO::FETCH_ASSOC);

function val($v) {
    if ($v === null || $v === '') return '—';
    return $v;
}

function row($label, $value) {
    echo '<tr><td class="px-4 py-2 text-sm font-medium text-gray-600 w-1/3">' . htmlspecialchars($label) . '</td><td class="px-4 py-2 text-sm text-gray-900">' . htmlspecialchars(val($value)) . '</td></tr>';
}

layout_head('Admin – Registration #' . $id);
?>
<div class="max-w-4xl mx-auto px-4 py-8">
  <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Registration #<?= (int) $reg['id'] ?></h1>
    <nav class="flex gap-4 items-center">
      <a href="<?= APP_URL ?>/admin/registrations" class="text-indigo-600 hover:underline">← Back to list</a>
      <a href="<?= APP_URL ?>/admin/settings" class="text-gray-600 hover:underline">Settings</a>
      <a href="<?= APP_URL ?>/admin/logout" class="text-gray-600 hover:underline">Logout</a>
    </nav>
  </div>

  <div class="space-y-6">
    <!-- Parent / Guardian -->
    <div class="card p-0 overflow-hidden">
      <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Parent / Guardian</h2>
      <table class="w-full">
        <tbody>
          <?php row('First name', $reg['parent_first_name'] ?? null); ?>
          <?php row('Last name', $reg['parent_last_name'] ?? null); ?>
          <?php row('Email', $reg['email'] ?? null); ?>
          <?php row('Phone', $reg['phone'] ?? null); ?>
          <?php row('Address', $reg['address'] ?? null); ?>
          <?php row('Home church', $reg['home_church'] ?? null); ?>
          <?php row('Alternative pick up name', $reg['alternative_pickup_name'] ?? null); ?>
          <?php row('Alternative pick up phone', $reg['alternative_pickup_phone'] ?? null); ?>
        </tbody>
      </table>
    </div>
    <!-- Children -->
    <div class="card p-0 overflow-hidden">
          <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Children (<?= count($kids) ?>)</h2>
          <?php if (empty($kids)): ?>
          <p class="px-4 py-4 text-sm text-gray-500">No children recorded.</p>
          <?php else: ?>
          <?php foreach ($kids as $idx => $k): ?>
          <div class="border-b border-gray-100 last:border-b-0">
            <h3 class="text-sm font-medium text-gray-700 px-4 py-2 bg-gray-50/70">Child <?= $idx + 1 ?></h3>
            <table class="w-full">
              <tbody>
                <?php row('First name', $k['first_name'] ?? null); ?>
                <?php row('Last name', $k['last_name'] ?? null); ?>
                <?php row('Age', $k['age'] !== null && $k['age'] !== '' ? $k['age'] : null); ?>
                <?php row('Gender', $k['gender'] ?? null); ?>
                <?php row('Date of birth', $k['date_of_birth'] ?? null); ?>
                <?php row('Grade Entering in Fall', $k['last_grade_completed'] ?? null); ?>
                <?php row('T-Shirt size', $k['t_shirt_size'] ?? null); ?>
                <?php row('Medical / allergies', $k['medical_allergy_info'] ?? null); ?>
              </tbody>
            </table>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
    <!-- Photo consent (standalone) -->
    <?php
    $photo = $reg['photo_consent'] ?? null;
    $photo_is_no = strtolower((string) $photo) === 'no';
    $photo_is_yes = strtolower((string) $photo) === 'yes';
    $photo_bg = $photo_is_no ? 'bg-red-500' : ($photo_is_yes ? 'bg-green-500' : 'bg-gray-200');
    $photo_color = ($photo_is_no || $photo_is_yes) ? 'text-white' : 'text-gray-800';
    $photo_label_color = ($photo_is_no || $photo_is_yes) ? 'text-white/90' : 'text-gray-600';
    $photo_text = $photo_is_no ? 'NO' : ($photo_is_yes ? 'YES' : val($photo));
    ?>
    <div class="card p-0 overflow-hidden <?= $photo_bg ?>">
      <div class="px-4 py-4 text-center">
        <p class="text-sm font-medium uppercase tracking-wide <?= $photo_label_color ?>">Photo &amp; Video Release</p>
        <p class="mt-1 text-2xl font-bold <?= $photo_color ?>"><?= htmlspecialchars($photo_text) ?></p>
      </div>
    </div>

    <!-- Emergency contact -->
    <div class="card p-0 overflow-hidden">
      <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Emergency contact</h2>
      <table class="w-full">
        <tbody>
          <?php row('Name', $reg['emergency_contact_name'] ?? null); ?>
          <?php row('Phone', $reg['emergency_contact_phone'] ?? null); ?>
          <?php row('Relationship', $reg['emergency_contact_relationship'] ?? null); ?>
        </tbody>
      </table>
    </div>

    <!-- Consent & payment -->
    <div class="card p-0 overflow-hidden">
      <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Consent &amp; payment</h2>
      <table class="w-full">
        <tbody>
          <?php row('Consent accepted', isset($reg['consent_accepted']) && $reg['consent_accepted'] ? 'Yes' : 'No'); ?>
          <?php row('Digital signature', $reg['digital_signature'] ?? null); ?>
          <?php row('Status', $reg['status'] ?? null); ?>
          <?php row('Total', isset($reg['total_amount_cents']) ? format_money(((int) $reg['total_amount_cents']) / 100.0) : '—'); ?>
        </tbody>
      </table>
    </div>

    
  </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
