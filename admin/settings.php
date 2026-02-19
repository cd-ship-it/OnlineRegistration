<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_admin();

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $price_per_kid_dollars = (float) ($_POST['price_per_kid'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'usd');
    $early_bird_start = trim($_POST['early_bird_start_date'] ?? '');
    $early_bird_end = trim($_POST['early_bird_end_date'] ?? '');
    $early_bird_price_per_kid_dollars = (float) ($_POST['early_bird_price_per_kid'] ?? 0);
    $multi_kid_price_per_kid_dollars = (float) ($_POST['multi_kid_price_per_kid'] ?? 0);
    $multi_kid_min_count = (int) ($_POST['multi_kid_min_count'] ?? 2);
    $max_kids_per_registration = (int) ($_POST['max_kids_per_registration'] ?? 10);
    $registration_open = isset($_POST['registration_open']) ? '1' : '0';
    $consent_content = trim($_POST['consent_content'] ?? '');
    $event_description = trim($_POST['event_description'] ?? '');
    $groups_max_children = (int) ($_POST['groups_max_children'] ?? 8);
    $groups_count = (int) ($_POST['groups_count'] ?? 8);

    if ($price_per_kid_dollars < 0) $errors[] = 'Price per kid cannot be negative.';
    if ($max_kids_per_registration < 1) $errors[] = 'Max kids per registration must be at least 1.';
    if ($groups_max_children < 1) $errors[] = 'Max children per group must be at least 1.';
    if ($groups_count < 1) $errors[] = 'Number of groups must be at least 1.';

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute(['price_per_kid_cents', (string) (int) round($price_per_kid_dollars * 100)]);
        $stmt->execute(['currency', $currency]);
        $stmt->execute(['early_bird_start_date', $early_bird_start]);
        $stmt->execute(['early_bird_end_date', $early_bird_end]);
        $stmt->execute(['early_bird_price_per_kid_cents', (string) (int) round($early_bird_price_per_kid_dollars * 100)]);
        $stmt->execute(['multi_kid_price_per_kid_cents', (string) (int) round($multi_kid_price_per_kid_dollars * 100)]);
        $stmt->execute(['multi_kid_min_count', (string) $multi_kid_min_count]);
        $stmt->execute(['max_kids_per_registration', (string) $max_kids_per_registration]);
        $stmt->execute(['registration_open', $registration_open]);
        $stmt->execute(['consent_content', $consent_content]);
        $stmt->execute(['event_description', $event_description]);
        $stmt->execute(['groups_max_children', (string) $groups_max_children]);
        $stmt->execute(['groups_count', (string) $groups_count]);
        $message = 'Settings saved.';
    }
}

$settings = get_settings($pdo);
$price_per_kid_dollars = ((int) ($settings['price_per_kid_cents'] ?? 5000)) / 100.0;
$currency = $settings['currency'] ?? 'usd';
$early_bird_start = $settings['early_bird_start_date'] ?? '';
$early_bird_end = $settings['early_bird_end_date'] ?? '';
$early_bird_price_per_kid_dollars = ((int) ($settings['early_bird_price_per_kid_cents'] ?? 0)) / 100.0;
$multi_kid_price_per_kid_dollars = ((int) ($settings['multi_kid_price_per_kid_cents'] ?? 0)) / 100.0;
$multi_kid_min_count = $settings['multi_kid_min_count'] ?? '2';
$max_kids_per_registration = $settings['max_kids_per_registration'] ?? '10';
$registration_open = ($settings['registration_open'] ?? '1') === '1';
$consent_content = $settings['consent_content'] ?? '';
$event_description = $settings['event_description'] ?? '';
$groups_max_children = $settings['groups_max_children'] ?? '8';
$groups_count = $settings['groups_count'] ?? '8';

layout_head('Admin – Settings');
?>
<div class="max-w-3xl mx-auto px-4 py-8">
  <div class="flex justify-between items-center mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Pricing &amp; Settings</h1>
    <nav class="flex gap-4">
      <a href="<?= APP_URL ?>/admin/registrations" class="text-indigo-600 hover:underline">Registrations</a>
      <a href="<?= APP_URL ?>/admin/assigngroups" class="text-indigo-600 hover:underline">Assign Groups</a>
      <a href="<?= APP_URL ?>/admin/logout" class="text-gray-600 hover:underline">Logout</a>
    </nav>
  </div>

  <?php if ($message): ?>
  <div class="card border-green-200 bg-green-50 text-green-800 mb-6"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
  <ul class="list-disc list-inside text-red-600 text-sm mb-6">
    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <form method="post" action="" class="space-y-6">
    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Event Description</h2>
      <p class="text-sm text-gray-600 mb-2">This description appears on the registration page. Include date, time, age requirements, and any other event details.</p>
      <label for="event_description" class="block text-sm font-medium text-gray-700 mb-1">Event Description</label>
      <textarea id="event_description" name="event_description" rows="6" class="input-field w-full resize-y" placeholder="e.g.&#10;At Rainforest Falls, kids explore the nature of God with awesome Bible-learning experiences.&#10;&#10;DATE: JUNE 15-19 – 9 am - 12:30 pm&#10;AGE: Age 4 as of 6/15/2025 through entering 5th grade in Fall 2026"><?= htmlspecialchars($event_description) ?></textarea>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Pricing</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label for="price_per_kid" class="block text-sm font-medium text-gray-700 mb-1">Price per kid ($)</label>
          <input type="number" id="price_per_kid" name="price_per_kid" min="0" step="0.01" value="<?= number_format($price_per_kid_dollars, 2, '.', '') ?>" class="input-field">
          <p class="text-xs text-gray-500 mt-1">e.g. 50.00</p>
        </div>
        <div>
          <label for="currency" class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
          <input type="text" id="currency" name="currency" value="<?= htmlspecialchars($currency) ?>" class="input-field" maxlength="3">
        </div>
      </div>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Early bird discount</h2>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label for="early_bird_start_date" class="block text-sm font-medium text-gray-700 mb-1">Start date</label>
          <input type="date" id="early_bird_start_date" name="early_bird_start_date" value="<?= htmlspecialchars($early_bird_start) ?>" class="input-field">
        </div>
        <div>
          <label for="early_bird_end_date" class="block text-sm font-medium text-gray-700 mb-1">End date</label>
          <input type="date" id="early_bird_end_date" name="early_bird_end_date" value="<?= htmlspecialchars($early_bird_end) ?>" class="input-field">
        </div>
        <div>
          <label for="early_bird_price_per_kid" class="block text-sm font-medium text-gray-700 mb-1">Price per kid ($)</label>
          <input type="number" id="early_bird_price_per_kid" name="early_bird_price_per_kid" min="0" step="0.01" value="<?= number_format($early_bird_price_per_kid_dollars, 2, '.', '') ?>" class="input-field">
          <p class="text-xs text-gray-500 mt-1">Actual price per kid in this period</p>
        </div>
      </div>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Multiple kids discount</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label for="multi_kid_min_count" class="block text-sm font-medium text-gray-700 mb-1">Minimum # of kids</label>
          <input type="number" id="multi_kid_min_count" name="multi_kid_min_count" min="1" value="<?= htmlspecialchars($multi_kid_min_count) ?>" class="input-field">
        </div>
        <div>
          <label for="multi_kid_price_per_kid" class="block text-sm font-medium text-gray-700 mb-1">Price per kid ($)</label>
          <input type="number" id="multi_kid_price_per_kid" name="multi_kid_price_per_kid" min="0" step="0.01" value="<?= number_format($multi_kid_price_per_kid_dollars, 2, '.', '') ?>" class="input-field">
          <p class="text-xs text-gray-500 mt-1">Actual price per kid when 2+ kids</p>
        </div>
      </div>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Consent items</h2>
      <p class="text-sm text-gray-600 mb-2">Enter the consent text shown to parents during registration. <strong>Each paragraph is one consent item</strong> (parents must check a box for each). Separate paragraphs with a blank line.</p>
      <label for="consent_content" class="block text-sm font-medium text-gray-700 mb-1">Consent content</label>
      <textarea id="consent_content" name="consent_content" rows="16" class="input-field w-full font-mono text-sm resize-y" placeholder="e.g.&#10;&#10;Consent for Activity Participation&#10;I grant permission for XXXX to participate...&#10;&#10;Consent for Transportation&#10;I grant permission for my child to be transported..."><?= htmlspecialchars($consent_content) ?></textarea>
      <p class="text-sm text-gray-500 mt-2">The Photo &amp; Video Release (Section 5) is always shown at the end of the consent form and cannot be edited here.</p>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Assign Groups</h2>
      <p class="text-sm text-gray-600 mb-3">Used on the <a href="<?= APP_URL ?>/admin/assigngroups" class="text-indigo-600 hover:underline">Assign Groups</a> page.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label for="groups_max_children" class="block text-sm font-medium text-gray-700 mb-1">Max children per group</label>
          <input type="number" id="groups_max_children" name="groups_max_children" min="1" value="<?= htmlspecialchars($groups_max_children) ?>" class="input-field w-32">
        </div>
        <div>
          <label for="groups_count" class="block text-sm font-medium text-gray-700 mb-1">Number of groups</label>
          <input type="number" id="groups_count" name="groups_count" min="1" value="<?= htmlspecialchars($groups_count) ?>" class="input-field w-32">
        </div>
      </div>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Other</h2>
      <div class="space-y-4">
        <div>
          <label for="max_kids_per_registration" class="block text-sm font-medium text-gray-700 mb-1">Max kids per registration</label>
          <input type="number" id="max_kids_per_registration" name="max_kids_per_registration" min="1" value="<?= htmlspecialchars($max_kids_per_registration) ?>" class="input-field w-32">
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" id="registration_open" name="registration_open" value="1" <?= $registration_open ? 'checked' : '' ?> class="rounded border-gray-300 text-indigo-600">
          <label for="registration_open" class="text-sm text-gray-700">Registration open</label>
        </div>
      </div>
    </div>

    <button type="submit" class="btn-primary">Save settings</button>
  </form>

  <div class="mt-8 p-4 bg-gray-100 rounded-lg text-sm text-gray-600">
    <strong>Preview:</strong> 1 kid = <?= format_money(compute_total_dollars($pdo, 1)) ?>,
    2 kids = <?= format_money(compute_total_dollars($pdo, 2)) ?>,
    3 kids = <?= format_money(compute_total_dollars($pdo, 3)) ?>.
  </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
