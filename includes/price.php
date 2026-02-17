<?php
/**
 * Compute total price in cents for a given number of kids and optional registration date.
 * Uses settings: price_per_kid_cents, early_bird_*, multi_kid_discount_percent, multi_kid_min_count.
 */
function get_settings(PDO $pdo) {
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function get_setting(PDO $pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function compute_total_cents(PDO $pdo, $num_kids, $registration_date = null) {
    if ($num_kids < 1) {
        return 0;
    }
    $settings = get_settings($pdo);
    $price_per_kid = (int) ($settings['price_per_kid_cents'] ?? 0);
    $early_start = $settings['early_bird_start_date'] ?? '';
    $early_end = $settings['early_bird_end_date'] ?? '';
    $early_discount_percent = (float) ($settings['early_bird_discount_percent'] ?? 0);
    $multi_discount_percent = (float) ($settings['multi_kid_discount_percent'] ?? 0);
    $multi_min = (int) ($settings['multi_kid_min_count'] ?? 2);

    $date = $registration_date ?: date('Y-m-d');
    $subtotal = $price_per_kid * $num_kids;

    if ($early_start && $early_end && $early_discount_percent > 0) {
        if ($date >= $early_start && $date <= $early_end) {
            $subtotal = $subtotal * (1 - $early_discount_percent / 100);
        }
    }

    if ($num_kids >= $multi_min && $multi_discount_percent > 0) {
        $subtotal = $subtotal * (1 - $multi_discount_percent / 100);
    }

    return (int) round($subtotal);
}

function format_money($cents, $currency = 'usd') {
    return '$' . number_format($cents / 100, 2);
}
