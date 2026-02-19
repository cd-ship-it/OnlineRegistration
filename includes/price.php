<?php
/**
 * Compute total price in dollars for a given number of kids and optional registration date.
 * Uses settings: price_per_kid (regular), early_bird_* (dates + early_bird_price_per_kid), multi_kid_min_count + multi_kid_price_per_kid.
 * Discounts are the actual price per kid in that tier. Stored in DB as _cents; we convert at boundary.
 */
function get_settings(PDO $pdo)
{
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function get_setting(PDO $pdo, $key, $default = null)
{
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function compute_total_dollars(PDO $pdo, $num_kids, $registration_date = null)
{
    if ($num_kids < 1) {
        return 0.0;
    }
    $settings = get_settings($pdo);
    $price_per_kid_dollars = ((int) ($settings['price_per_kid_cents'] ?? 0)) / 100.0;
    $early_start = $settings['early_bird_start_date'] ?? '';
    $early_end = $settings['early_bird_end_date'] ?? '';
    $early_bird_price_per_kid_dollars = ((int) ($settings['early_bird_price_per_kid_cents'] ?? 0)) / 100.0;
    // $multi_kid_price_per_kid_dollars = ((int) ($settings['multi_kid_price_per_kid_cents'] ?? 0)) / 100.0;
    // $multi_min = (int) ($settings['multi_kid_min_count'] ?? 2);

    $date = $registration_date ?: date('Y-m-d');

    if ($early_start && $early_end && $early_bird_price_per_kid_dollars > 0 && $date >= $early_start && $date <= $early_end) {
        // Early bird pricing: all kids at early bird price
        $subtotal_dollars = $early_bird_price_per_kid_dollars * $num_kids;
    } else {
        // Regular pricing: all kids at regular price ($70 per child, no multi-child discount)
        $subtotal_dollars = $price_per_kid_dollars * $num_kids;
    }

    return round($subtotal_dollars, 2);
}

function format_money($dollars, $currency = 'usd')
{
    return '$' . number_format((float) $dollars, 2);
}
