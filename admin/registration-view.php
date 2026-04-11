<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw_kid') {
  csrf_verify();
  $rid    = (int) ($_POST['registration_id'] ?? 0);
  $kid_id = (int) ($_POST['kid_id'] ?? 0);
  if ($rid > 0 && $kid_id > 0) {
    $ok = admin_withdraw_kid($pdo, $rid, $kid_id);
    $frag = trim((string) ($_POST['fragment'] ?? ''));
    $hash = '';
    if ($frag !== '') {
      $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $frag);
      if ($safe !== '') {
        $hash = '#' . $safe;
      }
    }
    $q = $ok ? 'kid_withdrawn=1' : 'withdraw_err=1';
    header('Location: ' . APP_URL . '/admin/registrations/view?id=' . $rid . '&' . $q . $hash, true, 302);
    exit;
  }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
  header('Location: ' . APP_URL . '/admin/registrations', true, 302);
  exit;
}

$reg = admin_get_registration($pdo, $id);
if (!$reg) {
  header('Location: ' . APP_URL . '/admin/registrations', true, 302);
  exit;
}

$kids = admin_get_registration_kids($pdo, $id);

$flash_withdrawn = isset($_GET['kid_withdrawn']) && $_GET['kid_withdrawn'] === '1';
$flash_withdraw_err = isset($_GET['withdraw_err']) && $_GET['withdraw_err'] === '1';

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

  <?php if ($flash_withdrawn): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">
      This child was marked as withdrawn and removed from group assignment lists.
    </div>
  <?php endif; ?>
  <?php if ($flash_withdraw_err): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">
      Could not withdraw that child. Refresh and try again.
    </div>
  <?php endif; ?>

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
        $kid_anchor = str_replace(' ', '', ($k['first_name'] ?? '') . ($k['last_name'] ?? ''));
        $kid_display = trim(($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? ''));
        $is_withdrawn = isset($k['withdraw']) && (string) $k['withdraw'] === '1';
        ?>
        <div class="card p-0 overflow-hidden" id="<?= htmlspecialchars($kid_anchor) ?>">
          <h2 class="text-lg font-semibold text-gray-900 px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-2">
            <span>Child <?= $idx + 1 ?>: <?= htmlspecialchars($kid_display) ?></span>
            <?php if ($is_withdrawn): ?>
              <span class="text-xs font-semibold uppercase tracking-wide text-red-600">Withdrawn</span>
            <?php endif; ?>
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
          <?php if (!$is_withdrawn): ?>
            <div class="px-4 py-3 border-t border-gray-100 bg-white">
              <button type="button" class="text-sm font-medium text-red-600 hover:text-red-700 hover:underline"
                data-kid-id="<?= (int) $k['id'] ?>"
                data-kid-name="<?= htmlspecialchars($kid_display, ENT_QUOTES, 'UTF-8') ?>"
                data-anchor="<?= htmlspecialchars($kid_anchor, ENT_QUOTES, 'UTF-8') ?>"
                onclick="openWithdrawKidDialog(this)">Withdraw this kid</button>
            </div>
          <?php endif; ?>
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

<dialog id="withdraw-kid-dialog" class="rounded-xl shadow-2xl border border-gray-200 p-0 max-w-md w-[calc(100vw-2rem)] backdrop:bg-black/40">
  <form method="post" action="" id="withdraw-kid-form">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="withdraw_kid">
    <input type="hidden" name="registration_id" value="<?= (int) $id ?>">
    <input type="hidden" name="kid_id" id="withdraw-kid-form-kid-id" value="">
    <input type="hidden" name="fragment" id="withdraw-kid-form-fragment" value="">
    <div class="px-5 py-4 border-b border-gray-200">
      <h3 class="text-lg font-semibold text-gray-900">Confirm withdrawal</h3>
    </div>
    <div class="px-5 py-4 text-sm text-gray-700">
      <p>Withdraw <strong id="withdraw-kid-dialog-name"></strong> from this program? They will no longer appear in Assign Groups or group counts.</p>
    </div>
    <div class="px-5 py-3 bg-gray-50 flex flex-wrap justify-end gap-2 rounded-b-xl">
      <button type="button" class="btn-secondary" onclick="document.getElementById('withdraw-kid-dialog').close()">Cancel</button>
      <button type="submit" class="btn-danger text-sm">Confirm withdrawal</button>
    </div>
  </form>
</dialog>

<script>
function openWithdrawKidDialog(btn) {
  var id = btn.getAttribute('data-kid-id');
  var name = btn.getAttribute('data-kid-name') || '';
  var anchor = btn.getAttribute('data-anchor') || '';
  document.getElementById('withdraw-kid-form-kid-id').value = id;
  document.getElementById('withdraw-kid-form-fragment').value = anchor;
  document.getElementById('withdraw-kid-dialog-name').textContent = name;
  document.getElementById('withdraw-kid-dialog').showModal();
}
</script>
<?php layout_footer(); ?>
</body>

</html>