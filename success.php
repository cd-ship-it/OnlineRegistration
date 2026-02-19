<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
require_once __DIR__ . '/includes/logger.php';
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
  $stmt = $pdo->prepare("UPDATE registrations SET status = 'paid', stripe_session_id = ?, updated_at = ? WHERE id = ? AND status = 'draft'");
  $stmt->execute([$session_id, date('Y-m-d H:i:s'), $reg_id]);
  if ($stmt->rowCount() > 0) {
    unset($_SESSION['vbs_registration_data']);
    $stmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ?");
    $stmt->execute([$reg_id]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($registration) {
      $stmt = $pdo->prepare("SELECT * FROM registration_kids WHERE registration_id = ? ORDER BY sort_order");
      $stmt->execute([$reg_id]);
      $registration['kids'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Production: send confirmation email to parent (once per paid registration)
    if ($registration && APP_ENV === 'production') {
      $to = $registration['email'];
      $subject = 'Rainforest Falls VBS 2026 – Registration Confirmed | Crosspoint Church';
      $parent_name = trim($registration['parent_first_name'] . ' ' . $registration['parent_last_name']);
      $total_dollars = ((int) $registration['total_amount_cents']) / 100.0;
      $total_str = format_money($total_dollars);

      // HTML Email Template
      $settings = get_settings($pdo);
      $template = file_get_contents(__DIR__ . '/vbs-email-v2.html');

      $kids_list_html = [];
      foreach ($registration['kids'] as $k) {
        $line = htmlspecialchars($k['first_name'] . ' ' . $k['last_name']);
        if (!empty($k['age']))
          $line .= ' (' . (int) $k['age'] . ')';
        $kids_list_html[] = $line;
      }
      $children_names = implode('<br>', $kids_list_html);

      $replacements = [
        '{{PARENT_FIRST_NAME}}' => htmlspecialchars($registration['parent_first_name'] ?? ''),
        '{{PARENT_NAME}}' => htmlspecialchars($parent_name),
        '{{TOTAL_PAID}}' => htmlspecialchars($total_str),
        '{{CHILDREN_NAMES}}' => $children_names,
        '{{event_title}}' => htmlspecialchars($settings['event_title'] ?? 'VBS 2026'),
        '{{event_start_date}}' => htmlspecialchars($settings['event_start_date'] ?? ''),
        '{{event_end_date}}' => htmlspecialchars($settings['event_end_date'] ?? ''),
        '{{event_start_time}}' => htmlspecialchars($settings['event_start_time'] ?? ''),
        '{{event_end_time}}' => htmlspecialchars($settings['event_end_time'] ?? ''),
      ];

      $body = strtr($template, $replacements);
      $reply_to = 'cm@crosspointchurchsv.org';
      $headers = [
        'From: Crosspoint Church VBS <' . $reply_to . '>',
        'Reply-To: ' . $reply_to,
        'Cc: cd@crosspointchurchsv.org',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
      ];
      $sent = mail($to, $subject, $body, implode("\r\n", $headers));
      if ($sent) {
        app_log('high', 'Email', 'Confirmation email sent', [
          'to' => $to,
          'subject' => $subject,
          'registration_id' => $reg_id,
          'sent_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s T'),
        ]);
      } else {
        app_log('high', 'Email', 'Confirmation email FAILED', [
          'to' => $to,
          'subject' => $subject,
          'registration_id' => $reg_id,
          'attempted_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s T'),
        ]);
      }
    }
  }
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