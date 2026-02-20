<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db_helper.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/vendor/autoload.php';

$errors = [];
$payment_error = null;
$registration_open = get_setting($pdo, 'registration_open', '1');
app_log('high', 'Registration', 'Page loaded', [
  'method' => $_SERVER['REQUEST_METHOD'],
  'registration_open' => $registration_open,
  'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);
$max_kids = (int) get_setting($pdo, 'max_kids_per_registration', 10);
$event_title = get_setting($pdo, 'event_title', '');
$event_start_date = get_setting($pdo, 'event_start_date', '');
$event_end_date = get_setting($pdo, 'event_end_date', '');
$event_start_time = get_setting($pdo, 'event_start_time', '');
$event_end_time = get_setting($pdo, 'event_end_time', '');
$event_description = get_setting($pdo, 'event_description', '');

// Format event date/time range for display, e.g. "Monday, Jun 15 to Friday, Jun 19 from 9 am to 12:30 pm"
$event_date_range = '';
if ($event_start_date || $event_end_date) {
  $fmt_date = function (string $date): string {
    $ts = strtotime($date);
    return $ts ? date('l, M j', $ts) : $date;
  };
  $fmt_time = function (string $time): string {
    $ts = strtotime($time);
    return $ts ? ltrim(date('g:i a', $ts), '0') : $time;
  };
  // Remove ":00" for whole hours, e.g. "9:00 am" → "9 am"
  $clean_time = function (string $t): string {
    return preg_replace('/:00\s/', ' ', $t);
  };
  $parts = [];
  if ($event_start_date) {
    $parts[] = $fmt_date($event_start_date);
  }
  if ($event_end_date) {
    $parts[] = 'to ' . $fmt_date($event_end_date);
  }
  $date_str = implode(' ', $parts);
  $time_parts = [];
  if ($event_start_time) {
    $time_parts[] = $clean_time($fmt_time($event_start_time));
  }
  if ($event_end_time) {
    $time_parts[] = $clean_time($fmt_time($event_end_time));
  }
  $event_date_range = $date_str;
  if ($time_parts) {
    $event_date_range .= ' from ' . implode(' to ', $time_parts);
  }
}

// Last grade completed options (dropdown) — change this array to update the list
$grade_options = ['Preschool', 'Pre K', 'K', '1st', '2nd', '3rd', '4th', '5th'];

// T-shirt size options (dropdown) for each kid — change this array to update the list
$t_shirt_size_options = ['Youth XS', 'Youth S', 'Youth M', 'Youth L', 'Youth XL'];

// ─── Required fields ──────────────────────────────────────────────────────────
// Remove a key to make a field optional; add one to enforce it.
// Child fields use the prefix "kid_".
$required_fields = [
    // Step 1 – Parent / Guardian
    'parent_first_name',
    'parent_last_name',
    'email',
    'phone',
    'address',
    'hear_from_us',
    // Step 1 – Emergency contact
    'emergency_contact_name',
    'emergency_contact_phone',
    'emergency_contact_relationship',
    // Step 2 – Per child (prefix kid_)
    'kid_first_name',
    'kid_last_name',
    'kid_date_of_birth',
    'kid_gender',
    'kid_last_grade_completed',
    'kid_t_shirt_size',
    // Step 3 – Consent
    'digital_signature',
    'photo_consent',
];

/** Returns ' required' if $field is in $required_fields, else ''. Use inside HTML input/select. */
function req(string $field): string {
    global $required_fields;
    return in_array($field, $required_fields, true) ? ' required' : '';
}

/** Returns ' *' if $field is in $required_fields, else ''. Append to label text. */
function req_star(string $field): string {
    global $required_fields;
    return in_array($field, $required_fields, true) ? ' *' : '';
}

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
  'parent_first_name' => '',
  'parent_last_name' => '',
  'email' => '',
  'phone' => '',
  'address' => '',
  'home_church' => '',
  'alternative_pickup_name' => '',
  'alternative_pickup_phone' => '',
  'emergency_contact_name' => '',
  'emergency_contact_phone' => '',
  'emergency_contact_relationship' => '',
];
$kids_for_form = [['first_name' => '', 'last_name' => '', 'age' => '', 'gender' => '', 'date_of_birth' => '', 'last_grade_completed' => '', 't_shirt_size' => '', 'medical_allergy_info' => '']];
$initial_step = 1;
$digital_signature_value = '';
$photo_consent_value = '';
$receive_emails_value = 'yes'; // checked by default
$hear_from_us_value = '';
$hear_from_us_other_value = '';
$consent_checked = []; // keys: section_0, section_1, … — restored from session on cancel-return

// Restore form from session when user returns after cancelling payment
$existing_draft_id = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['cancelled']) && !empty($_SESSION['vbs_registration_data'])) {
  $saved = $_SESSION['vbs_registration_data'];
  $existing_draft_id = isset($saved['registration_id']) ? (int) $saved['registration_id'] : null;
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
  if (empty($kids_for_form))
    $kids_for_form = [['first_name' => '', 'last_name' => '', 'age' => '', 'gender' => '', 'date_of_birth' => '', 'last_grade_completed' => '', 't_shirt_size' => '', 'medical_allergy_info' => '']];
  $digital_signature_value = ''; // intentionally cleared — user must re-sign
  $photo_consent_value = $saved['photo_consent'] ?? '';
  $receive_emails_value = $saved['receive_emails'] ?? 'yes';
  $hear_from_us_value = $saved['hear_from_us'] ?? '';
  $hear_from_us_other_value = $saved['hear_from_us_other'] ?? '';
  $consent_checked = $saved['consent_items'] ?? [];
  $initial_step = 4;
}

// Single submission: all data in one POST with action=payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
  app_log('high', 'Registration', 'Form submission received', [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'email' => trim($_POST['email'] ?? ''),
  ]);
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
  if (in_array('parent_first_name', $required_fields, true) && $parent_first === '')
    $errors[] = 'Parent first name is required.';
  if (in_array('parent_last_name', $required_fields, true) && $parent_last === '')
    $errors[] = 'Parent last name is required.';
  if ($email === '')
    $errors[] = 'Email is required.';
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
    $errors[] = 'Please enter a valid email.';
  if (in_array('phone', $required_fields, true) && $phone === '')
    $errors[] = 'Phone number is required.';
  if (in_array('address', $required_fields, true) && $address === '')
    $errors[] = 'Address is required.';
  if ($alternative_pickup_name !== null && $alternative_pickup_name !== '' && empty($alternative_pickup_phone))
    $errors[] = 'Alternative pick-up phone is required when an alternative pick-up name is provided.';
  if (in_array('emergency_contact_name', $required_fields, true) && trim($_POST['emergency_contact_name'] ?? '') === '')
    $errors[] = 'Emergency contact name is required.';
  if (in_array('emergency_contact_phone', $required_fields, true) && trim($_POST['emergency_contact_phone'] ?? '') === '')
    $errors[] = 'Emergency contact phone is required.';
  if (in_array('emergency_contact_relationship', $required_fields, true) && trim($_POST['emergency_contact_relationship'] ?? '') === '')
    $errors[] = 'Emergency contact relationship is required.';

  // Build kid_rows and validate kids
  $kid_rows = [];
  foreach ($kids as $i => $k) {
    $first = trim($k['first_name'] ?? '');
    $last = trim($k['last_name'] ?? '');
    $age = isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : null;
    $gender = trim($k['gender'] ?? '');
    if (!in_array($gender, ['Boy', 'Girl'], true))
      $gender = null;
    $dob = trim($k['date_of_birth'] ?? '');
    $dob = $dob !== '' ? $dob : null;
    $last_grade = trim($k['last_grade_completed'] ?? '') ?: null;
    $t_shirt = trim($k['t_shirt_size'] ?? '') ?: null;
    $medical = trim($k['medical_allergy_info'] ?? '');
    if ($first !== '' || $last !== '' || $age !== null || $gender !== null || $medical !== '') {
      $kid_rows[] = [
        'first_name' => $first,
        'last_name' => $last,
        'age' => $age ?: null,
        'gender' => $gender ?: null,
        'date_of_birth' => $dob,
        'last_grade_completed' => $last_grade,
        't_shirt_size' => $t_shirt,
        'medical_allergy_info' => $medical
      ];
    }
  }
  if (count($kid_rows) === 0)
    $errors[] = 'Please add at least one child.';
  foreach ($kid_rows as $i => $k) {
    $n = $i + 1;
    if (in_array('kid_first_name', $required_fields, true) && $k['first_name'] === '')
      $errors[] = "Kid $n: first name is required.";
    if (in_array('kid_last_name', $required_fields, true) && $k['last_name'] === '')
      $errors[] = "Kid $n: last name is required.";
    if (in_array('kid_date_of_birth', $required_fields, true) && empty($k['date_of_birth']))
      $errors[] = "Kid $n: date of birth is required.";
    if (in_array('kid_gender', $required_fields, true) && empty($k['gender']))
      $errors[] = "Kid $n: gender is required.";
    if (in_array('kid_last_grade_completed', $required_fields, true) && empty($k['last_grade_completed']))
      $errors[] = "Kid $n: grade entering in Fall 2026 is required.";
    if (in_array('kid_t_shirt_size', $required_fields, true) && empty($k['t_shirt_size']))
      $errors[] = "Kid $n: T-shirt size is required.";
  }
  if (count($kid_rows) > $max_kids)
    $errors[] = "Maximum $max_kids children per registration.";
  if (in_array('digital_signature', $required_fields, true) && $digital_signature === '')
    $errors[] = 'Digital signature (your full name) is required.';
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

  // Section 6: Future Communications
  $receive_emails = !empty($_POST['receive_emails']) ? 'yes' : 'no';

  // Section 7: How did you hear from us
  $hear_from_us_select = trim($_POST['hear_from_us'] ?? '');
  $hear_from_us_other_txt = trim($_POST['hear_from_us_other'] ?? '');
  $hear_from_us_db = ($hear_from_us_select === 'Other' && $hear_from_us_other_txt !== '')
    ? 'Other: ' . $hear_from_us_other_txt
    : $hear_from_us_select;
  if (in_array('hear_from_us', $required_fields, true) && $hear_from_us_select === '')
    $errors[] = 'Please tell us how you heard about us.';
  elseif (in_array('hear_from_us', $required_fields, true) && $hear_from_us_select === 'Other' && $hear_from_us_other_txt === '')
    $errors[] = 'Please specify how you heard about us.';

  if (empty($errors)) {
    $total_dollars = compute_total_dollars($pdo, count($kid_rows));
    if ($total_dollars < 0.50)
      $errors[] = 'Minimum charge is $0.50. Please check admin pricing settings.';
  }

  if (!empty($errors)) {
    app_log('high', 'Registration', 'Validation failed', [
      'email' => $email,
      'errors' => $errors,
    ]);
    $form = [
      'parent_first_name' => $parent_first,
      'parent_last_name' => $parent_last,
      'email' => $email,
      'phone' => $phone,
      'address' => $address,
      'home_church' => $home_church,
      'alternative_pickup_name' => $alternative_pickup_name ?: '',
      'alternative_pickup_phone' => $alternative_pickup_phone ?: '',
      'emergency_contact_name' => $emergency_contact_name ?: '',
      'emergency_contact_phone' => $emergency_contact_phone ?: '',
      'emergency_contact_relationship' => $emergency_contact_relationship ?: '',
    ];
    $kids_for_form = [];
    foreach ($kids as $k) {
      $kids_for_form[] = [
        'first_name' => trim($k['first_name'] ?? ''),
        'last_name' => trim($k['last_name'] ?? ''),
        'age' => isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : '',
        'gender' => trim($k['gender'] ?? ''),
        'date_of_birth' => trim($k['date_of_birth'] ?? ''),
        'last_grade_completed' => trim($k['last_grade_completed'] ?? ''),
        't_shirt_size' => trim($k['t_shirt_size'] ?? ''),
        'medical_allergy_info' => trim($k['medical_allergy_info'] ?? ''),
      ];
    }
    if (empty($kids_for_form))
      $kids_for_form = [['first_name' => '', 'last_name' => '', 'age' => '', 'gender' => '', 'date_of_birth' => '', 'last_grade_completed' => '', 't_shirt_size' => '', 'medical_allergy_info' => '']];
    $digital_signature_value = $digital_signature;
    $photo_consent_value = $photo_consent;
    $receive_emails_value = $receive_emails;
    $hear_from_us_value = $hear_from_us_select;
    $hear_from_us_other_value = $hear_from_us_other_txt;
    if (!empty($errors)) {
      if (preg_match('/Parent|Email|email/i', implode(' ', $errors)))
        $initial_step = 1;
      elseif (preg_match('/Kid|child|Maximum|Minimum/i', implode(' ', $errors)))
        $initial_step = 2;
      else
        $initial_step = 4;
    }
  } else {
    try {
      if (!STRIPE_SECRET_KEY)
        throw new Exception('Stripe is not configured.');
      app_log('high', 'Registration', 'Validation passed, proceeding to save draft', [
        'email' => $email,
        'kid_count' => count($kid_rows),
      ]);
      $photo_consent_val = ($photo_consent === 'yes' || $photo_consent === 'no') ? $photo_consent : null;
      $now = date('Y-m-d H:i:s'); // Los Angeles (set in config.php)
      $wanted = [
        'parent_first_name' => $parent_first,
        'parent_last_name' => $parent_last,
        'email' => $email,
        'phone' => $phone,
        'address' => $address ?: null,
        'home_church' => $home_church ?: null,
        'alternative_pickup_name' => $alternative_pickup_name,
        'alternative_pickup_phone' => $alternative_pickup_phone,
        'emergency_contact_name' => $emergency_contact_name,
        'emergency_contact_phone' => $emergency_contact_phone,
        'emergency_contact_relationship' => $emergency_contact_relationship,
        'consent_accepted' => 1,
        'digital_signature' => $digital_signature ?: null,
        'consent_agreed_at' => $now,
        'photo_consent' => $photo_consent_val,
        'receive_emails' => $receive_emails,
        'hear_from_us' => $hear_from_us_db ?: null,
        'status' => 'draft',
        'total_amount_cents' => (int) round($total_dollars * 100),
        'created_at' => $now,
        'updated_at' => $now,
      ];
      $registration_id = reg_save_draft($pdo, $existing_draft_id, $wanted, $kid_rows);
      app_log('high', 'Stripe', 'Initiating Stripe checkout session', [
        'registration_id' => $registration_id,
        'email' => $email,
        'kid_count' => count($kid_rows),
        'total_cents' => (int) round($total_dollars * 100),
      ]);
      \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
      $session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'customer_email' => $email,
        'line_items' => [
          [
            'price_data' => [
              'currency' => get_setting($pdo, 'currency', 'usd'),
              'product_data' => ['name' => 'VBS Registration - ' . count($kid_rows) . ' kid(s)'],
              'unit_amount' => (int) round($total_dollars * 100 / count($kid_rows)),
            ],
            'quantity' => count($kid_rows),
          ]
        ],
        'mode' => 'payment',
        'success_url' => APP_URL . '/success?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => APP_URL . '/register?cancelled=1',
        'metadata' => ['registration_id' => (string) $registration_id],
      ]);
      if (empty($session->url))
        throw new Exception('Stripe did not return a checkout URL.');
      app_log('high', 'Stripe', 'Checkout session created, redirecting', [
        'registration_id' => $registration_id,
        'stripe_session' => $session->id,
      ]);
      // Save form data to session so if user cancels payment they get it back
      // Also save registration_id so we can update the draft instead of creating duplicates
      $_SESSION['vbs_registration_data'] = [
        'registration_id' => $registration_id,
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
        'receive_emails' => $receive_emails,
        'hear_from_us' => $hear_from_us_select,
        'hear_from_us_other' => $hear_from_us_other_txt,
        'consent_items' => array_map(fn($v) => (string)$v, (array)($_POST['consent_items'] ?? [])),
      ];
      header('Location: ' . $session->url, true, 303);
      exit;
    } catch (Exception $e) {
      $payment_error = $e->getMessage();
      app_log('high', 'Error', 'Payment flow exception', [
        'error' => $e->getMessage(),
        'email' => $email ?? null,
        'registration_id' => $registration_id ?? null,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
      ]);
      $form = [
        'parent_first_name' => $parent_first,
        'parent_last_name' => $parent_last,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'home_church' => $home_church,
        'alternative_pickup_name' => $alternative_pickup_name ?: '',
        'alternative_pickup_phone' => $alternative_pickup_phone ?: '',
        'emergency_contact_name' => $emergency_contact_name ?: '',
        'emergency_contact_phone' => $emergency_contact_phone ?: '',
        'emergency_contact_relationship' => $emergency_contact_relationship ?: '',
      ];
      $kids_for_form = [];
      foreach ($kids as $k) {
        $kids_for_form[] = [
          'first_name' => trim($k['first_name'] ?? ''),
          'last_name' => trim($k['last_name'] ?? ''),
          'age' => isset($k['age']) && $k['age'] !== '' ? (int) $k['age'] : '',
          'gender' => trim($k['gender'] ?? ''),
          'date_of_birth' => trim($k['date_of_birth'] ?? ''),
          'last_grade_completed' => trim($k['last_grade_completed'] ?? ''),
          't_shirt_size' => trim($k['t_shirt_size'] ?? ''),
          'medical_allergy_info' => trim($k['medical_allergy_info'] ?? ''),
        ];
      }
      if (empty($kids_for_form))
        $kids_for_form = [['first_name' => '', 'last_name' => '', 'age' => '', 'gender' => '', 'date_of_birth' => '', 'last_grade_completed' => '', 't_shirt_size' => '', 'medical_allergy_info' => '']];
      $digital_signature_value = $digital_signature;
      $photo_consent_value = $photo_consent;
      $receive_emails_value = $receive_emails;
      $hear_from_us_value = $hear_from_us_select;
      $hear_from_us_other_value = $hear_from_us_other_txt;
      $initial_step = 4;
    }
  }
}

$kid_names_str = implode(', ', array_map(function ($k) {
  return trim(($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? ''));
}, $kids_for_form));

layout_head('VBS Registration');
$card_img = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/') . '/img/email_hero.webp';
?>
<!-- Full-width event header: spans page, inline styles for reliable padding -->
<section style="display:grid; grid-template-columns:1fr 1fr; background:#fff; border-bottom:1px solid #e5e7eb;">

  <!-- Left: photo -->
  <div style="overflow:hidden;">
    <img src="<?= htmlspecialchars($card_img) ?>" alt="VBS event photo"
         style="width:100%; height:100%; object-fit:cover; object-position:center; display:block;">
  </div>

  <!-- Right: event details + pricing + CTA -->
  <div style="padding:2rem; display:flex; flex-direction:column; justify-content:space-between; gap:1rem;">
    <div style="display:flex; flex-direction:column; gap:0.75rem;">

      <h1 style="font-size:1.25rem; font-weight:700; color:#111827; line-height:1.3; margin:0;">
        <?= htmlspecialchars($event_title) ?> Registration
      </h1>

      <p style="display:flex; align-items:flex-start; gap:0.5rem; font-size:0.875rem; color:#4b5563; margin:0;">
        <svg style="width:1rem; height:1rem; flex-shrink:0; margin-top:2px;" fill="none" stroke="#6366f1" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <a href="https://www.google.com/maps/search/?api=1&amp;query=658+Gibraltar+Court,+Milpitas,+CA+95035"
           target="_blank" rel="noopener noreferrer"
           style="color:#4f46e5; text-decoration:none;"
           onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
          658 Gibraltar Court, Milpitas, CA 95035
        </a>
      </p>

      <?php if ($event_date_range): ?>
      <p style="display:flex; align-items:flex-start; gap:0.5rem; font-size:0.875rem; color:#4b5563; margin:0;">
        <svg style="width:1rem; height:1rem; flex-shrink:0; margin-top:2px;" fill="none" stroke="#6366f1" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <?= htmlspecialchars($event_date_range) ?>
      </p>
      <?php endif; ?>

      <?php if ($show_early_bird): ?>
      <div style="border-radius:0.5rem; background:#fffbeb; border:1px solid #fcd34d; padding:0.625rem 0.875rem;">
        <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#92400e; margin:0 0 0.25rem;">Early bird discount</p>
        <p style="font-size:1rem; font-weight:700; color:#78350f; margin:0;">
          <?= format_money($early_bird_price_cents / 100.0) ?>
          <span style="font-size:0.875rem; font-weight:600;">per child</span>
        </p>
        <p style="font-size:0.75rem; color:#92400e; margin:0.125rem 0 0;">Ends <?= htmlspecialchars($early_bird_end_display) ?></p>
      </div>
      <?php endif; ?>

      <div>
        <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; margin:0 0 0.2rem;">
          <?= $show_early_bird ? 'Regular price' : 'Registration price' ?>
        </p>
        <p style="font-size:1rem; font-weight:700; color:#111827; margin:0;">
          <?= format_money($regular_price_cents / 100.0) ?>
          <span style="font-size:0.875rem; font-weight:600; color:#4b5563;">per child</span>
        </p>
      </div>
    </div>

    <a href="#form-top" class="btn-emerald" style="display:block; text-align:center; font-size:0.875rem;">Register Now &#8594;</a>
  </div>
</section>
<div id="form-top"></div>

<div class="max-w-2xl mx-auto px-4 py-10">

  <?php if (!empty($errors)): ?>
    <div class="card border-red-200 bg-red-50 mb-6">
      <ul class="list-disc list-inside text-red-700 text-sm">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if ($payment_error): ?>
    <div class="card border-red-200 bg-red-50 mb-6 text-red-700 text-sm">We couldn’t start payment.
      <?= htmlspecialchars($payment_error) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['cancelled'])): ?>
    <div id="cancel-banner" class="card border-amber-200 bg-amber-50 mb-6 text-amber-800">Payment was cancelled. You can complete the form again when ready.</div>
  <?php endif; ?>

  <?php if ($registration_open !== '1'): ?>
    <div class="card border-gray-200 bg-gray-100 text-gray-600 text-center">Registration is currently closed.</div>
  <?php else: ?>

    <form method="post" action="" id="registration-form" data-initial-step="<?= (int) $initial_step ?>">
      <input type="hidden" name="action" value="payment">

     

      <!-- Progress stepper -->
      <nav id="reg-stepper" aria-label="Registration progress"
           class="flex items-center justify-center py-2 mb-6 select-none">
        <div class="stepper-step" data-step="1">
          <div class="stepper-icon">1</div>
          <span class="stepper-label">Parent Info</span>
        </div>
        <div class="stepper-connector" data-after="1"></div>
        <div class="stepper-step" data-step="2">
          <div class="stepper-icon">2</div>
          <span class="stepper-label">Children</span>
        </div>
        <div class="stepper-connector" data-after="2"></div>
        <div class="stepper-step" data-step="3">
          <div class="stepper-icon">3</div>
          <span class="stepper-label">Review</span>
        </div>
        <div class="stepper-connector" data-after="3"></div>
        <div class="stepper-step" data-step="4">
          <div class="stepper-icon">4</div>
          <span class="stepper-label">Consent &amp; Pay</span>
        </div>
      </nav>

      <!-- Step 1: Parent + Emergency -->
      <div id="step-1" class="registration-step step-panel" aria-label="Step 1 – Parent &amp; emergency contact">
        <div class="flex items-center gap-2 mb-6">
          <span
            class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium text-white" style="background:#0284c7">1</span>
          <h2 class="text-xl font-semibold text-gray-900">Parent / Guardian &amp; Emergency Contact</h2>
        </div>
        <div class="card mb-4">
          <h3 class="text-lg font-semibold text-gray-900 mb-4">Parent / Guardian</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="parent_first_name" class="block text-sm font-medium text-gray-700 mb-1">First name<?= req_star('parent_first_name') ?></label>
              <input type="text" id="parent_first_name" name="parent_first_name"<?= req('parent_first_name') ?> maxlength="100"
                value="<?= htmlspecialchars($form['parent_first_name']) ?>" class="input-field">
            </div>
            <div>
              <label for="parent_last_name" class="block text-sm font-medium text-gray-700 mb-1">Last name<?= req_star('parent_last_name') ?></label>
              <input type="text" id="parent_last_name" name="parent_last_name"<?= req('parent_last_name') ?> maxlength="100"
                value="<?= htmlspecialchars($form['parent_last_name']) ?>" class="input-field">
            </div>
          </div>
          <div class="mt-4">
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email<?= req_star('email') ?></label>
            <input type="email" id="email" name="email"<?= req('email') ?> value="<?= htmlspecialchars($form['email']) ?>"
              class="input-field">
          </div>
          <div class="mt-4">
            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone<?= req_star('phone') ?></label>
            <input type="tel" id="phone" name="phone"<?= req('phone') ?> value="<?= htmlspecialchars($form['phone']) ?>" class="input-field">
          </div>
          <div class="mt-4">
            <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address<?= req_star('address') ?></label>
            <input type="text" id="address" name="address"<?= req('address') ?> value="<?= htmlspecialchars($form['address']) ?>"
              class="input-field">
          </div>
          <div class="mt-4">
            <label for="home_church" class="block text-sm font-medium text-gray-700 mb-1">Which church do you attend? (leave blank if none)<span
                class="text-gray-500 font-normal"></span></label>
            <input type="text" id="home_church" name="home_church" value="<?= htmlspecialchars($form['home_church']) ?>"
              class="input-field" maxlength="255">
          </div>
          <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="alternative_pickup_name" class="block text-sm font-medium text-gray-700 mb-1">Alternative pick-up name</label>
              <input type="text" id="alternative_pickup_name" name="alternative_pickup_name"
                value="<?= htmlspecialchars($form['alternative_pickup_name']) ?>" class="input-field" maxlength="100">
            </div>
            <div>
              <label for="alternative_pickup_phone" id="alternative_pickup_phone_label" class="block text-sm font-medium text-gray-700 mb-1">Alternative pick-up phone</label>
              <input type="tel" id="alternative_pickup_phone" name="alternative_pickup_phone"
                value="<?= htmlspecialchars($form['alternative_pickup_phone']) ?>" class="input-field" maxlength="50">
            </div>
          </div>
          <div class="mt-4 pt-4 border-t border-gray-100">
            <label for="hear_from_us_select" class="block text-sm font-medium text-gray-700 mb-1">How did you hear about
              us?<?= req_star('hear_from_us') ?></label>
            <select name="hear_from_us" id="hear_from_us_select" class="input-field max-w-sm text-sm"<?= req('hear_from_us') ?>
              onchange="(function(sel){var wrap=document.getElementById('hear_from_us_other_wrap'),inp=document.getElementById('hear_from_us_other'),isOther=sel.value==='Other';wrap.classList.toggle('hidden',!isOther);inp.required=isOther;if(!isOther)inp.setCustomValidity('');})(this)">
              <option value="">— Select an option —</option>
              <?php foreach (['Previous VBS', 'Google search', 'Facebook/Instagram/Social Media', 'Friend or family referral','Flyers', 'Other'] as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= $hear_from_us_value === $opt ? 'selected' : '' ?>>
                  <?= htmlspecialchars($opt) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div id="hear_from_us_other_wrap" class="mt-2 <?= $hear_from_us_value === 'Other' ? '' : 'hidden' ?>">
              <input type="text" id="hear_from_us_other" name="hear_from_us_other"
                value="<?= htmlspecialchars($hear_from_us_other_value) ?>" class="input-field max-w-sm text-sm"
                placeholder="Please specify…"
                <?= $hear_from_us_value === 'Other' ? 'required' : '' ?>>
            </div>
          </div>
        </div>
        <div class="card mb-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-4">Emergency contact</h3>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-1">Name<?= req_star('emergency_contact_name') ?></label>
              <input type="text" id="emergency_contact_name" name="emergency_contact_name" maxlength="100"<?= req('emergency_contact_name') ?>
                value="<?= htmlspecialchars($form['emergency_contact_name']) ?>" class="input-field">
            </div>
            <div>
              <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Phone<?= req_star('emergency_contact_phone') ?></label>
              <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="50"<?= req('emergency_contact_phone') ?>
                value="<?= htmlspecialchars($form['emergency_contact_phone']) ?>" class="input-field">
            </div>
            <div>
              <label for="emergency_contact_relationship"
                class="block text-sm font-medium text-gray-700 mb-1">Relationship<?= req_star('emergency_contact_relationship') ?></label>
              <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" maxlength="50"<?= req('emergency_contact_relationship') ?>
                value="<?= htmlspecialchars($form['emergency_contact_relationship']) ?>" class="input-field">
            </div>
          </div>
        </div>
        <div class="flex justify-end">
          <button type="button" class="btn-emerald px-6 py-3 step-next" data-next="2">Next: Add Child(ren)</button>
        </div>
      </div>

      <!-- Step 2: Kids -->
      <div id="step-2" class="registration-step step-panel hidden" aria-label="Step 2 – Add Children">
        <div class="flex items-center gap-2 mb-6">
          <span
            class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium text-white" style="background:#0284c7">2</span>
          <h2 class="text-xl font-semibold text-gray-900">Add Children</h2>
          <button type="button" class="ml-auto btn-secondary text-xs px-3 py-1 step-back" data-back="1">← Back</button>
        </div>
        <div class="card mb-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-4">Child you are registering</h3>
          <div id="kids-container" class="space-y-4">
            <?php foreach ($kids_for_form as $idx => $kid): ?>
              <div class="kid-block border border-gray-200 rounded-lg p-4" data-index="<?= $idx ?>">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label for="kids-<?= $idx ?>-first_name" class="block text-sm font-medium text-gray-700 mb-1">First name<?= req_star('kid_first_name') ?></label>
                    <input type="text" id="kids-<?= $idx ?>-first_name" name="kids[<?= $idx ?>][first_name]"<?= req('kid_first_name') ?>
                      maxlength="100" value="<?= htmlspecialchars($kid['first_name']) ?>" class="input-field">
                  </div>
                  <div>
                    <label for="kids-<?= $idx ?>-last_name" class="block text-sm font-medium text-gray-700 mb-1">Last name<?= req_star('kid_last_name') ?></label>
                    <input type="text" id="kids-<?= $idx ?>-last_name" name="kids[<?= $idx ?>][last_name]"<?= req('kid_last_name') ?>
                      maxlength="100" value="<?= htmlspecialchars($kid['last_name']) ?>" class="input-field">
                  </div>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                  <div>
                    <label for="kids-<?= $idx ?>-date_of_birth" class="block text-sm font-medium text-gray-700 mb-1">Date of birth<?= req_star('kid_date_of_birth') ?></label>
                    <input type="date" id="kids-<?= $idx ?>-date_of_birth" name="kids[<?= $idx ?>][date_of_birth]"
                      <?= req('kid_date_of_birth') ?> value="<?= htmlspecialchars($kid['date_of_birth']) ?>" class="input-field dob-input">
                  </div>
                  <div>
                    <label for="kids-<?= $idx ?>-age" class="block text-sm font-medium text-gray-700 mb-1">Age <span class="text-gray-400 font-normal text-xs">(age as of VBS first day)</span></label>
                    <input type="number" id="kids-<?= $idx ?>-age" name="kids[<?= $idx ?>][age]" min="1" max="18"
                      value="<?= htmlspecialchars($kid['age'] !== '' ? $kid['age'] : '') ?>" class="input-field age-input">
                  </div>
                  <div>
                    <label for="kids-<?= $idx ?>-gender" class="block text-sm font-medium text-gray-700 mb-1">Gender<?= req_star('kid_gender') ?></label>
                    <select id="kids-<?= $idx ?>-gender" name="kids[<?= $idx ?>][gender]"<?= req('kid_gender') ?> class="input-field">
                      <option value="">Select</option>
                      <option value="Boy" <?= ($kid['gender'] ?? '') === 'Boy' ? 'selected' : '' ?>>Boy</option>
                      <option value="Girl" <?= ($kid['gender'] ?? '') === 'Girl' ? 'selected' : '' ?>>Girl</option>
                    </select>
                  </div>
                </div>
                <div class="mt-4">
                  <label for="kids-<?= $idx ?>-last_grade_completed"
                    class="block text-sm font-medium text-gray-700 mb-1">Child Grade Entering in Fall 2026 (Note: Not the
                    current grade)<?= req_star('kid_last_grade_completed') ?></label>
                  <select id="kids-<?= $idx ?>-last_grade_completed" name="kids[<?= $idx ?>][last_grade_completed]"
                    <?= req('kid_last_grade_completed') ?> class="input-field w-full">
                    <option value="">Select</option>
                    <?php foreach ($grade_options as $opt): ?>
                      <option value="<?= htmlspecialchars($opt) ?>" <?= ($kid['last_grade_completed'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mt-4">
                  <label for="kids-<?= $idx ?>-t_shirt_size" class="block text-sm font-medium text-gray-700 mb-1">T-Shirt
                    size<?= req_star('kid_t_shirt_size') ?></label>
                  <select id="kids-<?= $idx ?>-t_shirt_size" name="kids[<?= $idx ?>][t_shirt_size]"
                    <?= req('kid_t_shirt_size') ?> class="input-field w-full">
                    <option value="">Select</option>
                    <?php foreach ($t_shirt_size_options as $opt): ?>
                      <option value="<?= htmlspecialchars($opt) ?>" <?= ($kid['t_shirt_size'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mt-4">
                  <label for="kids-<?= $idx ?>-medical" class="block text-sm font-medium text-gray-700 mb-1">Allergies /
                    medical info</label>
                  <textarea id="kids-<?= $idx ?>-medical" name="kids[<?= $idx ?>][medical_allergy_info]" rows="2"
                    maxlength="500"
                    class="input-field w-full resize-y"><?= htmlspecialchars($kid['medical_allergy_info']) ?></textarea>
                </div>
                <button type="button" class="remove-kid mt-4 text-sm text-red-600 hover:text-red-700"
                  aria-label="Remove kid">Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="flex justify-end mt-4">
            <button type="button" id="add-kid" class="btn-orange text-sm" disabled>+ Add Child</button>
          </div>
        </div>
        <div class="flex flex-wrap gap-4 justify-between items-center">
          <button type="button" class="btn-secondary px-4 py-2 step-back" data-back="1">← Back</button>
          <button type="button" class="btn-emerald px-6 py-3 step-next" data-next="3">Next: Review</button>
        </div>
      </div>

      <!-- Step 3: Review -->
      <div id="step-3" class="registration-step step-panel hidden" aria-label="Step 3 – Review your information">
        <div class="flex items-center gap-2 mb-6">
          <span class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium text-white" style="background:#0284c7">3</span>
          <h2 class="text-xl font-semibold text-gray-900">Review Your Information</h2>
          <button type="button" class="ml-auto btn-secondary text-xs px-3 py-1 step-back" data-back="2">← Back</button>
        </div>
        <p class="text-sm text-gray-500 mb-6">Please confirm everything looks correct before signing. Click <strong>Edit</strong> on any section to go back and make changes.</p>

        <!-- Parent & Emergency Contact -->
        <div class="card mb-4">
          <div class="flex justify-between items-center mb-3">
            <h3 class="text-base font-semibold text-gray-900">Parent / Guardian &amp; Emergency Contact</h3>
            <button type="button" class="text-sm text-sky-600 hover:underline font-medium rv-edit-btn" data-goto="1">Edit</button>
          </div>
          <dl id="rv-parent" class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm text-gray-700">
            <!-- populated by populateReview() -->
          </dl>
        </div>

        <!-- Children -->
        <div class="card mb-4">
          <div class="flex justify-between items-center mb-3">
            <h3 class="text-base font-semibold text-gray-900">Child(ren)</h3>
            <button type="button" class="text-sm text-sky-600 hover:underline font-medium rv-edit-btn" data-goto="2">Edit</button>
          </div>
          <div id="rv-kids" class="space-y-3 text-sm text-gray-700">
            <!-- populated by populateReview() -->
          </div>
        </div>

        <div class="flex flex-wrap gap-4 justify-between items-center mt-6">
          <button type="button" class="btn-secondary px-4 py-2 step-back" data-back="2">← Back</button>
          <button type="button" class="btn-emerald px-6 py-3 step-next" data-next="4">Looks good — proceed to consent</button>
        </div>
      </div>

      <!-- Step 4: Consent + Signature -->
      <div id="step-4" class="registration-step step-panel hidden" aria-label="Step 4 – Consent &amp; payment">
        <div class="flex items-center gap-2 mb-6">
          <span
            class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium text-white" style="background:#0284c7">4</span>
          <h2 class="text-xl font-semibold text-gray-900">Consent &amp; Payment</h2>
          <button type="button" class="ml-auto btn-secondary text-xs px-3 py-1 step-back" data-back="3">← Back</button>
        </div>
        <div class="space-y-4 mb-6">
          <?php foreach ($consent_paragraphs as $i => $block): ?>
            <?php
            $text = $block;
            if ($i === 0)
              $text = str_replace('XXXX', $kid_names_str, $text);
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
                <div class="text-gray-700 whitespace-pre-wrap leading-relaxed text-sm"><?= nl2br(htmlspecialchars($body)) ?>
                </div>
              <?php endif; ?>
              <div class="mt-4 pt-4 border-t border-gray-200">
                <label class="flex gap-3 cursor-pointer group" for="<?= htmlspecialchars($cb_id) ?>">
                  <input type="checkbox" id="<?= htmlspecialchars($cb_id) ?>"
                    name="consent_items[<?= htmlspecialchars($slug) ?>]" value="1" required
                    <?= !empty($consent_checked[$slug]) ? 'checked' : '' ?>
                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                  <span class="text-sm text-gray-700 group-hover:text-gray-900">I have read and agree to the terms
                    above.</span>
                </label>
              </div>
            </div>
          <?php endforeach; ?>

          <!-- Section 5 - Photo & Video Release (hardcoded, not from database) -->
          <div class="card">
            <h3 class="text-base font-semibold text-gray-900 mb-2">SECTION 5 – PHOTO &amp; VIDEO RELEASE</h3>
            <p class="text-gray-700 leading-relaxed text-sm mb-4">Please select one of the following options regarding the
              use of your child(ren)'s photo and/or video by Crosspoint Church:</p>
            <div class="text-gray-700 leading-relaxed text-sm space-y-4">
              <label class="flex gap-3 cursor-pointer group block">
                <input type="checkbox" name="photo_consent_yes" value="1" <?= $photo_consent_value === 'yes' ? 'checked' : '' ?>
                  class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 photo-consent-cb">
                <span class="text-gray-700 group-hover:text-gray-900"><strong>YES</strong> — I grant Crosspoint Church
                  permission to use my child(ren)'s photo and/or video in promotional broadcasts, telecasts, or print
                  media, free of charge.</span>
              </label>
              <label class="flex gap-3 cursor-pointer group block">
                <input type="checkbox" name="photo_consent_no" value="1" <?= $photo_consent_value === 'no' ? 'checked' : '' ?>
                  class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 photo-consent-cb">
                <span class="text-gray-700 group-hover:text-gray-900"><strong>NO</strong> — I do NOT grant permission for
                  my child(ren)'s photo and/or video to be used in any Crosspoint Church media.</span>
              </label>
            </div>
          </div>

          <!-- Section 6 – Future Communications -->
          <div class="card">
            <h3 class="text-base font-semibold text-gray-900 mb-2">SECTION 6 – FUTURE COMMUNICATIONS</h3>
            <p class="text-gray-700 text-sm mb-4">Stay in the loop with upcoming events and activities at Crosspoint
              Church.</p>
            <label class="flex gap-3 cursor-pointer group">
              <input type="checkbox" name="receive_emails" value="1" <?= $receive_emails_value !== 'no' ? 'checked' : '' ?>
                class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
              <span class="text-sm text-gray-700 group-hover:text-gray-900">
                I would like to receive email notifications for future Crosspoint Church events.
              </span>
            </label>
          </div>

        </div>
        <div class="card mb-6">
          <label for="digital_signature" class="block text-sm font-medium text-gray-700 mb-2">Digital signature<?= req_star('digital_signature') ?></label>
          <p class="text-sm text-gray-500 mb-2">By typing my name below, I hereby acknowledge that I have read all consents and this Privacy Notice, and that all information I have submitted is correct.</p>
          <input type="text" id="digital_signature" name="digital_signature"<?= req('digital_signature') ?> maxlength="200"
            value="<?= htmlspecialchars($digital_signature_value) ?>" class="input-field max-w-md"
            placeholder="Full legal name" autocomplete="name">
        </div>
        <div class="flex flex-wrap gap-4 justify-between items-center">
          <button type="button" class="btn-secondary px-4 py-2 step-back" data-back="3">← Back</button>
          <button type="submit" class="btn-emerald px-8 py-3 text-lg">Go to payment</button>
        </div>
        <div class="mt-8 pt-6 border-t border-gray-200 flex flex-col items-center gap-3">
          <p class="text-sm font-medium text-gray-500">Payment is secured and powered by Stripe</p>
          <a href="https://stripe.com" target="_blank" rel="noopener noreferrer"
            class="inline-flex items-center text-gray-400 hover:text-gray-600" aria-label="Stripe">
            <img src="img/blurple.svg" alt="" width="120" height="40" class="h-10 w-auto object-contain">
          </a>
        </div>
      </div>
    </form>

  <?php endif; ?>

</div>

<style>
  .registration-step.step-panel {
    display: none;
  }

  .registration-step.step-panel.active {
    display: block;
    animation: stepIn 0.25s ease-out;
  }

  .registration-step.step-panel.hidden {
    display: none !important;
  }

  @keyframes stepIn {
    from {
      opacity: 0;
      transform: translateY(8px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

</style>
<script src="<?= rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/') ?>/js/review-helpers.js"></script>
<script>
  (function () {
    var form = document.getElementById('registration-form');
    var panels = form.querySelectorAll('.registration-step');
    var initial = parseInt(form.getAttribute('data-initial-step') || '1', 10);

    function updateStepper(n) {
      document.querySelectorAll('#reg-stepper .stepper-step').forEach(function (el) {
        var s = parseInt(el.dataset.step, 10);
        var icon = el.querySelector('.stepper-icon');
        el.classList.remove('upcoming', 'active', 'completed');
        if (s < n)        { el.classList.add('completed'); icon.textContent = '✓'; }
        else if (s === n) { el.classList.add('active');    icon.textContent = s;   }
        else              { el.classList.add('upcoming');  icon.textContent = s;   }
      });
      document.querySelectorAll('#reg-stepper .stepper-connector').forEach(function (line) {
        var after = parseInt(line.dataset.after, 10);
        after < n ? line.classList.add('done') : line.classList.remove('done');
      });
    }

    function showStep(num, scrollIntoView) {
      var n = Math.max(1, Math.min(4, num));
      updateStepper(n);
      // Dismiss the "payment cancelled" banner as soon as the user navigates
      var cancelBanner = document.getElementById('cancel-banner');
      if (cancelBanner) cancelBanner.remove();
      panels.forEach(function (p) {
        var stepNum = parseInt(p.id.replace('step-', ''), 10) || 1;
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
      if (n === 3) populateReview();
    }

    /* ── Review helpers (loaded from js/review-helpers.js) ──────────── */
    var escHtml      = window.ReviewHelpers.escHtml;
    var rvRow        = window.ReviewHelpers.rvRow;
    var rvVal        = window.ReviewHelpers.rvVal;
    var kidReviewCard = window.ReviewHelpers.kidReviewCard;

    function populateReview() {
      // ── Parent block ──
      var hearVal = rvVal('hear_from_us_select');
      if (hearVal === 'Other') {
        var other = rvVal('hear_from_us_other');
        hearVal = other ? 'Other: ' + other : 'Other';
      }
      var parentEl = document.getElementById('rv-parent');
      if (parentEl) {
        parentEl.innerHTML =
          rvRow('Name',              rvVal('parent_first_name') + ' ' + rvVal('parent_last_name')) +
          rvRow('Email',             rvVal('email')) +
          rvRow('Phone',             rvVal('phone')) +
          rvRow('Address',           rvVal('address')) +
          rvRow('Church',            rvVal('home_church')) +
          rvRow('Alt. pick-up',      rvVal('alternative_pickup_name') + (rvVal('alternative_pickup_phone') ? ' · ' + rvVal('alternative_pickup_phone') : '')) +
          rvRow('Emergency contact', rvVal('emergency_contact_name') + ' · ' + rvVal('emergency_contact_phone') + ' (' + rvVal('emergency_contact_relationship') + ')') +
          rvRow('Heard about us',    hearVal);
      }

      // ── Kids block ──
      var kidsEl = document.getElementById('rv-kids');
      if (kidsEl) {
        var kidHtml = '';
        document.querySelectorAll('#kids-container .kid-block').forEach(function (block, i) {
          var g = function(sel) { var e = block.querySelector(sel); return e ? e.value.trim() : ''; };
          kidHtml += kidReviewCard({
            first_name:   g('[name*="[first_name]"]'),
            last_name:    g('[name*="[last_name]"]'),
            date_of_birth: g('[name*="[date_of_birth]"]'),
            age:          g('[name*="[age]"]'),
            gender:       g('[name*="[gender]"]'),
            last_grade:   g('[name*="[last_grade_completed]"]'),
            t_shirt:      g('[name*="[t_shirt_size]"]'),
            medical:      g('[name*="[medical_allergy_info]"]'),
          }, i);
        });
        kidsEl.innerHTML = kidHtml || '<p class="text-gray-400 italic">No children added.</p>';
      }
    }

    // "Edit" links inside the review panel jump to the relevant step
    document.getElementById('step-3').addEventListener('click', function (e) {
      var btn = e.target.closest('.rv-edit-btn');
      if (!btn) return;
      var goto = parseInt(btn.getAttribute('data-goto'), 10);
      showStep(goto, false);
      document.getElementById('form-top').scrollIntoView({ behavior: 'smooth' });
    });

    form.querySelectorAll('.step-next').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next    = parseInt(btn.getAttribute('data-next'), 10);
        var current = next - 1;
        var currentEl = document.getElementById('step-' + current);
        if (currentEl) {
          // Collect every invalid field (inputs, selects, textareas, and age
          // inputs that carry a setCustomValidity message)
          var allInvalid = Array.from(
            currentEl.querySelectorAll('input:invalid, select:invalid, textarea:invalid')
          );

          if (allInvalid.length > 0) {
            // Highlight every invalid field at once so the user sees all gaps
            allInvalid.forEach(function (el) {
              el.classList.add('field-error');
              // Auto-clear the highlight as soon as the field becomes valid
              function clearOnFix() {
                if (el.checkValidity()) {
                  el.classList.remove('field-error');
                  el.removeEventListener('input',  clearOnFix);
                  el.removeEventListener('change', clearOnFix);
                }
              }
              el.addEventListener('input',  clearOnFix);
              el.addEventListener('change', clearOnFix);
            });
            // Scroll to and report the first problem
            allInvalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            allInvalid[0].focus();
            allInvalid[0].reportValidity();
            return;
          }
        }
        showStep(next, false);
        document.getElementById('form-top').scrollIntoView({ behavior: 'smooth' });
      });
    });

    form.querySelectorAll('.step-back').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var back = parseInt(btn.getAttribute('data-back'), 10);
        showStep(back, false);
        document.getElementById('form-top').scrollIntoView({ behavior: 'smooth' });
      });
    });

    showStep(initial, false);

    // Scroll to the form when returning from a cancelled Stripe payment
    if (new URLSearchParams(window.location.search).get('cancelled') !== null) {
      document.getElementById('form-top').scrollIntoView({ behavior: 'smooth' });
    }

    // Add/remove kid (grade options from PHP — change $grade_options array at top to update)
    var gradeOptions = <?= json_encode($grade_options) ?>;
    var gradeOptionsHtml = gradeOptions.map(function (o) { return '<option value="' + o.replace(/"/g, '&quot;') + '">' + o.replace(/</g, '&lt;') + '</option>'; }).join('');
    var tShirtOptions = <?= json_encode($t_shirt_size_options) ?>;
    var tShirtOptionsHtml = tShirtOptions.map(function (o) { return '<option value="' + o.replace(/"/g, '&quot;') + '">' + o.replace(/</g, '&lt;') + '</option>'; }).join('');

    // Required kid sub-fields (driven by $required_fields on the server)
    var requiredKidFields = <?= json_encode(array_values(array_filter(
        ['first_name', 'last_name', 'date_of_birth', 'last_grade_completed', 't_shirt_size', 'gender', 'age'],
        fn($f) => in_array('kid_' . $f, $required_fields, true)
    ))) ?>;
    function kidReq(f)  { return requiredKidFields.indexOf(f) !== -1 ? ' required' : ''; }
    function kidStar(f) { return requiredKidFields.indexOf(f) !== -1 ? ' *' : ''; }

    var container = document.getElementById('kids-container');
    var addBtn = document.getElementById('add-kid');
    var maxKids = <?= (int) $max_kids ?>;
    var index = <?= count($kids_for_form) ?>;

    // Rotating colour palette (defined in layout.php for easy editing)
    var kidColors = window.kidCardColors || [];
    var kidColorIndex = 0;
    function applyKidColor(block) {
      if (!kidColors.length) return;
      block.style.backgroundColor = kidColors[kidColorIndex % kidColors.length];
      kidColorIndex++;
    }
    // Colour PHP-rendered blocks in order
    container.querySelectorAll('.kid-block').forEach(function (b) { applyKidColor(b); });

    // Enable "+ Add Child" only when every required field is filled AND no age is too young
    function refreshAddBtn() {
      if (!addBtn) return;
      var atMax      = container.querySelectorAll('.kid-block').length >= maxKids;
      var anyInvalid = !!container.querySelector('[required]:invalid')  // empty required fields
                    || !!container.querySelector('.age-input:invalid'); // age setCustomValidity (too young)
      addBtn.disabled = atMax || anyInvalid;
    }

    // Re-evaluate on any input or selection change inside the kids container
    container.addEventListener('input',  refreshAddBtn);
    container.addEventListener('change', refreshAddBtn);

    // Run once on load so the button reflects pre-filled (session-restored) data
    refreshAddBtn();

    if (addBtn) {
      addBtn.addEventListener('click', function () {
        if (container.querySelectorAll('.kid-block').length >= maxKids) return;
        var div = document.createElement('div');
        div.className = 'kid-block border border-gray-200 rounded-lg p-4';
        div.setAttribute('data-index', index);
        div.innerHTML = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
          '<div><label class="block text-sm font-medium text-gray-700 mb-1">First name' + kidStar('first_name') + '</label><input type="text" name="kids[' + index + '][first_name]"' + kidReq('first_name') + ' maxlength="100" class="input-field"></div>' +
          '<div><label class="block text-sm font-medium text-gray-700 mb-1">Last name' + kidStar('last_name') + '</label><input type="text" name="kids[' + index + '][last_name]"' + kidReq('last_name') + ' maxlength="100" class="input-field"></div></div>' +
          '<div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">' +
          '<div><label class="block text-sm font-medium text-gray-700 mb-1">Date of birth' + kidStar('date_of_birth') + '</label><input type="date" name="kids[' + index + '][date_of_birth]"' + kidReq('date_of_birth') + ' class="input-field dob-input"></div>' +
          '<div><label class="block text-sm font-medium text-gray-700 mb-1">Age' + kidStar('age') + ' <span class="text-gray-400 font-normal text-xs">(age as of VBS first day)</span></label><input type="number" name="kids[' + index + '][age]"' + kidReq('age') + ' min="1" max="18" class="input-field age-input"></div>' +
          '<div><label class="block text-sm font-medium text-gray-700 mb-1">Gender' + kidStar('gender') + '</label><select name="kids[' + index + '][gender]"' + kidReq('gender') + ' class="input-field"><option value="">Select</option><option value="Boy">Boy</option><option value="Girl">Girl</option></select></div>' +
          '</div>' +
          '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Child Grade Entering in Fall 2026 (Note: Not the current grade)' + kidStar('last_grade_completed') + '</label><select name="kids[' + index + '][last_grade_completed]"' + kidReq('last_grade_completed') + ' class="input-field w-full"><option value="">Select</option>' + gradeOptionsHtml + '</select></div>' +
          '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">T-Shirt size' + kidStar('t_shirt_size') + '</label><select name="kids[' + index + '][t_shirt_size]"' + kidReq('t_shirt_size') + ' class="input-field w-full"><option value="">Select</option>' + tShirtOptionsHtml + '</select></div>' +
          '<div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Allergies / medical info</label><textarea name="kids[' + index + '][medical_allergy_info]" rows="2" maxlength="500" class="input-field w-full resize-y"></textarea></div>' +
          '<button type="button" class="remove-kid mt-4 text-sm text-red-600 hover:text-red-700" aria-label="Remove kid">Remove</button>';
        // Grab previous sibling's last name before appending
        var prevBlocks = container.querySelectorAll('.kid-block');
        var prevLastName = '';
        if (prevBlocks.length > 0) {
          var prevLastInput = prevBlocks[prevBlocks.length - 1].querySelector('input[name*="[last_name]"]');
          if (prevLastInput) prevLastName = prevLastInput.value.trim();
        }

        container.appendChild(div);
        applyKidColor(div);
        index++;

        // Pre-fill last name from previous child
        var lastNameInput = div.querySelector('input[name*="[last_name]"]');
        if (lastNameInput && prevLastName) lastNameInput.value = prevLastName;

        // Scroll new card into view then focus first name
        div.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var firstNameInput = div.querySelector('input[name*="[first_name]"]');
        if (firstNameInput) setTimeout(function () { firstNameInput.focus(); }, 300);

        refreshAddBtn(); // new block has empty required fields → disable immediately
        div.querySelector('.remove-kid').addEventListener('click', function () { removeKid(div); });
        // Direct listeners on the new DOB input as a belt-and-suspenders fallback
        var newDob = div.querySelector('.dob-input');
        if (newDob) {
          newDob.addEventListener('change', function() { applyDobToAge(newDob); });
          newDob.addEventListener('input',  function() { applyDobToAge(newDob); });
        }
      });
    }

    function removeKid(block) {
      if (container.querySelectorAll('.kid-block').length <= 1) return;
      block.remove();
      refreshAddBtn();
    }
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('remove-kid')) removeKid(e.target.closest('.kid-block'));
    });

    // Photo consent (Section 5): only one of Yes/No may be checked
    form.querySelectorAll('.photo-consent-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        if (this.checked) {
          form.querySelectorAll('.photo-consent-cb').forEach(function (other) {
            if (other !== cb) other.checked = false;
          });
        }
      });
    });

    // Auto-calculate age as of event start date (falls back to today PT if not set)
    var eventStartDate = <?= json_encode($event_start_date ?: '') ?>;
    var MIN_KID_AGE    = 4;
    var AGE_GRACE_DAYS = 0; // kids whose birthday falls within this many days after event start are allowed

    // Human-readable version of the event start date for warning messages
    var eventDateLabel = (function () {
      if (!eventStartDate) return '';
      var d = new Date(eventStartDate + 'T00:00:00');
      return isNaN(d) ? eventStartDate
        : d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    })();

    // extraDays: optional offset added to the reference date (used for grace-period check)
    function calcAge(dobValue, extraDays) {
      if (!dobValue) return null;
      var refDate;
      if (eventStartDate) {
        refDate = new Date(eventStartDate + 'T00:00:00');
      } else {
        var ptStr = new Date().toLocaleString('en-US', { timeZone: 'America/Los_Angeles' });
        refDate = new Date(ptStr);
      }
      if (extraDays) refDate = new Date(refDate.getTime() + extraDays * 86400000);
      var dob = new Date(dobValue + 'T00:00:00');
      if (isNaN(dob) || isNaN(refDate)) return null;
      var age = refDate.getFullYear() - dob.getFullYear();
      var m   = refDate.getMonth() - dob.getMonth();
      // Birthday ON the reference date counts — only decrement when strictly before
      if (m < 0 || (m === 0 && refDate.getDate() < dob.getDate())) age--;
      return age;
    }

    function applyDobToAge(dobInput) {
      var block    = dobInput.closest('.kid-block');
      if (!block) return;
      var ageInput = block.querySelector('.age-input');
      if (!ageInput) return;

      var age = calcAge(dobInput.value);
      if (age !== null) ageInput.value = age;

      // Find or create the inline warning element (inserted after the DOB/Age/Gender grid row)
      var warning = block.querySelector('.dob-age-warning');
      if (!warning) {
        warning = document.createElement('p');
        warning.className = 'dob-age-warning mt-2 text-sm text-red-600 font-medium';
        var grid = dobInput.closest('.grid');
        if (grid) grid.insertAdjacentElement('afterend', warning);
        else ageInput.parentNode.appendChild(warning);
      }

      // Allow through if child reaches MIN_KID_AGE within the grace window after event start
      var ageWithGrace = calcAge(dobInput.value, AGE_GRACE_DAYS);
      var tooYoung = dobInput.value && age !== null && ageWithGrace !== null && ageWithGrace < MIN_KID_AGE;
      if (tooYoung) {
        var dateNote = eventDateLabel ? ' as of ' + eventDateLabel : '';
        warning.textContent = 'Minimum age is ' + MIN_KID_AGE + dateNote
          + '. This child is too young to register. Contact us if you have any questions.';
        ageInput.setCustomValidity('Minimum age is ' + MIN_KID_AGE
          + (eventDateLabel ? ' as of ' + eventDateLabel : '') + '.');
      } else {
        warning.textContent = '';
        ageInput.setCustomValidity('');
      }
      refreshAddBtn(); // age validity just changed — re-evaluate the button
    }
    // Fire on every DOB change — listen to both 'change' and 'input' for
    // cross-browser reliability (Safari fires 'input' on date pickers, not 'change').
    // Event delegation covers dynamically added kid rows too.
    function onDobEvent(e) {
      if (e.target.classList.contains('dob-input')) applyDobToAge(e.target);
    }
    form.addEventListener('change', onDobEvent);
    form.addEventListener('input',  onDobEvent);
    // Also populate age for any pre-filled DOB values on page load
    form.querySelectorAll('.dob-input').forEach(function(el) {
      if (el.value) applyDobToAge(el);
    });

    // Name field auto-capitalizer — title-cases on blur (handles dynamic kid rows via delegation)
    function capitalizeName(val) {
      return val.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }
    var NAME_FIELDS = /first_name|last_name|alternative_pickup_name|emergency_contact_name/;
    form.addEventListener('blur', function (e) {
      var el = e.target;
      if (el.tagName === 'INPUT' && el.type === 'text' && NAME_FIELDS.test(el.name || '')) {
        el.value = capitalizeName(el.value);
      }
    }, true); // capture phase so it fires even on inputs inside nested elements

    // Phone number live formatter — produces (xxx)xxx-xxxx as the user types
    function formatPhone(input) {
      var digits = input.value.replace(/\D/g, '').slice(0, 10);
      var out = '';
      if (digits.length === 0) {
        out = '';
      } else if (digits.length <= 3) {
        out = '(' + digits;
      } else if (digits.length <= 6) {
        out = '(' + digits.slice(0, 3) + ')' + digits.slice(3);
      } else {
        out = '(' + digits.slice(0, 3) + ')' + digits.slice(3, 6) + '-' + digits.slice(6);
      }
      // Only update if value actually changed to avoid resetting cursor unnecessarily
      if (input.value !== out) input.value = out;
    }

    function attachPhoneFormatter(el) {
      el.addEventListener('input', function () { formatPhone(this); });
      // Format any pre-filled value on page load
      if (el.value) formatPhone(el);
    }

    document.querySelectorAll('input[type="tel"]').forEach(attachPhoneFormatter);

    // Step 4 Consent auto-jump
    var step3 = document.getElementById('step-4');
    if (step3) {
      var consentInputs = step3.querySelectorAll('input[type="checkbox"]');
      consentInputs.forEach(function (input) {
        input.addEventListener('change', function () {
          if (this.checked) {
            // Find the current card
            var currentCard = this.closest('.card');
            if (!currentCard) return;

            // Find the next card to jump to
            var nextCard = currentCard.nextElementSibling;

            // If Section 5, it's inside the same parent div, so nextElementSibling might work
            // If it's the last item in the space-y-4 div, we need to find the sibling of that div
            if (!nextCard || !nextCard.classList.contains('card')) {
              var parent = currentCard.parentElement;
              if (parent && parent.classList.contains('space-y-4')) {
                nextCard = parent.nextElementSibling;
              }
            }

            if (nextCard && nextCard.classList.contains('card')) {
              setTimeout(function () {
                window.scrollTo({
                  top: nextCard.offsetTop - 100,
                  behavior: 'smooth'
                });
              }, 100);
            }
          }
        });
      });
    }
    // Alternative pick-up phone becomes required when a name is entered
    (function () {
      var nameInput  = document.getElementById('alternative_pickup_name');
      var phoneInput = document.getElementById('alternative_pickup_phone');
      var phoneLabel = document.getElementById('alternative_pickup_phone_label');
      if (!nameInput || !phoneInput || !phoneLabel) return;

      function syncAltPickupRequired() {
        var hasName = nameInput.value.trim() !== '';
        phoneInput.required = hasName;
        phoneLabel.textContent = 'Alternative pick-up phone' + (hasName ? ' *' : '');
      }

      nameInput.addEventListener('input', syncAltPickupRequired);
      syncAltPickupRequired(); // run on page load in case name is pre-filled
    })();

  })();
</script>
<?php layout_footer(); ?>
</body>

</html>