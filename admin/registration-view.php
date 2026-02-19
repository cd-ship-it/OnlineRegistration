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

function val($v)
{
  if ($v === null || $v === '')
    return '—';
  return $v;
}

function row($label, $value)
{
  echo '<tr><td class="px-4 py-2 text-sm font-medium text-gray-600 bg-blue-50/50 w-1/4">' . htmlspecialchars($label) . '</td><td class="px-4 py-2 text-sm text-gray-900">' . htmlspecialchars(val($value)) . '</td></tr>';
}

layout_head('Admin – Registration #' . $id);
admin_nav('registrations');
?>
<div class="max-w-4xl mx-auto px-4 py-6">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= APP_URL ?>/admin/registrations" class="text-sm text-indigo-600 hover:underline">← Back to list</a>
    <h1 class="text-2xl font-bold text-gray-900">Registration #<?= (int) $reg['id'] ?></h1>
  </div>

  <div class="space-y-6">
    <!-- Parent / Guardian -->
    <?php $parent_id = str_replace(' ', '', ($reg['parent_first_name'] ?? '') . ($reg['parent_last_name'] ?? '')); ?>
    <div class="card p-0 overflow-hidden" id="<?= htmlspecialchars($parent_id) ?>">
      <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Parent / Guardian:
        <?= htmlspecialchars(($reg['parent_first_name'] ?? '') . ' ' . ($reg['parent_last_name'] ?? '')) ?>
      </h2>
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
    <?php if (empty($kids)): ?>
      <div class="card p-0 overflow-hidden">
        <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Children</h2>
        <p class="px-4 py-4 text-sm text-gray-500">No children recorded.</p>
      </div>
    <?php else: ?>
      <?php foreach ($kids as $idx => $k):
        $kid_id = str_replace(' ', '', ($k['first_name'] ?? '') . ($k['last_name'] ?? ''));
        ?>
        <div class="card p-0 overflow-hidden" id="<?= htmlspecialchars($kid_id) ?>">
          <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">
            Child <?= $idx + 1 ?>: <?= htmlspecialchars(($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? '')) ?>
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-2 divide-x divide-gray-100">
            <table class="w-full">
              <tbody>
                <?php row('Name', ($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? '')); ?>
                <?php row('Age', $k['age'] !== null && $k['age'] !== '' ? $k['age'] : null); ?>
                <?php row('Gender', $k['gender'] ?? null); ?>
                <?php row('Date of birth', $k['date_of_birth'] ?? null); ?>
              </tbody>
            </table>
            <table class="w-full border-t border-gray-100 md:border-t-0">
              <tbody>
                <?php row('Grade Entering in Fall', $k['last_grade_completed'] ?? null); ?>
                <?php row('T-Shirt size', $k['t_shirt_size'] ?? null); ?>
                <?php row('Medical / allergies', $k['medical_allergy_info'] ?? null); ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <!-- Emergency contact -->
    <div class="card p-0 overflow-hidden">
      <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Emergency contact
      </h2>
      <table class="w-full">
        <tbody>
          <?php row('Name', $reg['emergency_contact_name'] ?? null); ?>
          <?php row('Phone', $reg['emergency_contact_phone'] ?? null); ?>
          <?php row('Relationship', $reg['emergency_contact_relationship'] ?? null); ?>
        </tbody>
      </table>
    </div>

    <!-- Consent & payment -->
    <?php
    $photo = $reg['photo_consent'] ?? null;
    $photo_is_yes = strtolower((string) $photo) === 'yes';
    $photo_is_no  = strtolower((string) $photo) === 'no';
    $photo_text   = $photo_is_yes ? 'Yes' : ($photo_is_no ? 'No' : val($photo));
    $photo_badge  = $photo_is_yes ? 'bg-green-500 text-white' : ($photo_is_no ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-600');
    ?>
    <div class="card p-0 overflow-hidden">
      <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200">Consent &amp; Payment</h2>
      <table class="w-full">
        <tbody>
          <?php row('Consent accepted', isset($reg['consent_accepted']) && $reg['consent_accepted'] ? 'Yes' : 'No'); ?>
          <?php row('Digital signature', $reg['digital_signature'] ?? null); ?>
          <tr>
            <td class="px-4 py-2 text-sm font-medium text-gray-600 bg-blue-50/50 w-1/4">Photo &amp; Video Release</td>
            <td class="px-4 py-2 text-sm text-gray-900">
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $photo_badge ?>"><?= htmlspecialchars($photo_text) ?></span>
            </td>
          </tr>
          <?php
          $re = $reg['receive_emails'] ?? '';
          $re_text  = $re === 'yes' ? 'Yes' : ($re === 'no' ? 'No' : '—');
          $re_badge = $re === 'yes' ? 'bg-green-500 text-white' : ($re === 'no' ? 'bg-gray-200 text-gray-600' : 'bg-gray-200 text-gray-600');
          ?>
          <tr>
            <td class="px-4 py-2 text-sm font-medium text-gray-600 bg-blue-50/50 w-1/4">Parent wants to receive future email</td>
            <td class="px-4 py-2 text-sm text-gray-900">
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $re_badge ?>"><?= htmlspecialchars($re_text) ?></span>
            </td>
          </tr>
          <?php row('How they heard about us', $reg['hear_from_us'] ?? null); ?>
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