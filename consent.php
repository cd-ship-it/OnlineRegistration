<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/layout.php';

$data = $_SESSION['vbs_registration_data'] ?? null;
if (!$data || empty($data['kid_rows'])) {
    header('Location: ' . APP_URL . '/register', true, 302);
    exit;
}

$kid_names = array_map(function ($k) {
    return trim($k['first_name'] . ' ' . $k['last_name']);
}, $data['kid_rows']);
$kid_names_str = implode(', ', $kid_names);

// Load consent text: try script directory first, then document root
$consent_file = __DIR__ . '/consent.txt';
$consent_loaded_from = 'fallback';
$content = @file_get_contents($consent_file);
if ($content !== false && $content !== '') {
    $consent_loaded_from = $consent_file;
}
if ($content === false || $content === '') {
    $alt = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/consent.txt' : null;
    if ($alt && is_readable($alt)) {
        $content = file_get_contents($alt);
        if ($content !== false && $content !== '') {
            $consent_loaded_from = $alt;
        }
    }
}
if ($content === false || $content === '') {
    // Fallback if file missing or unreadable (e.g. wrong path or permissions)
    $content = <<<'FALLBACK'
Consent for Activity Participation
I grant permission for XXXX to participate in the Vacation Bible School at Crosspoint Church on
Jun 15, 2026 - Jun 19, 2026 from 8:45am - 12:45. I also expressly assume all risks of the child whether such risks are known or unknown to
me at this time. I further release Crosspoint Church and its ministers, leaders, employees, volunteers, and agents ("sponsors")
from any claim that my child or I may have against them as a result of injury or illness incurred during the course of participation.

Consent for Transportation
I grant permission for my child to be transported to and from activities sponsored by Crosspoint Church that are located off
church property. All transportation will be provided by adult sponsors who have completed a volunteer driver information form,
and demonstrated responsible driving practices.

Consent for Photography
I give my permission for my child's photograph to be taken by Crosspoint Church event sponsors solely for the purposes
of slide shows, student photo albums, bulletin boards, Crosspoint Church facebook page and webpage.

Consent for Medical Treatment
I authorize Crosspoint Church event sponsors to dispense to my child the prescription drugs and/or over the counter
medications listed below in accordance with the instructions provided on the label. In case of sudden illness or accident to my
child requiring immediate treatment, I authorize Crosspoint Church sponsors to take such action as deemed appropriate to
protect the health and physical well being of my child. This authority extends to any physician(s) selected by the event sponsors.
I understand that in case of emergency, every effort will be made to contact me (and/or the emergency contact designated
below), and that I will be financially responsible for any treatment administered to my child.

Code of Conduct
Both participant and parent(s) or guardian(s) certify they have read the Youth Code of Conduct, and understand that
failure to abide by its guidelines may result in parent being summoned to remove participant from the retreat/activity,
or possible suspension from participation in future retreats and/or youth group activities.
FALLBACK;
}
$content = str_replace(["\r\n", "\r"], ["\n", "\n"], $content);
// Split into paragraphs by one or more blank lines; each paragraph goes in its own box
$paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n+/', $content)));

layout_head('Consent Form');
?>
<div class="max-w-3xl mx-auto px-4 py-10">


  <h1 class="text-2xl font-bold text-gray-900 mb-2">Consent Form</h1>
  <p class="text-gray-600 mb-8">Please read each section below. You may optionally sign to consent; leaving a signature blank does not prevent you from proceeding to payment.</p>

  <?php
  // Same-origin form action so session cookie is always sent (avoid APP_URL vs current host mismatch)
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/consent', PHP_URL_PATH) ?: '/consent';
  $form_action = preg_replace('#/consent$#', '', $path) . '/register';
  if ($form_action === '/register' || trim($form_action, '/') === '') $form_action = '/register';
  ?>
  <?php if (!empty($_GET['payment_error'])): ?>
  <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded text-red-800 text-sm">We couldn’t start payment. <?= htmlspecialchars($_GET['payment_error']) ?> Please try again.</div>
  <?php endif; ?>
  <form method="post" action="<?= htmlspecialchars($form_action) ?>" id="consent-form">
    <input type="hidden" name="action" value="payment">

    <?php foreach ($paragraphs as $i => $block): ?>
      <?php
      $text = $block;
      if ($i === 0) {
          $text = str_replace('XXXX', $kid_names_str, $text);
      }
      $block_id = 'consent-block-' . $i;
      ?>
      <section class="consent-box border border-gray-200 rounded-xl bg-white p-6 shadow-sm mb-6" aria-labelledby="consent-heading-<?= $i ?>">
        <div id="consent-heading-<?= $i ?>" class="text-gray-700 whitespace-pre-wrap leading-relaxed"><?= nl2br(htmlspecialchars($text)) ?></div>
        <div class="mt-6 pt-4 border-t border-gray-200">
          <p class="text-sm font-medium text-gray-700 mb-2">By entering your name below, you consent to the terms outlined above.</p>
          <label for="<?= $block_id ?>" class="block text-sm text-gray-500 mb-1">Signature (optional — leave blank if you do not wish to sign)</label>
          <input type="text" id="<?= $block_id ?>" name="consent_signature[<?= $i ?>]" maxlength="200" class="input-field max-w-md" autocomplete="name">
        </div>
      </section>
    <?php endforeach; ?>

    <div class="card mt-8 text-center">
      <button type="submit" class="btn-primary px-8 py-3 text-lg">Go to payment</button>
    </div>

    <div class="mt-10 pt-8 border-t border-gray-200 flex flex-col items-center gap-3">
      <p class="text-sm font-medium text-gray-500">Secured and powered by Stripe</p>
      <a href="https://stripe.com" target="_blank" rel="noopener noreferrer" class="inline-flex items-center text-gray-400 hover:text-gray-600 transition" aria-label="Stripe — payment processing">
        <img src="img/stripe-badge.png" alt="" width="120" height="40" class="h-10 w-auto object-contain">
      </a>
    </div>
  </form>

  <p class="text-center text-gray-500 text-sm mt-6"><a href="<?= APP_URL ?>/register" class="text-indigo-600 hover:underline">Back to registration</a></p>
</div>
</body>
</html>
