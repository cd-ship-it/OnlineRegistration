<?php
/**
 * Email helpers for VBS registration.
 * Requires: config.php, price.php, logger.php to already be loaded.
 */

/**
 * Finalize a paid registration and send the confirmation email if this
 * process wins the claim.
 *
 * Replaces the repeated 3-line finalize+notify pattern that used to live
 * in both success.php and stripe-webhook.php, giving each caller a single
 * call site with no duplicated logic.
 *
 * The email is only dispatched when APP_ENV === 'production', so the
 * function is safe to call in development and test environments.
 *
 * @param PDO    $pdo        Active database connection.
 * @param int    $reg_id     Registration ID from Stripe session metadata.
 * @param string $session_id Stripe Checkout Session ID (stored for idempotency).
 */
function payment_finalize_and_notify(PDO $pdo, int $reg_id, string $session_id): void
{
    $email_data = registration_finalize_payment($pdo, $reg_id, $session_id);
    if ($email_data !== null && defined('APP_ENV') && APP_ENV === 'production') {
        send_registration_confirmation_email($pdo, $email_data);
    }
}

/**
 * Build and send the registration confirmation email.
 *
 * @param PDO   $pdo          Active database connection (used to fetch settings).
 * @param array $registration Row from `registrations` with a nested `kids` array
 *                            (as returned by success_get_registration_with_kids()).
 * @return bool               True if mail() accepted the message, false otherwise.
 */
function send_registration_confirmation_email(PDO $pdo, array $registration): bool
{
    $reg_id      = (int) $registration['id'];
    $to          = $registration['email'];
    $subject     = 'Rainforest Falls VBS 2026 – Registration Confirmed | Crosspoint Church';
    $parent_name = trim($registration['parent_first_name'] . ' ' . $registration['parent_last_name']);
    $total_str   = format_money(((int) $registration['total_amount_cents']) / 100.0);

    $template_path = dirname(__DIR__) . '/vbs-email.html';
    if (!file_exists($template_path)) {
        app_log('high', 'Email', 'Email template file not found', [
            'path'            => $template_path,
            'registration_id' => $reg_id,
        ]);
        return false;
    }
    $template = file_get_contents($template_path);

    $settings = get_settings($pdo);

    $kids_list_html = [];
    foreach ($registration['kids'] as $k) {
        $line = htmlspecialchars($k['first_name'] . ' ' . $k['last_name']);
        if (!empty($k['age']))
            $line .= ' (' . (int) $k['age'] . ')';
        $kids_list_html[] = $line;
    }

    $replacements = [
        '{{PARENT_FIRST_NAME}}' => htmlspecialchars($registration['parent_first_name'] ?? ''),
        '{{PARENT_NAME}}'       => htmlspecialchars($parent_name),
        '{{TOTAL_PAID}}'        => htmlspecialchars($total_str),
        '{{CHILDREN_NAMES}}'    => implode('<br>', $kids_list_html),
        '{{event_title}}'       => htmlspecialchars($settings['event_title']      ?? 'VBS 2026'),
        '{{event_start_date}}'  => htmlspecialchars($settings['event_start_date'] ?? ''),
        '{{event_end_date}}'    => htmlspecialchars($settings['event_end_date']   ?? ''),
        '{{event_start_time}}'  => htmlspecialchars($settings['event_start_time'] ?? ''),
        '{{event_end_time}}'    => htmlspecialchars($settings['event_end_time']   ?? ''),
    ];

    $body      = strtr($template, $replacements);
    $reply_to  = 'cm@crosspointchurchsv.org';
    $headers   = implode("\r\n", [
        'From: Crosspoint Church VBS <' . $reply_to . '>',
        'Reply-To: ' . $reply_to,
        'Cc: cd@crosspointchurchsv.org',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    $sent = mail($to, $subject, $body, $headers);
    $now  = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s T');

    if ($sent) {
        app_log('high', 'Email', 'Confirmation email sent', [
            'to'              => $to,
            'subject'         => $subject,
            'registration_id' => $reg_id,
            'sent_at'         => $now,
        ]);
    } else {
        app_log('high', 'Email', 'Confirmation email FAILED', [
            'to'              => $to,
            'subject'         => $subject,
            'registration_id' => $reg_id,
            'attempted_at'    => $now,
        ]);
    }

    return $sent;
}
