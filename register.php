<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/vendor/autoload.php';

$errors = [];
$payment_error = null;
$registration_open = get_setting($pdo, 'registration_open', '1');
$max_kids = (int) get_setting($pdo, 'max_kids_per_registration', 10);

// Last grade completed options (dropdown) — change this array to update the list
$grade_options = ['Preschool', 'Pre K', 'K', '1st', '2nd', '3rd', '4th', '5th'];

// T-shirt size options (dropdown) for each kid — change this array to update the list
$t_shirt_size_options = ['Youth XS', 'Youth S', 'Youth M', 'Youth L', 'Youth XL'];

// Load consent items for step 3 from admin settings (each paragraph = one consent item)
$content = get_setting($pdo, 'consent_content', '');
$content = str_replace(["\r\n", "\r"], ["\n", "\n"], $content);
$consent_paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n+/', $content)));
if (empty($consent_paragraphs)) {
    $consent_paragraphs = ['Consent terms have not been configured yet. Please contact the administrator.'];
}

// Price / early bird for display above form
$today = date('Y-m-d');
$early_bird_end = get_setting($pdo, 'early_bird_end_date', '');
$early_bird_price_cents = (int) get_setting($pdo, 'early_bird_price_per_kid_cents', 0);
$regular_price_cents = (int) get_setting($pdo, 'price_per_kid_cents', 0);
$show_early_bird = ($early_bird_end !== '' && $early_bird_price_cents > 0 && $today <= $early_bird_end);
$early_bird_end_display = $early_bird_end ? date('M j, Y', strtotime($early_bird_end)) : '';

// Default form state
$form = [
    'parent_first_name' => '', 'parent_last_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'home_church' => '',
    'alternative_pickup_name' => '', 'alternative_pickup_phone' => '',
    'emergency_contact_name' => '', 'emergency_contact_phone' => '', 'emergency_contact_relationship' => '',
];
$kids_for_form = [['first_name'=>'','last_name'=>'','age'=>'','gender'=>'','date_of_birth'=>'','last_grade_completed'=>'','t_shirt_size'=>'','medical_allergy_info'=>'']];
$initial_step = 1;
$digital_signature_value = '';
$photo_consent_value = '';

// Restore form from session when user returns after cancelling payment
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['cancelled']) && !empty($_SESSION['vbs_registration_data'])) {
    $saved = $_SESSION['vbs_registration_data'];
    $form = [
        'parent_first_name' => $saved['parent_first_name'] ?? '',
        'parent_last_name' => $saved['parent_last_name'] ?? '',
        'email' => $saved['email'] ?? '',
        'phone' => $saved['phone'] ?? '',
        'address' => $saved['address'] ?? '',
    'home_church' => $saved['home_church'] ?? '',
    'alternative_pickup_name' => $saved['alternative_pickup_name'] ?? '',
    'alternative_pickup_phone' => $saved['alternative_pickup_phone'] ?? '',
    'emergency_contact_name' => $saved['emergency_contact_name'] ?? '',
        'emergency_contact_phone' => $saved['emergency_contact_phone'] ?? '',
        'emergency_contact_relationship' => $saved['emergency_contact_relationship'] ?? '',
    ];
    $kids_for_form = [];
    foreach ($saved['kid_rows'] ?? [] as $k) {
        $kids_for_form[] = [
            'first_name' => $k['first_name'] ?? '',
            'last_name' => $k['last_name'] ?? '',
            'age' => $k['age'] ?? '',
            'gender' => $k['gender'] ?? '',
            'date_of_birth' => $k['date_of_birth'] ?? '',
            'last_grade_completed' => $k['last_grade_completed'] ?? '',
            't_shirt_size' => $k['t_shirt_size'] ?? '',
            'medical_allergy_info' => $k['medical_allergy_info'] ?? '',
        ];
    }
    if (empty($kids_for_form)) $kids_for_form = [['first_name'=>'','last_name'=>'','age'=>'','gender'=>'','date_of_birth'=>'','last_grade_completed'=>'','t_shirt_size'=>'','medical_allergy_info'=>'']];
    $digital_signature_value = $saved['digital_signature'] ?? '';
    $photo_consent_value = $saved['photo_consent'] ?? '';
    $initial_step = 3;
}

// Single submission: all data in one POST with action=payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
    $parent_first = trim($_POST['parent_first_name'] ?? '');
    $parent_last = trim($_POST['parent_last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $home_church = trim($_POST['home_church'] ?? '');
    $alternative_pickup_name = trim($_POST['alternative_pickup_name'] ?? '') ?: null;
    $alternative_pickup_phone = trim($_POST['alternative_pickup_phone'] ?? '') ?: null;
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '') ?: null;
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '') ?: null;
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '') ?: null;
    $digital_signature = trim($_POST['digital_signature'] ?? '');
    $kids = $_POST['kids'] ?? [];

    // Validate parent
    if ($parent_first === '') $errors[] = 'Parent first name is required.';
    if ($parent_last === '') $errors[] = 'Parent last name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email.';

    // Build kid_rows and validate kids
    $kid_rows = [];
    foreach ($kids as $i => $k) {
        $first = trim($k['first_name'] ?? '');
        $last = trim($k['last_name'] ?? '');
        $age = isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : null;
        $gender = trim($k['gender'] ?? '');
        if (!in_array($gender, ['Boy', 'Girl'], true)) $gender = null;
        $dob = trim($k['date_of_birth'] ?? '');
        $dob = $dob !== '' ? $dob : null;
        $last_grade = trim($k['last_grade_completed'] ?? '') ?: null;
        $t_shirt = trim($k['t_shirt_size'] ?? '') ?: null;
        $medical = trim($k['medical_allergy_info'] ?? '');
        if ($first !== '' || $last !== '' || $age !== null || $gender !== null || $medical !== '') {
            $kid_rows[] = [
                'first_name' => $first, 'last_name' => $last, 'age' => $age ?: null, 'gender' => $gender ?: null,
                'date_of_birth' => $dob, 'last_grade_completed' => $last_grade, 't_shirt_size' => $t_shirt, 'medical_allergy_info' => $medical
            ];
        }
    }
    if (count($kid_rows) === 0) $errors[] = 'Please add at least one child.';
    foreach ($kid_rows as $i => $k) {
        if ($k['first_name'] === '') $errors[] = 'Kid ' . ($i + 1) . ': first name is required.';
        if ($k['last_name'] === '') $errors[] = 'Kid ' . ($i + 1) . ': last name is required.';
    }
    if (count($kid_rows) > $max_kids) $errors[] = "Maximum $max_kids children per registration.";
    if ($digital_signature === '') $errors[] = 'Digital signature (your full name) is required.';
    $photo_consent_yes = !empty($_POST['photo_consent_yes']);
    $photo_consent_no = !empty($_POST['photo_consent_no']);
    $photo_consent = '';
    if ($photo_consent_yes && $photo_consent_no) {
        $errors[] = 'Please select only Yes or No for photo consent (Section 5), not both.';
    } elseif (!$photo_consent_yes && !$photo_consent_no) {
        $errors[] = 'Please select Yes or No for the photo consent (Section 5).';
    } else {
        $photo_consent = $photo_consent_yes ? 'yes' : 'no';
    }

    if (empty($errors)) {
        $total_dollars = compute_total_dollars($pdo, count($kid_rows));
        if ($total_dollars < 0.50) $errors[] = 'Minimum charge is $0.50. Please check admin pricing settings.';
    }

    if (!empty($errors)) {
        $form = [
            'parent_first_name' => $parent_first, 'parent_last_name' => $parent_last, 'email' => $email, 'phone' => $phone,
            'address' => $address, 'home_church' => $home_church,
            'alternative_pickup_name' => $alternative_pickup_name ?: '', 'alternative_pickup_phone' => $alternative_pickup_phone ?: '',
            'emergency_contact_name' => $emergency_contact_name ?: '', 'emergency_contact_phone' => $emergency_contact_phone ?: '', 'emergency_contact_relationship' => $emergency_contact_relationship ?: '',
        ];
        $kids_for_form = [];
        foreach ($kids as $k) {
            $kids_for_form[] = [
                'first_name' => trim($k['first_name'] ?? ''), 'last_name' => trim($k['last_name'] ?? ''),
                'age' => isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : '', 'gender' => trim($k['gender'] ?? ''),
                'date_of_birth' => trim($k['date_of_birth'] ?? ''), 'last_grade_completed' => trim($k['last_grade_completed'] ?? ''),
                't_shirt_size' => trim($k['t_shirt_size'] ?? ''), 'medical_allergy_info' => trim($k['medical_allergy_info'] ?? ''),
            ];
        }
        if (empty($kids_for_form)) $kids_for_form = [['first_name'=>'','last_name'=>'','age'=>'','gender'=>'','date_of_birth'=>'','last_grade_completed'=>'','t_shirt_size'=>'','medical_allergy_info'=>'']];
        $digital_signature_value = $digital_signature;
        $photo_consent_value = $photo_consent;
        if (!empty($errors)) {
            if (preg_match('/Parent|Email|email/i', implode(' ', $errors))) $initial_step = 1;
            elseif (preg_match('/Kid|child|Maximum|Minimum/i', implode(' ', $errors))) $initial_step = 2;
            else $initial_step = 3;
        }
    } else {
        try {
            if (!STRIPE_SECRET_KEY) throw new Exception('Stripe is not configured.');
            $pdo->beginTransaction();
            $reg_columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations'")->fetchAll(PDO::FETCH_COLUMN);
            $photo_consent_val = ($photo_consent === 'yes' || $photo_consent === 'no') ? $photo_consent : null;
            $now = date('Y-m-d H:i:s'); // Los Angeles (set in config.php)
            $wanted = [
                'parent_first_name' => $parent_first, 'parent_last_name' => $parent_last, 'email' => $email, 'phone' => $phone,
                'address' => $address ?: null, 'home_church' => $home_church ?: null,
                'alternative_pickup_name' => $alternative_pickup_name, 'alternative_pickup_phone' => $alternative_pickup_phone,
                'emergency_contact_name' => $emergency_contact_name, 'emergency_contact_phone' => $emergency_contact_phone, 'emergency_contact_relationship' => $emergency_contact_relationship,
                'consent_accepted' => 1, 'digital_signature' => $digital_signature ?: null, 'consent_agreed_at' => $now,
                'photo_consent' => $photo_consent_val,
                'status' => 'draft', 'total_amount_cents' => (int) round($total_dollars * 100),
                'created_at' => $now, 'updated_at' => $now,
            ];
            $cols = []; $vals = [];
            foreach ($wanted as $col => $val) {
                if (in_array($col, $reg_columns, true)) { $cols[] = '`' . $col . '`'; $vals[] = $val; }
            }
            $stmt = $pdo->prepare('INSERT INTO registrations (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')');
            $stmt->execute($vals);
            $registration_id = (int) $pdo->lastInsertId();
            $kid_columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_kids'")->fetchAll(PDO::FETCH_COLUMN);
            $kid_cols_wanted = ['registration_id', 'first_name', 'last_name', 'age', 'gender', 'date_of_birth', 'last_grade_completed', 't_shirt_size', 'medical_allergy_info', 'sort_order'];
            $kid_cols = array_filter($kid_cols_wanted, function ($c) use ($kid_columns) { return in_array($c, $kid_columns, true); });
            $kid_stmt = $pdo->prepare('INSERT INTO registration_kids (`' . implode('`,`', $kid_cols) . '`) VALUES (' . implode(',', array_fill(0, count($kid_cols), '?')) . ')');
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
                    elseif ($c === 't_shirt_size') $kid_vals[] = $k['t_shirt_size'] ?? null;
                    elseif ($c === 'medical_allergy_info') $kid_vals[] = $k['medical_allergy_info'] ?? null;
                    elseif ($c === 'sort_order') $kid_vals[] = $i;
                }
                if (!empty($kid_vals)) $kid_stmt->execute($kid_vals);
            }
            $pdo->commit();
            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'customer_email' => $email,
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
            if (empty($session->url)) throw new Exception('Stripe did not return a checkout URL.');
            // Save form data to session so if user cancels payment they get it back
            $_SESSION['vbs_registration_data'] = [
                'parent_first_name' => $parent_first,
                'parent_last_name' => $parent_last,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'home_church' => $home_church,
                'alternative_pickup_name' => $alternative_pickup_name,
                'alternative_pickup_phone' => $alternative_pickup_phone,
                'emergency_contact_name' => $emergency_contact_name,
                'emergency_contact_phone' => $emergency_contact_phone,
                'emergency_contact_relationship' => $emergency_contact_relationship,
                'kid_rows' => $kid_rows,
                'digital_signature' => $digital_signature,
                'photo_consent' => ($photo_consent === 'yes' || $photo_consent === 'no') ? $photo_consent : null,
            ];
            header('Location: ' . $session->url, true, 303);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $payment_error = $e->getMessage();
            $form = [
                'parent_first_name' => $parent_first, 'parent_last_name' => $parent_last, 'email' => $email, 'phone' => $phone,
                'address' => $address, 'home_church' => $home_church,
                'alternative_pickup_name' => $alternative_pickup_name ?: '', 'alternative_pickup_phone' => $alternative_pickup_phone ?: '',
                'emergency_contact_name' => $emergency_contact_name ?: '', 'emergency_contact_phone' => $emergency_contact_phone ?: '', 'emergency_contact_relationship' => $emergency_contact_relationship ?: '',
            ];
            $kids_for_form = [];
            foreach ($kids as $k) {
                $kids_for_form[] = [
                    'first_name' => trim($k['first_name'] ?? ''), 'last_name' => trim($k['last_name'] ?? ''),
                    'age' => isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : '', 'gender' => trim($k['gender'] ?? ''),
                    'date_of_birth' => trim($k['date_of_birth'] ?? ''), 'last_grade_completed' => trim($k['last_grade_completed'] ?? ''),
                    't_shirt_size' => trim($k['t_shirt_size'] ?? ''), 'medical_allergy_info' => trim($k['medical_allergy_info'] ?? ''),
                ];
            }
            if (empty($kids_for_form)) $kids_for_form = [['first_name'=>'','last_name'=>'','age'=>'','gender'=>'','date_of_birth'=>'','last_grade_completed'=>'','t_shirt_size'=>'','medical_allergy_info'=>'']];
            $digital_signature_value = $digital_signature;
            $photo_consent_value = $photo_consent;
            $initial_step = 3;
        }
    }
}

$kid_names_str = implode(', ', array_map(function ($k) {
    return trim(($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? ''));
}, $kids_for_form));

layout_head('VBS Registration');
$hero_img = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/') . '/img/image.webp';
?>
<header class="relative w-full min-h-[400px] overflow-hidden" aria-hidden="true">
  <img src="<?= htmlspecialchars($hero_img) ?>" alt="" class="absolute inset-0 w-full h-full min-h-[400px] object-cover object-center" width="1200" height="700">
  <div class="absolute inset-0 bg-gradient-to-t from-gray-900/60 to-transparent pointer-events-none"></div>
</header>
<div class="max-w-2xl mx-auto px-4 py-10">
  <section class="mb-8 text-center">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-4">Crosspoint Church Rainforest Falls VBS 2026</h1>
    <p class="flex items-center justify-center gap-2 text-gray-600 mb-2">
      <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <a href="https://www.google.com/maps/search/?api=1&amp;query=658+Gibraltar+Court,+Milpitas,+CA+95035" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800 hover:underline">658 Gibraltar Court, Milpitas, CA 95035</a>
    </p>
    <p class="flex items-center justify-center gap-2 text-gray-600 mb-6">
      <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      Monday, Jun 15 to Friday, Jun 19 from 9 am to 12:30 pm PDT
    </p>
    <div class="card text-left">
      <p class="text-gray-700 mb-4">At Rainforest Falls, kids explore the nature of God with awesome Bible-learning experiences.</p>
      <p class="font-semibold text-gray-900 py-2">DATE: JUNE 15-19 – 9 am - 12:30 pm</p>
      <p class="font-semibold text-gray-900 py-2">AGE: Age 4 as of 6/15/2025 through entering 5th grade in Fall 2026</p>
    </div>
  </section>

  <?php if (!empty($errors)): ?>
  <div class="card border-red-200 bg-red-50 mb-6">
    <ul class="list-disc list-inside text-red-700 text-sm">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if ($payment_error): ?>
  <div class="card border-red-200 bg-red-50 mb-6 text-red-700 text-sm">We couldn’t start payment. <?= htmlspecialchars($payment_error) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['cancelled'])): ?>
  <div class="card border-amber-200 bg-amber-50 mb-6 text-amber-800">Payment was cancelled. You can complete the form again when ready.</div>
  <?php endif; ?>

  <?php if ($registration_open !== '1'): ?>
  <div class="card border-gray-200 bg-gray-100 text-gray-600 text-center">Registration is currently closed.</div>
  <?php else: ?>

  <form method="post" action="" id="registration-form" data-initial-step="<?= (int) $initial_step ?>">
    <input type="hidden" name="action" value="payment">

    <!-- Price boxes: early bird (if active) + regular -->
    <div class="mb-8 <?= $show_early_bird ? 'grid grid-cols-1 sm:grid-cols-2 gap-4' : '' ?>">
      <?php if ($show_early_bird): ?>
      <div class="rounded-xl  bg-amber-100 px-5 py-4 shadow-sm">
        <p class="text-sm font-semibold uppercase tracking-wide text-amber-900/80">Early bird discount</p>
        <p class="mt-1 text-2xl font-bold text-amber-900"><?= format_money($early_bird_price_cents / 100.0) ?> <span class="text-lg font-semibold text-amber-900/90">per child</span></p>
        <p class="mt-2 text-lg font-medium text-amber-900/90">Ends <?= htmlspecialchars($early_bird_end_display) ?></p>
      </div>
      <div class="rounded-xl -white px-5 py-4 shadow-sm">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">Regular price</p>
        <p class="mt-1 text-2xl font-bold text-gray-900"><?= format_money($regular_price_cents / 100.0) ?> <span class="text-lg font-semibold text-gray-600">per child</span></p>
      </div>
      <?php else: ?>
      <div class="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm max-w-md">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">Registration price</p>
        <p class="mt-1 text-2xl font-bold text-gray-900"><?= format_money($regular_price_cents / 100.0) ?> <span class="text-lg font-semibold text-gray-600">per child</span></p>
      </div>
      <?php endif; ?>
    </div>
    <hr class="my-6 border-gray-200">

    <!-- Step 1: Parent + Emergency -->
    <div id="step-1" class="registration-step step-panel" aria-label="Step 1 – Parent &amp; emergency contact">
      <div class="flex items-center gap-2 mb-6">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">1</span>
        <h2 class="text-xl font-semibold text-gray-900">Parent / Guardian &amp; Emergency Contact</h2>
      </div>
      <div class="card mb-4">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Parent / Guardian</h3>
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
          <label for="home_church" class="block text-sm font-medium text-gray-700 mb-1">Home Church <span class="text-gray-500 font-normal"></span></label>
          <input type="text" id="home_church" name="home_church" value="<?= htmlspecialchars($form['home_church']) ?>" class="input-field" maxlength="255">
        </div>
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="alternative_pickup_name" class="block text-sm font-medium text-gray-700 mb-1">Alternative pick up name <span class="text-gray-500 font-normal"></span></label>
            <input type="text" id="alternative_pickup_name" name="alternative_pickup_name" value="<?= htmlspecialchars($form['alternative_pickup_name']) ?>" class="input-field" maxlength="100">
          </div>
          <div>
            <label for="alternative_pickup_phone" class="block text-sm font-medium text-gray-700 mb-1">Alternative pick up phone <span class="text-gray-500 font-normal"></span></label>
            <input type="tel" id="alternative_pickup_phone" name="alternative_pickup_phone" value="<?= htmlspecialchars($form['alternative_pickup_phone']) ?>" class="input-field" maxlength="50">
          </div>
        </div>
      </div>
      <div class="card mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Emergency contact</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input type="text" id="emergency_contact_name" name="emergency_contact_name" maxlength="100" value="<?= htmlspecialchars($form['emergency_contact_name']) ?>" class="input-field">
          </div>
          <div>
            <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="50" value="<?= htmlspecialchars($form['emergency_contact_phone']) ?>" class="input-field">
          </div>
          <div>
            <label for="emergency_contact_relationship" class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
            <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" maxlength="50" value="<?= htmlspecialchars($form['emergency_contact_relationship']) ?>" class="input-field">
          </div>
        </div>
      </div>
      <div class="flex justify-end">
        <button type="button" class="btn-primary px-6 py-3 step-next" data-next="2">Next: Add Child(ren)</button>
      </div>
    </div>

    <!-- Step 2: Kids -->
    <div id="step-2" class="registration-step step-panel hidden" aria-label="Step 2 – Add kids">
      <div class="flex items-center gap-2 mb-6">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">2</span>
        <h2 class="text-xl font-semibold text-gray-900">Add Kids</h2>
      </div>
      <div class="card mb-6">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-semibold text-gray-900">Child(ren) you are registering</h3>
          <button type="button" id="add-kid" class="btn-secondary text-sm">+ Add Child</button>
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
                <input type="number" id="kids-<?= $idx ?>-age" name="kids[<?= $idx ?>][age]" min="1" max="18" value="<?= htmlspecialchars($kid['age'] !== '' ? $kid['age'] : '') ?>" class="input-field">
              </div>
              <div>
                <label for="kids-<?= $idx ?>-gender" class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                <select id="kids-<?= $idx ?>-gender" name="kids[<?= $idx ?>][gender]" class="input-field">
                  <option value="">Select</option>
                  <option value="Boy" <?= ($kid['gender'] ?? '') === 'Boy' ? 'selected' : '' ?>>Boy</option>
                  <option value="Girl" <?= ($kid['gender'] ?? '') === 'Girl' ? 'selected' : '' ?>>Girl</option>
                </select>
              </div>
              <div>
                <label for="kids-<?= $idx ?>-date_of_birth" class="block text-sm font-medium text-gray-700 mb-1">Date of birth</label>
                <input type="date" id="kids-<?= $idx ?>-date_of_birth" name="kids[<?= $idx ?>][date_of_birth]" value="<?= htmlspecialchars($kid['date_of_birth']) ?>" class="input-field">
              </div>
            </div>
            <div class="mt-4">
              <label for="kids-<?= $idx ?>-last_grade_completed" class="block text-sm font-medium text-gray-700 mb-1">Child Grade Entering in Fall 2026 (Note: Not the current grade)</label>
              <select id="kids-<?= $idx ?>-last_grade_completed" name="kids[<?= $idx ?>][last_grade_completed]" class="input-field w-full">
                <option value="">Select</option>
                <?php foreach ($grade_options as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($kid['last_grade_completed'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mt-4">
              <label for="kids-<?= $idx ?>-t_shirt_size" class="block text-sm font-medium text-gray-700 mb-1">T-Shirt size</label>
              <select id="kids-<?= $idx ?>-t_shirt_size" name="kids[<?= $idx ?>][t_shirt_size]" class="input-field w-full">
                <option value="">Select</option>
                <?php foreach ($t_shirt_size_options as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($kid['t_shirt_size'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mt-4">
              <label for="kids-<?= $idx ?>-medical" class="block text-sm font-medium text-gray-700 mb-1">Allergies / medical info</label>
              <textarea id="kids-<?= $idx ?>-medical" name="kids[<?= $idx ?>][medical_allergy_info]" rows="2" maxlength="500" class="input-field w-full resize-y"><?= htmlspecialchars($kid['medical_allergy_info']) ?></textarea>
            </div>
            <button type="button" class="remove-kid mt-4 text-sm text-red-600 hover:text-red-700" aria-label="Remove kid">Remove</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex flex-wrap gap-4 justify-between items-center">
        <button type="button" class="btn-secondary px-4 py-2 step-back" data-back="1">← Back</button>
        <button type="button" class="btn-primary px-6 py-3 step-next" data-next="3">Next: Consent &amp; payment</button>
      </div>
    </div>

    <!-- Step 3: Consent + Signature -->
    <div id="step-3" class="registration-step step-panel hidden" aria-label="Step 3 – Consent &amp; payment">
      <div class="flex items-center gap-2 mb-6">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">3</span>
        <h2 class="text-xl font-semibold text-gray-900">Consent &amp; Payment</h2>
      </div>
      <div class="space-y-4 mb-6">
        <?php foreach ($consent_paragraphs as $i => $block): ?>
          <?php
          $text = $block;
          if ($i === 0) $text = str_replace('XXXX', $kid_names_str, $text);
          $parts = explode("\n", $text, 2);
          $first_line = trim($parts[0]);
          $body = isset($parts[1]) ? trim($parts[1]) : '';
          $slug = 'section_' . $i;
          $cb_id = 'consent-item-' . $slug;
          ?>
          <div class="card">
            <?php if ($first_line !== ''): ?>
            <h3 class="text-base font-semibold text-gray-900 mb-2"><?= htmlspecialchars($first_line) ?></h3>
            <?php endif; ?>
            <?php if ($body !== ''): ?>
            <div class="text-gray-700 whitespace-pre-wrap leading-relaxed text-sm"><?= nl2br(htmlspecialchars($body)) ?></div>
            <?php endif; ?>
            <div class="mt-4 pt-4 border-t border-gray-200">
              <label class="flex gap-3 cursor-pointer group" for="<?= htmlspecialchars($cb_id) ?>">
                <input type="checkbox" id="<?= htmlspecialchars($cb_id) ?>" name="consent_items[<?= htmlspecialchars($slug) ?>]" value="1" required class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <span class="text-sm text-gray-700 group-hover:text-gray-900">I have read and agree to the terms above.</span>
              </label>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Section 5 - Photo & Video Release (hardcoded, not from database) -->
        <div class="card">
          <h3 class="text-base font-semibold text-gray-900 mb-2">SECTION 5 – PHOTO &amp; VIDEO RELEASE</h3>
          <p class="text-gray-700 leading-relaxed text-sm mb-4">Please select one of the following options regarding the use of your child(ren)'s photo and/or video by Crosspoint Church:</p>
          <div class="text-gray-700 leading-relaxed text-sm space-y-4">
            <label class="flex gap-3 cursor-pointer group block">
              <input type="checkbox" name="photo_consent_yes" value="1" <?= $photo_consent_value === 'yes' ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 photo-consent-cb">
              <span class="text-gray-700 group-hover:text-gray-900"><strong>YES</strong> — I grant Crosspoint Church permission to use my child(ren)'s photo and/or video in promotional broadcasts, telecasts, or print media, free of charge.</span>
            </label>
            <label class="flex gap-3 cursor-pointer group block">
              <input type="checkbox" name="photo_consent_no" value="1" <?= $photo_consent_value === 'no' ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 photo-consent-cb">
              <span class="text-gray-700 group-hover:text-gray-900"><strong>NO</strong> — I do NOT grant permission for my child(ren)'s photo and/or video to be used in any Crosspoint Church media.</span>
            </label>
          </div>
        </div>
      </div>
      <div class="card mb-6">
        <label for="digital_signature" class="block text-sm font-medium text-gray-700 mb-2">Digital signature</label>
        <p class="text-sm text-gray-500 mb-2">Type your full legal name (as parent or guardian) to sign.</p>
        <input type="text" id="digital_signature" name="digital_signature" required maxlength="200" value="<?= htmlspecialchars($digital_signature_value) ?>" class="input-field max-w-md" placeholder="Full legal name" autocomplete="name">
      </div>
      <div class="flex flex-wrap gap-4 justify-between items-center">
        <button type="button" class="btn-secondary px-4 py-2 step-back" data-back="2">← Back</button>
        <button type="submit" class="btn-primary px-8 py-3 text-lg">Go to payment</button>
      </div>
      <div class="mt-8 pt-6 border-t border-gray-200 flex flex-col items-center gap-3">
        <p class="text-sm font-medium text-gray-500">Secured and powered by Stripe</p>
        <a href="https://stripe.com" target="_blank" rel="noopener noreferrer" class="inline-flex items-center text-gray-400 hover:text-gray-600" aria-label="Stripe">
          <img src="img/blurple.svg" alt="" width="120" height="40" class="h-10 w-auto object-contain">
        </a>
      </div>
    </div>
  </form>

  <?php endif; ?>

</div>

<style>
.registration-step.step-panel { display: none; }
.registration-step.step-panel.active { display: block; animation: stepIn 0.25s ease-out; }
.registration-step.step-panel.hidden { display: none !important; }
@keyframes stepIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>
<script>
(function() {
  var form = document.getElementById('registration-form');
  var panels = form.querySelectorAll('.registration-step');
  var initial = parseInt(form.getAttribute('data-initial-step') || '1', 10);

  function showStep(num, scrollIntoView) {
    var n = Math.max(1, Math.min(3, num));
    panels.forEach(function(p) {
      var id = p.id;
      var stepNum = id === 'step-1' ? 1 : id === 'step-2' ? 2 : 3;
      p.classList.remove('active');
      p.classList.add('hidden');
      if (stepNum === n) {
        p.classList.remove('hidden');
        p.classList.add('active');
        if (scrollIntoView) {
          window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        }
      }
    });
  }

  form.querySelectorAll('.step-next').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var next = parseInt(btn.getAttribute('data-next'), 10);
      var current = next - 1;
      var currentEl = document.getElementById('step-' + current);
      if (currentEl) {
        var firstInvalid = currentEl.querySelector('input:invalid, select:invalid');
        if (firstInvalid) {
          firstInvalid.focus();
          firstInvalid.reportValidity();
          return;
        }
      }
      showStep(next, true);
    });
  });

  form.querySelectorAll('.step-back').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var back = parseInt(btn.getAttribute('data-back'), 10);
      showStep(back, true);
    });
  });

  showStep(initial, false);

  // Add/remove kid (grade options from PHP — change $grade_options array at top to update)
  var gradeOptions = <?= json_encode($grade_options) ?>;
  var gradeOptionsHtml = gradeOptions.map(function(o) { return '<option value="' + o.replace(/"/g, '&quot;') + '">' + o.replace(/</g, '&lt;') + '</option>'; }).join('');
  var tShirtOptions = <?= json_encode($t_shirt_size_options) ?>;
  var tShirtOptionsHtml = tShirtOptions.map(function(o) { return '<option value="' + o.replace(/"/g, '&quot;') + '">' + o.replace(/</g, '&lt;') + '</option>'; }).join('');
  var container = document.getElementById('kids-container');
  var addBtn = document.getElementById('add-kid');
  var maxKids = <?= (int) $max_kids ?>;
  var index = <?= count($kids_for_form) ?>;

  if (addBtn) {
    addBtn.addEventListener('click', function() {
      if (container.querySelectorAll('.kid-block').length >= maxKids) return;
      var div = document.createElement('div');
      div.className = 'kid-block border border-gray-200 rounded-lg p-4 bg-gray-50/50';
      div.setAttribute('data-index', index);
      div.innerHTML = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
        '<div><label class="block text-sm font-medium text-gray-700 mb-1">First name *</label><input type="text" name="kids[' + index + '][first_name]" required maxlength="100" class="input-field"></div>' +
        '<div><label class="block text-sm font-medium text-gray-700 mb-1">Last name *</label><input type="text" name="kids[' + index + '][last_name]" required maxlength="100" class="input-field"></div></div>' +
        '<div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">' +
        '<div><label class="block text-sm font-medium text-gray-700 mb-1">Age</label><input type="number" name="kids[' + index + '][age]" min="1" max="18" class="input-field"></div>' +
        '<div><label class="block text-sm font-medium text-gray-700 mb-1">Gender</label><select name="kids[' + index + '][gender]" class="input-field"><option value="">Select</option><option value="Boy">Boy</option><option value="Girl">Girl</option></select></div>' +
        '<div><label class="block text-sm font-medium text-gray-700 mb-1">Date of birth</label><input type="date" name="kids[' + index + '][date_of_birth]" class="input-field"></div></div>' +
        '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Child Grade Entering in Fall 2026 (Note: Not the current grade)</label><select name="kids[' + index + '][last_grade_completed]" class="input-field w-full"><option value="">Select</option>' + gradeOptionsHtml + '</select></div>' +
        '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">T-Shirt size</label><select name="kids[' + index + '][t_shirt_size]" class="input-field w-full"><option value="">Select</option>' + tShirtOptionsHtml + '</select></div>' +
        '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Allergies / medical info</label><textarea name="kids[' + index + '][medical_allergy_info]" rows="2" maxlength="500" class="input-field w-full resize-y"></textarea></div>' +
        '<button type="button" class="remove-kid mt-4 text-sm text-red-600 hover:text-red-700" aria-label="Remove kid">Remove</button>';
      container.appendChild(div);
      index++;
      div.querySelector('.remove-kid').addEventListener('click', function() { removeKid(div); });
    });
  }

  function removeKid(block) {
    if (container.querySelectorAll('.kid-block').length <= 1) return;
    block.remove();
  }
  container.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-kid')) removeKid(e.target.closest('.kid-block'));
  });

  // Photo consent (Section 5): only one of Yes/No may be checked
  form.querySelectorAll('.photo-consent-cb').forEach(function(cb) {
    cb.addEventListener('change', function() {
      if (this.checked) {
        form.querySelectorAll('.photo-consent-cb').forEach(function(other) {
          if (other !== cb) other.checked = false;
        });
      }
    });
  });
})();
</script>
<?php layout_footer(); ?>
</body>
</html>
