<?php
/**
 * Stripe webhook: checkout.session.completed -> finalize registration + send email.
 *
 * Configure in Stripe Dashboard: Webhooks -> Add endpoint -> URL = APP_URL/stripe-webhook
 * Use STRIPE_WEBHOOK_SECRET for signing secret.
 *
 * registration_finalize_payment() uses SELECT … FOR UPDATE so that this handler
 * and success.php cannot both claim the confirmation email — exactly one sends it.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/price.php';
require_once __DIR__ . '/includes/db_helper.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/vendor/autoload.php';

$payload = @file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret  = trim(env('StripeWebhookSecret', '') ?: env('STRIPE_WEBHOOK_SECRET', ''));

if ($secret === '') {
    http_response_code(500);
    exit('Webhook secret not configured');
}

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
} catch (Exception $e) {
    http_response_code(400);
    exit('Invalid signature');
}

if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    $reg_id  = isset($session->metadata->registration_id) ? (int) $session->metadata->registration_id : 0;

    if ($reg_id > 0) {
        // Atomically finalize payment and send the confirmation email if this
        // process wins the claim (idempotent — safe if success.php fires first).
        payment_finalize_and_notify($pdo, $reg_id, $session->id);
    }
}

http_response_code(200);
echo 'OK';
