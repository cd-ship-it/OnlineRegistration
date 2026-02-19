<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
require_once __DIR__ . '/includes/db_helper.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/vendor/autoload.php';

$session_id = $_GET['session_id'] ?? '';
if ($session_id === '') {
  header('Location: ' . APP_URL . '/register', true, 302);
  exit;
}

try {
  \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
  $session = \Stripe\Checkout\Session::retrieve($session_id);
} catch (Exception $e) {
  $session = null;
}

$registration = null;
if ($session && $session->payment_status === 'paid' && !empty($session->metadata->registration_id)) {
  $reg_id = (int) $session->metadata->registration_id;

  // Atomically finalize payment and send the confirmation email if this
  // process wins the claim (idempotent — safe if webhook fires first).
  payment_finalize_and_notify($pdo, $reg_id, $session_id);

  // Always load the registration for the thank-you page, regardless of who sent the email.
  $registration = success_get_registration_with_kids($pdo, $reg_id);
  unset($_SESSION['vbs_registration_data']);
}

require_once __DIR__ . '/includes/layout.php';
layout_head('Thank you');
?>
<div class="max-w-2xl mx-auto px-4 py-10">
  <?php if ($registration): ?>
    <div class="card text-center mb-6">
      <h1 class="text-2xl font-bold text-indigo-800">Thank you!</h1>
      <p class="text-gray-600 mt-2">Your registration and payment are complete. You can close this page now. </p>
    </div>
    <div class="card space-y-4">
      <h2 class="font-semibold text-gray-900">Registration details</h2>
      <p><span class="text-gray-600">Parent:</span>
        <?= htmlspecialchars($registration['parent_first_name'] . ' ' . $registration['parent_last_name']) ?></p>
      <p><span class="text-gray-600">Email:</span> <?= htmlspecialchars($registration['email']) ?></p>
      <?php if (!empty($registration['phone'])): ?>
        <p><span class="text-gray-600">Phone:</span> <?= htmlspecialchars($registration['phone']) ?></p><?php endif; ?>
      <p><span class="text-gray-600">Total paid:</span>
        <?= format_money(((int) $registration['total_amount_cents']) / 100.0) ?></p>
      <div>
        <span class="text-gray-600">Children registered:</span>
        <ul class="list-disc list-inside mt-1">
          <?php foreach ($registration['kids'] as $k): ?>
            <li>
              <?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?>
              <?= $k['age'] ? ' (age ' . (int) $k['age'] . ')' : '' ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php else: ?>
    <div class="card text-center">
      <h1 class="text-xl font-bold text-gray-800">Payment confirmed</h1>
      <p class="text-gray-600 mt-2">If you just completed payment, your registration is being updated. You may close this
        page.</p>
      <p class="mt-4"><a href="<?= APP_URL ?>/register" class="btn-primary inline-block">Back to registration</a></p>
    </div>
  <?php endif; ?>
  <p class="text-center text-gray-500 text-sm mt-8"><a href="<?= APP_URL ?>/register"
      class="text-indigo-600 hover:underline">Register another</a></p>

</div>
<?php layout_footer(); ?>
</body>

</html>
