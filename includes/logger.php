<?php
/**
 * Application logger.
 *
 * APP_LOG_LEVEL controls verbosity — think of it as a dial:
 *
 *   'high'   → log everything (early production, maximum visibility)   ← start here
 *   'medium' → log medium + low only (routine noise suppressed)
 *   'low'    → log only the most critical events (steady state)        ← move here later
 *
 * Each app_log() call is tagged with the level of detail it represents:
 *   'high'   = verbose / chatty (DB steps, every request, etc.)
 *   'medium' = informational milestones
 *   'low'    = critical-only (errors, payment failures, etc.)
 *
 * A message is written when its level is ≤ APP_LOG_LEVEL.
 * So lowering APP_LOG_LEVEL progressively silences verbose calls.
 *
 * Log file: logs/app.log  (created automatically, never served publicly)
 */

define('APP_LOG_LEVEL', 'high');  // ← lower to 'medium' or 'low' when things are smooth
define('APP_LOG_FILE',  __DIR__ . '/../logs/app.log');

/**
 * Write a structured log entry.
 *
 * @param 'high'|'medium'|'low' $level    Verbosity of this event (high = chatty, low = critical).
 * @param string                $context  Short label: 'DB', 'Stripe', 'Registration', 'Error', …
 * @param string                $message  Human-readable description.
 * @param array                 $data     Optional key→value pairs serialised as JSON on the same line.
 */
function app_log(string $level, string $context, string $message, array $data = []): void
{
    static $order = ['low' => 0, 'medium' => 1, 'high' => 2];

    $max = $order[APP_LOG_LEVEL] ?? 2;
    $cur = $order[$level]        ?? 0;

    if ($cur > $max) {
        return;
    }

    $dir = dirname(APP_LOG_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $line = sprintf(
        "[%s] [%s] [%s] %s%s\n",
        (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s T'),
        str_pad(strtoupper($level),   6),
        str_pad(strtoupper($context), 12),
        $message,
        $data ? ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    file_put_contents(APP_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
