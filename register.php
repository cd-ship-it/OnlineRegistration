<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
require_once __DIR__ . '/vendor/autoload.php';

$errors = [];
$success_message = '';

// Step 2: From consent page — create registration and redirect to Stripe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
    $data = $_SESSION['vbs_registration_data'] ?? null;
    if (!$data || empty($data['kid_rows'])) {
        header('Location: ' . APP_URL . '/register', true, 302);
        exit;
    }
    $parent_first = $data['parent_first_name'];
    $parent_last = $data['parent_last_name'];
    $email = $data['email'];
    $phone = $data['phone'] ?? '';
    $address = $data['address'] ?? '';
    $home_church = $data['home_church'] ?? '';
    $emergency_contact_name = $data['emergency_contact_name'] ?? null;
    $emergency_contact_phone = $data['emergency_contact_phone'] ?? null;
    $emergency_contact_relationship = $data['emergency_contact_relationship'] ?? null;
    $kid_rows = $data['kid_rows'];
    $payment_error = null;
    try {
        $total_dollars = compute_total_dollars($pdo, count($kid_rows));
        if ($total_dollars < 0.50) {
            header('Location: ' . APP_URL . '/register', true, 302);
            exit;
        }
        if (!STRIPE_SECRET_KEY) {
            throw new Exception('Stripe is not configured. Please set STRIPE_SECRET_KEY in .env.');
        }
        $pdo->beginTransaction();
        // Build INSERT from columns that exist (handles DBs created before address/home_church were added)
        $reg_columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations'")->fetchAll(PDO::FETCH_COLUMN);
        $wanted = [
            'parent_first_name' => $parent_first,
            'parent_last_name' => $parent_last,
            'email' => $email,
            'phone' => $phone,
            'address' => $address ?: null,
            'home_church' => $home_church ?: null,
            'emergency_contact_name' => $emergency_contact_name,
            'emergency_contact_phone' => $emergency_contact_phone,
            'emergency_contact_relationship' => $emergency_contact_relationship,
            'consent_accepted' => 1,
            'status' => 'draft',
            'total_amount_cents' => (int) round($total_dollars * 100),
        ];
        $cols = [];
        $vals = [];
        foreach ($wanted as $col => $val) {
            if (in_array($col, $reg_columns, true)) {
                $cols[] = '`' . $col . '`';
                $vals[] = $val;
            }
        }
        $placeholders = implode(',', array_fill(0, count($vals), '?'));
        $stmt = $pdo->prepare('INSERT INTO registrations (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')');
        $stmt->execute($vals);
        $registration_id = (int) $pdo->lastInsertId();
        // Build kids INSERT from columns that exist (handles DBs with older schema)
        $kid_columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_kids'")->fetchAll(PDO::FETCH_COLUMN);
        $kid_cols_wanted = ['registration_id', 'first_name', 'last_name', 'age', 'gender', 'date_of_birth', 'last_grade_completed', 'medical_allergy_info', 'sort_order'];
        $kid_cols = [];
        foreach ($kid_cols_wanted as $c) {
            if (in_array($c, $kid_columns, true)) {
                $kid_cols[] = '`' . $c . '`';
            }
        }
        $kid_placeholders = implode(',', array_fill(0, count($kid_cols), '?'));
        $kid_stmt = $pdo->prepare('INSERT INTO registration_kids (' . implode(',', $kid_cols) . ') VALUES (' . $kid_placeholders . ')');
        foreach ($kid_rows as $i => $k) {
            $kid_vals = [];
            foreach ($kid_cols_wanted as $c) {
                if (!in_array($c, $kid_columns, true)) continue;
                if ($c === 'registration_id') $kid_vals[] = $registration_id;
                elseif ($c === 'first_name') $kid_vals[] = $k['first_name'];
                elseif ($c === 'last_name') $kid_vals[] = $k['last_name'];
                elseif ($c === 'age') $kid_vals[] = $k['age'];
                elseif ($c === 'gender') $kid_vals[] = $k['gender'];
                elseif ($c === 'date_of_birth') $kid_vals[] = $k['date_of_birth'] ?? null;
                elseif ($c === 'last_grade_completed') $kid_vals[] = $k['last_grade_completed'] ?? null;
                elseif ($c === 'medical_allergy_info') $kid_vals[] = $k['medical_allergy_info'] ?? null;
                elseif ($c === 'sort_order') $kid_vals[] = $i;
            }
            $kid_stmt->execute($kid_vals);
        }
        $pdo->commit();
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => get_setting($pdo, 'currency', 'usd'),
                    'product_data' => ['name' => 'VBS Registration - ' . count($kid_rows) . ' kid(s)'],
                    'unit_amount' => (int) round($total_dollars * 100 / count($kid_rows)),
                ],
                'quantity' => count($kid_rows),
            ]],
            'mode' => 'payment',
            'success_url' => APP_URL . '/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => APP_URL . '/register?cancelled=1',
            'metadata' => ['registration_id' => (string) $registration_id],
        ]);
        if (empty($session->url)) {
            throw new Exception('Stripe did not return a checkout URL.');
        }
        unset($_SESSION['vbs_registration_data']);
        header('Location: ' . $session->url, true, 303);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $payment_error = $e->getMessage();
    }
    // Payment failed: send back to consent with error (session kept so they can retry)
    header('Location: ' . APP_URL . '/consent?payment_error=' . urlencode($payment_error ?? 'Payment could not be started.'), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent_first = trim($_POST['parent_first_name'] ?? '');
    $parent_last = trim($_POST['parent_last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $home_church = trim($_POST['home_church'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '') ?: null;
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '') ?: null;
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '') ?: null;
    $kids = $_POST['kids'] ?? [];

    if ($parent_first === '') $errors[] = 'Parent first name is required.';
    if ($parent_last === '') $errors[] = 'Parent last name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email.';

    $kid_rows = [];
    foreach ($kids as $i => $k) {
        $first = trim($k['first_name'] ?? '');
        $last = trim($k['last_name'] ?? '');
        $age = isset($k['age']) ? (int) $k['age'] : null;
        $gender = trim($k['gender'] ?? '');
        if (!in_array($gender, ['Boy', 'Girl'], true)) $gender = null;
        $dob = trim($k['date_of_birth'] ?? '');
        $dob = $dob !== '' ? $dob : null;
        $last_grade = trim($k['last_grade_completed'] ?? '') ?: null;
        $medical = trim($k['medical_allergy_info'] ?? '');
        if ($first !== '' || $last !== '' || $age !== null || $gender !== null || $medical !== '') {
            $kid_rows[] = [
                'first_name' => $first, 'last_name' => $last, 'age' => $age ?: null, 'gender' => $gender ?: null,
                'date_of_birth' => $dob, 'last_grade_completed' => $last_grade,
                'medical_allergy_info' => $medical
            ];
        }
    }
    if (count($kid_rows) === 0) $errors[] = 'Please add at least one child.';
    foreach ($kid_rows as $i => $k) {
        if ($k['first_name'] === '') $errors[] = "Kid " . ($i + 1) . ": first name is required.";
        if ($k['last_name'] === '') $errors[] = "Kid " . ($i + 1) . ": last name is required.";
    }

    $max_kids = (int) get_setting($pdo, 'max_kids_per_registration', 10);
    if (count($kid_rows) > $max_kids) {
        $errors[] = "Maximum $max_kids children per registration.";
    }

    if (empty($errors)) {
        $total_dollars = compute_total_dollars($pdo, count($kid_rows));
        if ($total_dollars < 0.50) {
            $errors[] = 'Minimum charge is $0.50. Please check admin pricing settings.';
        } else {
            $_SESSION['vbs_registration_data'] = [
                'parent_first_name' => $parent_first,
                'parent_last_name' => $parent_last,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'home_church' => $home_church,
                'emergency_contact_name' => $emergency_contact_name,
                'emergency_contact_phone' => $emergency_contact_phone,
                'emergency_contact_relationship' => $emergency_contact_relationship,
                'kid_rows' => $kid_rows,
            ];
            header('Location: ' . APP_URL . '/consent', true, 302);
            exit;
        }
    }
}

$price_per_kid_dollars = ((int) get_setting($pdo, 'price_per_kid_cents', 5000)) / 100.0;
$registration_open = get_setting($pdo, 'registration_open', '1');
// Form values: from POST (after validation errors) or from session (when returning from consent)
$form = [
    'parent_first_name' => '',
    'parent_last_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'home_church' => '',
    'emergency_contact_name' => '',
    'emergency_contact_phone' => '',
    'emergency_contact_relationship' => '',
    'kids' => [],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'payment')) {
    $form['parent_first_name'] = trim($_POST['parent_first_name'] ?? '');
    $form['parent_last_name'] = trim($_POST['parent_last_name'] ?? '');
    $form['email'] = trim($_POST['email'] ?? '');
    $form['phone'] = trim($_POST['phone'] ?? '');
    $form['address'] = trim($_POST['address'] ?? '');
    $form['home_church'] = trim($_POST['home_church'] ?? '');
    $form['emergency_contact_name'] = trim($_POST['emergency_contact_name'] ?? '');
    $form['emergency_contact_phone'] = trim($_POST['emergency_contact_phone'] ?? '');
    $form['emergency_contact_relationship'] = trim($_POST['emergency_contact_relationship'] ?? '');
    foreach ($_POST['kids'] ?? [] as $k) {
        $form['kids'][] = [
            'first_name' => trim($k['first_name'] ?? ''),
            'last_name' => trim($k['last_name'] ?? ''),
            'age' => isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : '',
            'gender' => trim($k['gender'] ?? ''),
            'date_of_birth' => trim($k['date_of_birth'] ?? ''),
            'last_grade_completed' => trim($k['last_grade_completed'] ?? ''),
            'medical_allergy_info' => trim($k['medical_allergy_info'] ?? ''),
        ];
    }
} elseif (!empty($_SESSION['vbs_registration_data'])) {
    $data = $_SESSION['vbs_registration_data'];
    $form['parent_first_name'] = $data['parent_first_name'] ?? '';
    $form['parent_last_name'] = $data['parent_last_name'] ?? '';
    $form['email'] = $data['email'] ?? '';
    $form['phone'] = $data['phone'] ?? '';
    $form['address'] = $data['address'] ?? '';
    $form['home_church'] = $data['home_church'] ?? '';
    $form['emergency_contact_name'] = $data['emergency_contact_name'] ?? '';
    $form['emergency_contact_phone'] = $data['emergency_contact_phone'] ?? '';
    $form['emergency_contact_relationship'] = $data['emergency_contact_relationship'] ?? '';
    foreach ($data['kid_rows'] ?? [] as $k) {
        $form['kids'][] = [
            'first_name' => $k['first_name'] ?? '',
            'last_name' => $k['last_name'] ?? '',
            'age' => $k['age'] ?? '',
            'gender' => $k['gender'] ?? '',
            'date_of_birth' => $k['date_of_birth'] ?? '',
            'last_grade_completed' => $k['last_grade_completed'] ?? '',
            'medical_allergy_info' => $k['medical_allergy_info'] ?? '',
        ];
    }
}
$kids_for_form = !empty($form['kids']) ? $form['kids'] : [['first_name'=>'','last_name'=>'','age'=>'','gender'=>'','date_of_birth'=>'','last_grade_completed'=>'','medical_allergy_info'=>'']];

require_once __DIR__ . '/includes/layout.php';
layout_head('VBS Registration');
$hero_img = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/') . '/img/image.webp';
?>
<header class="relative w-full min-h-[400px] overflow-hidden" aria-hidden="true">
  <img src="<?= htmlspecialchars($hero_img) ?>" alt="" class="absolute inset-0 w-full h-full min-h-[400px] object-cover object-center" width="1200" height="700">
  <div class="absolute inset-0 bg-gradient-to-t from-gray-900/60 to-transparent pointer-events-none"></div>
  <div class="absolute bottom-0 left-0 right-0 p-4 sm:p-6 text-white text-center pointer-events-none">

  </div>
</header>
<div class="max-w-2xl mx-auto px-4 py-10">
  <section class="mb-10 text-center">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-4">Crosspoint Church Rainforest Falls VBS 2026</h1>
    <p class="flex items-center justify-center gap-2 text-gray-600 mb-2">
      <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <a href="https://www.google.com/maps/search/?api=1&amp;query=658+Gibraltar+Court,+Milpitas,+CA+95035" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800 hover:underline">658 Gibraltar Court, Milpitas, CA 95035</a>
    </p>
    <p class="flex items-center justify-center gap-2 text-gray-600 mb-6">
      <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      Monday, Jun 15 to Friday, Jun 19 from 9 am to 12:30 pm PDT
    </p>
    <div class="card text-left">
      <p class="text-gray-700 mb-4">At Rainforest Falls, kids explore the nature of God with awesome Bible-learning experiences kids see, hear, and touch! Hands-on science experiments, team-building games, unforgettable Bible songs, and tasty treats are just a few of the activities that help faith flow into real life.</p>
      <p class="font-semibold text-gray-900 py-2">DATE:<br>JUNE 15-19 - 9 am - 12:30 pm</p>
      <p class="font-semibold text-gray-900 py-2">EARLY BIRD: <br>$70 (on or before 4/19)</p>
      <p class="font-semibold text-gray-900 py-2">REGULAR: <br>$110 (4/20 - 5/17)</p>
      <p class="font-semibold text-gray-900 py-2">AGE LIMIT:<br>Age 4 as of 6/15/2025 through entering 5th grade in Fall 2026</p>
    </div>
  </section>

  <?php if (!empty($errors)): ?>
  <div class="card border-red-200 bg-red-50 mb-6">
    <ul class="list-disc list-inside text-red-700 text-sm">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (isset($_GET['cancelled'])): ?>
  <div class="card border-amber-200 bg-amber-50 mb-6 text-amber-800">Payment was cancelled. You can complete the form again when ready.</div>
  <?php endif; ?>

  <?php if ($registration_open !== '1'): ?>
  <div class="card border-gray-200 bg-gray-100 text-gray-600 text-center">Registration is currently closed.</div>
  <?php else: ?>

  <form method="post" action="" class="space-y-8" id="registration-form">
    <h2 class="text-xl font-semibold text-gray-900">Registration</h2>
    <div class="card">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Parent / Guardian</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label for="parent_first_name" class="block text-sm font-medium text-gray-700 mb-1">First name *</label>
          <input type="text" id="parent_first_name" name="parent_first_name" required maxlength="100" value="<?= htmlspecialchars($form['parent_first_name']) ?>" class="input-field">
        </div>
        <div>
          <label for="parent_last_name" class="block text-sm font-medium text-gray-700 mb-1">Last name *</label>
          <input type="text" id="parent_last_name" name="parent_last_name" required maxlength="100" value="<?= htmlspecialchars($form['parent_last_name']) ?>" class="input-field">
        </div>
      </div>
      <div class="mt-4">
        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
        <input type="email" id="email" name="email" required value="<?= htmlspecialchars($form['email']) ?>" class="input-field">
      </div>
      <div class="mt-4">
        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
        <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($form['phone']) ?>" class="input-field">
      </div>
      <div class="mt-4">
        <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
        <input type="text" id="address" name="address" value="<?= htmlspecialchars($form['address']) ?>" class="input-field">
      </div>
      <div class="mt-4">
        <label for="home_church" class="block text-sm font-medium text-gray-700 mb-1">Home Church <span class="text-gray-500 font-normal">(optional)</span></label>
        <input type="text" id="home_church" name="home_church" value="<?= htmlspecialchars($form['home_church']) ?>" class="input-field" maxlength="255">
      </div>
    </div>

    <div class="card">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Kids</h2>
        <button type="button" id="add-kid" class="btn-secondary text-sm">+ Add kid</button>
      </div>
      <div id="kids-container" class="space-y-4">
        <?php foreach ($kids_for_form as $idx => $kid): ?>
        <div class="kid-block border border-gray-200 rounded-lg p-4 bg-gray-50/50" data-index="<?= $idx ?>">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="kids-<?= $idx ?>-first_name" class="block text-sm font-medium text-gray-700 mb-1">First name *</label>
              <input type="text" id="kids-<?= $idx ?>-first_name" name="kids[<?= $idx ?>][first_name]" required maxlength="100" value="<?= htmlspecialchars($kid['first_name']) ?>" class="input-field">
            </div>
            <div>
              <label for="kids-<?= $idx ?>-last_name" class="block text-sm font-medium text-gray-700 mb-1">Last name *</label>
              <input type="text" id="kids-<?= $idx ?>-last_name" name="kids[<?= $idx ?>][last_name]" required maxlength="100" value="<?= htmlspecialchars($kid['last_name']) ?>" class="input-field">
            </div>
          </div>
          <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
              <label for="kids-<?= $idx ?>-age" class="block text-sm font-medium text-gray-700 mb-1">Age</label>
              <input type="number" id="kids-<?= $idx ?>-age" name="kids[<?= $idx ?>][age]" min="1" max="18" value="<?= htmlspecialchars($kid['age'] !== '' ? $kid['age'] : '') ?>" class="input-field" aria-label="Age">
            </div>
            <div>
              <label for="kids-<?= $idx ?>-gender" class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
              <select id="kids-<?= $idx ?>-gender" name="kids[<?= $idx ?>][gender]" class="input-field" aria-label="Gender">
                <option value="">Select</option>
                <option value="Boy" <?= ($kid['gender'] ?? '') === 'Boy' ? 'selected' : '' ?>>Boy</option>
                <option value="Girl" <?= ($kid['gender'] ?? '') === 'Girl' ? 'selected' : '' ?>>Girl</option>
              </select>
            </div>
            <div>
              <label for="kids-<?= $idx ?>-date_of_birth" class="block text-sm font-medium text-gray-700 mb-1">Date of birth</label>
              <input type="date" id="kids-<?= $idx ?>-date_of_birth" name="kids[<?= $idx ?>][date_of_birth]" value="<?= htmlspecialchars($kid['date_of_birth']) ?>" class="input-field" aria-label="Date of birth">
            </div>
          </div>
          <div class="mt-4">
            <label for="kids-<?= $idx ?>-last_grade_completed" class="block text-sm font-medium text-gray-700 mb-1">Last school grade completed</label>
            <input type="text" id="kids-<?= $idx ?>-last_grade_completed" name="kids[<?= $idx ?>][last_grade_completed]" maxlength="20" value="<?= htmlspecialchars($kid['last_grade_completed']) ?>" class="input-field w-full">
          </div>
          <div class="mt-4">
            <label for="kids-<?= $idx ?>-medical" class="block text-sm font-medium text-gray-700 mb-1">Allergies / medical info</label>
            <textarea id="kids-<?= $idx ?>-medical" name="kids[<?= $idx ?>][medical_allergy_info]" rows="2" maxlength="500" class="input-field w-full resize-y" aria-label="Allergies / medical info"><?= htmlspecialchars($kid['medical_allergy_info']) ?></textarea>
          </div>
          <button type="button" class="remove-kid mt-4 text-sm text-red-600 hover:text-red-700" aria-label="Remove kid">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Emergency contact</h2>
      <p class="text-sm text-gray-600 mb-4">One emergency contact for this registration (applies to all children you are registering).</p>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-1">Emergency contact name</label>
          <input type="text" id="emergency_contact_name" name="emergency_contact_name" maxlength="100" value="<?= htmlspecialchars($form['emergency_contact_name']) ?>" class="input-field">
        </div>
        <div>
          <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Emergency contact phone</label>
          <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="50" value="<?= htmlspecialchars($form['emergency_contact_phone']) ?>" class="input-field">
        </div>
        <div>
          <label for="emergency_contact_relationship" class="block text-sm font-medium text-gray-700 mb-1">Relationship to child(ren)</label>
          <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" maxlength="50" value="<?= htmlspecialchars($form['emergency_contact_relationship']) ?>" class="input-field">
        </div>
      </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-4 justify-between items-center">
      <p class="text-gray-600 text-sm">Total will be calculated at checkout based on number of kids and any discounts.</p>
      <button type="submit" class="btn-primary px-6 py-3">Next</button>
    </div>
  </form>

  <?php endif; ?>

  <div class="mt-10 flex justify-center">
    <img src="https://crosspointchurchsv.org/branding/logos/Xpt-ID2015-1_1400x346.png" alt="Crosspoint Church" class="max-w-xs sm:max-w-md h-auto" width="350" height="86">
  </div>
</div>

<script>
(function() {
  const container = document.getElementById('kids-container');
  const addBtn = document.getElementById('add-kid');
  let index = <?= count($kids_for_form) ?>;

  addBtn.addEventListener('click', function() {
    const max = <?= (int) get_setting($pdo, 'max_kids_per_registration', 10) ?>;
    if (container.querySelectorAll('.kid-block').length >= max) return;
    const div = document.createElement('div');
    div.className = 'kid-block border border-gray-200 rounded-lg p-4 bg-gray-50/50';
    div.setAttribute('data-index', index);
    div.innerHTML = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
      '<div><label class="block text-sm font-medium text-gray-700 mb-1">First name *</label><input type="text" name="kids[' + index + '][first_name]" required maxlength="100" class="input-field"></div>' +
      '<div><label class="block text-sm font-medium text-gray-700 mb-1">Last name *</label><input type="text" name="kids[' + index + '][last_name]" required maxlength="100" class="input-field"></div>' +
      '</div><div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">' +
      '<div><label class="block text-sm font-medium text-gray-700 mb-1">Age</label><input type="number" name="kids[' + index + '][age]" min="1" max="18" class="input-field" aria-label="Age"></div>' +
      '<div><label class="block text-sm font-medium text-gray-700 mb-1">Gender</label><select name="kids[' + index + '][gender]" class="input-field" aria-label="Gender"><option value="">Select</option><option value="Boy">Boy</option><option value="Girl">Girl</option></select></div>' +
      '<div><label class="block text-sm font-medium text-gray-700 mb-1">Date of birth</label><input type="date" name="kids[' + index + '][date_of_birth]" class="input-field" aria-label="Date of birth"></div>' +
      '</div><div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Last school grade completed</label><input type="text" name="kids[' + index + '][last_grade_completed]" maxlength="20" class="input-field w-full"></div>' +
      '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Allergies / medical info</label>' +
      '<textarea name="kids[' + index + '][medical_allergy_info]" rows="2" maxlength="500" class="input-field w-full resize-y" aria-label="Allergies / medical info"></textarea></div>' +
      '<button type="button" class="remove-kid mt-4 text-sm text-red-600 hover:text-red-700" aria-label="Remove kid">Remove</button>';
    container.appendChild(div);
    index++;
    div.querySelector('.remove-kid').addEventListener('click', function() { removeKid(div); });
  });

  function removeKid(block) {
    if (container.querySelectorAll('.kid-block').length <= 1) return;
    block.remove();
  }

  container.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-kid')) removeKid(e.target.closest('.kid-block'));
  });
})();
</script>
</body>
</html>
