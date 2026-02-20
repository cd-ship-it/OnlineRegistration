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
$reg_id = null;
if ($session && $session->payment_status === 'paid' && !empty($session->metadata->registration_id)) {
  $reg_id = (int) $session->metadata->registration_id;

  // Atomically finalize payment and send the confirmation email if this
  // process wins the claim (idempotent — safe if webhook fires first).
  payment_finalize_and_notify($pdo, $reg_id, $session_id);

  // Always load the registration for the thank-you page, regardless of who sent the email.
  $registration = success_get_registration_with_kids($pdo, $reg_id);
  unset($_SESSION['vbs_registration_data']);
}

// Handle rating submission (after registration is loaded)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_rating') {
  $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
  $reg_id_for_rating = isset($_POST['registration_id']) ? (int) $_POST['registration_id'] : 0;
  if ($rating >= 1 && $rating <= 5 && $reg_id_for_rating > 0) {
    try {
      $stmt = $pdo->prepare("UPDATE registrations SET signup_rating = ? WHERE id = ?");
      $stmt->execute([$rating, $reg_id_for_rating]);
      // Reload the registration to get updated rating
      $registration = success_get_registration_with_kids($pdo, $reg_id_for_rating);
      $reg_id = $reg_id_for_rating;
    } catch (Exception $e) {
      // Silently fail - don't break the page if rating save fails
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
      <p class="text-gray-600 mt-2">Your registration and payment are complete. </p>
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
    <?php if ($registration && empty($registration['signup_rating'])): ?>
      <div class="card text-center mt-6" id="survey-card">
        <p class="text-gray-700 mb-4">How easy was it to sign up for VBS? Tap a star to let us know.</p>
        <div class="flex items-center justify-center gap-2" id="star-rating">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <button type="button" class="star-btn text-3xl text-gray-300 transition-colors focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:ring-offset-2 rounded" 
                    data-rating="<?= $i ?>" 
                    aria-label="Rate <?= $i ?> star<?= $i > 1 ? 's' : '' ?>">
              ★
            </button>
          <?php endfor; ?>
        </div>
        <style>.star-btn.star-highlight { color: #facc15 !important; }</style>
        <form method="post" action="" id="rating-form" class="hidden">
          <input type="hidden" name="action" value="save_rating">
          <input type="hidden" name="registration_id" value="<?= $reg_id ? (int) $reg_id : '' ?>">
          <input type="hidden" name="rating" id="rating-value" value="">
        </form>
      </div>
    <?php elseif ($registration && !empty($registration['signup_rating'])): ?>
      <div class="card text-center mt-6 bg-green-50 border-green-200">
        <p class="text-green-700">Thank you for your feedback!</p>
        <p class="text-green-600 mt-2">You can close this page now.</p>
      </div>
    <?php endif; ?>
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
<script>
(function() {
  var starBtns = document.querySelectorAll('.star-btn');
  var ratingForm = document.getElementById('rating-form');
  var ratingValue = document.getElementById('rating-value');
  var surveyCard = document.getElementById('survey-card');
  
  if (!starBtns.length || !ratingForm || !ratingValue) return;
  
  function highlightStars(upTo) {
    starBtns.forEach(function(btn, idx) {
      var starNum = idx + 1;
      if (starNum <= upTo) {
        btn.classList.remove('text-gray-300');
        btn.classList.add('star-highlight');
      } else {
        btn.classList.remove('star-highlight');
        btn.classList.add('text-gray-300');
      }
    });
  }
  
  starBtns.forEach(function(btn) {
    var rating = parseInt(btn.getAttribute('data-rating'), 10);
    
    btn.addEventListener('click', function() {
      ratingValue.value = rating;
      ratingForm.submit();
    });
    
    btn.addEventListener('mouseenter', function() {
      highlightStars(rating);
    });
  });
  
  var starContainer = document.getElementById('star-rating');
  if (starContainer) {
    starContainer.addEventListener('mouseleave', function() {
      highlightStars(0);
    });
  }
})();
</script>
</body>

</html>
