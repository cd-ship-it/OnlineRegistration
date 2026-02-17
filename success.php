<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
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
    $stmt = $pdo->prepare("UPDATE registrations SET status = 'paid', stripe_session_id = ? WHERE id = ? AND status = 'draft'");
    $stmt->execute([$session_id, $reg_id]);
    if ($stmt->rowCount() > 0) {
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
            $kids_list = [];
            foreach ($registration['kids'] as $k) {
                $line = $k['first_name'] . ' ' . $k['last_name'];
                if (!empty($k['age'])) $line .= ' (age ' . (int)$k['age'] . ')';
                $kids_list[] = $line;
            }
            $total_dollars = ((int) $registration['total_amount_cents']) / 100.0;
            $total_str = format_money($total_dollars);
            $body = "Hi " . $parent_name . ",\n\n"
                . "Thank you for registering for Rainforest Falls VBS 2026 at Crosspoint Church. Your registration and payment are complete.\n\n"
                . "REGISTRATION DETAILS\n"
                . "--------------------\n"
                . "Parent: " . $parent_name . "\n"
                . "Email: " . $registration['email'] . "\n";
            if (!empty($registration['phone'])) $body .= "Phone: " . $registration['phone'] . "\n";
            $body .= "Total paid: " . $total_str . "\n\n"
                . "Children registered:\n";
            foreach ($kids_list as $line) $body .= "  • " . $line . "\n";
            $body .= "\n"
                . "EVENT DETAILS\n"
                . "-------------\n"
                . "Date: June 15–19, 2026\n"
                . "Time: 9:00 am – 12:30 pm\n"
                . "Location: 658 Gibraltar Court, Milpitas, CA 95035\n\n"
                . "We look forward to seeing you at Rainforest Falls!\n\n"
                . "If you have any questions, reply to this email.\n\n"
                . "— Crosspoint Church";
            $reply_to = 'cm@crosspointchurchsv.org';
            $headers = [
                'From: Crosspoint Church VBS <' . $reply_to . '>',
                'Reply-To: ' . $reply_to,
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: PHP/' . phpversion(),
            ];
            @mail($to, $subject, $body, implode("\r\n", $headers));
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
    <p class="text-gray-600 mt-2">Your registration and payment are complete.</p>
  </div>
  <div class="card space-y-4">
    <h2 class="font-semibold text-gray-900">Registration details</h2>
    <p><span class="text-gray-600">Parent:</span> <?= htmlspecialchars($registration['parent_first_name'] . ' ' . $registration['parent_last_name']) ?></p>
    <p><span class="text-gray-600">Email:</span> <?= htmlspecialchars($registration['email']) ?></p>
    <?php if (!empty($registration['phone'])): ?><p><span class="text-gray-600">Phone:</span> <?= htmlspecialchars($registration['phone']) ?></p><?php endif; ?>
    <p><span class="text-gray-600">Total paid:</span> <?= format_money(((int) $registration['total_amount_cents']) / 100.0) ?></p>
    <div>
      <span class="text-gray-600">Children registered:</span>
      <ul class="list-disc list-inside mt-1">
        <?php foreach ($registration['kids'] as $k): ?>
        <li><?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?><?= $k['age'] ? ' (age ' . (int)$k['age'] . ')' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php else: ?>
  <div class="card text-center">
    <h1 class="text-xl font-bold text-gray-800">Payment confirmed</h1>
    <p class="text-gray-600 mt-2">If you just completed payment, your registration is being updated. You may close this page.</p>
    <p class="mt-4"><a href="<?= APP_URL ?>/register" class="btn-primary inline-block">Back to registration</a></p>
  </div>
  <?php endif; ?>
  <p class="text-center text-gray-500 text-sm mt-8"><a href="<?= APP_URL ?>/register" class="text-indigo-600 hover:underline">Register another</a></p>

  <div class="mt-10 flex justify-center">
    <img src="https://crosspointchurchsv.org/branding/logos/Xpt-ID2015-1_1400x346.png" alt="Crosspoint Church" class="max-w-xs sm:max-w-md h-auto" width="350" height="86">
  </div>
</div>
</body>
</html>
