<?php
/**
 * Database helpers shared across the application.
 * All SQL lives here; callers contain no query strings.
 */

require_once __DIR__ . '/logger.php';

// ─── Shared DB prefix ─────────────────────────────────────────────────────────

/**
 * Returns the backtick-quoted DB name prefix (e.g. "`mydb`.") or '' if not set.
 * Use inside every query: "SELECT * FROM {db_prefix()}table"
 */
function db_prefix(): string
{
    return (defined('DB_NAME') && DB_NAME !== '')
        ? '`' . str_replace('`', '``', DB_NAME) . '`.'
        : '';
}

// ─── Admin: Registrations list (admin/registrations.php) ──────────────────────

/**
 * Return registrations with kid count, optionally filtered by status.
 *
 * Sort is controlled by a whitelisted key + direction so no raw SQL ever
 * travels from the caller into the query.
 *
 * @param string $sort_key  One of: 'parent', 'email', 'kids', 'photo', 'status', 'date'.
 *                          Unknown keys fall back to 'date'.
 * @param string $dir       'asc' or 'desc'. Anything else falls back to 'desc'.
 */
function admin_get_registrations(
    PDO    $pdo,
    string $status_filter,
    string $sort_key = 'date',
    string $dir      = 'desc'
): array {
    // Sanitise direction — only two valid values exist.
    $dir = ($dir === 'asc') ? 'asc' : 'desc';

    // Whitelist: sort key → SQL column expression ({dir} is substituted below).
    $sort_map = [
        'parent' => 'r.parent_last_name {dir}, r.parent_first_name {dir}',
        'email'  => 'r.email {dir}',
        'kids'   => 'kid_count {dir}',
        'photo'  => 'r.photo_consent {dir}',
        'status' => 'r.status {dir}',
        'date'   => 'r.created_at {dir}',
    ];
    $template  = $sort_map[$sort_key] ?? $sort_map['date'];
    $order_sql = str_replace('{dir}', $dir, $template);

    $db = db_prefix();
    $q  = "SELECT r.*, (SELECT COUNT(*) FROM {$db}registration_kids k 
                         JOIN {$db}registrations r2 ON r2.id = k.registration_id AND r2.status = 'paid'
                         WHERE k.registration_id = r.id) AS kid_count
           FROM {$db}registrations r
           WHERE r.status = 'paid'";
    $params = [];
    $q .= ' ORDER BY ' . $order_sql;
    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Return all kids joined with their registration and group, optionally filtered by status.
 */
function admin_get_kids_list(PDO $pdo, string $status_filter): array
{
    $db = db_prefix();
    $q  = "SELECT k.*, r.parent_first_name, r.parent_last_name, r.status AS reg_status, g.name AS group_name
           FROM {$db}registration_kids k
           JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'
           LEFT JOIN {$db}groups g ON g.id = k.group_id";
    $params = [];
    $q .= ' ORDER BY k.last_name, k.first_name';
    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── Admin: Registration detail (admin/registration-view.php) ─────────────────

/**
 * Return a single registration row by ID, or null if not found.
 */
function admin_get_registration(PDO $pdo, int $id): ?array
{
    $db   = db_prefix();
    $stmt = $pdo->prepare("SELECT * FROM {$db}registrations WHERE id = ? AND status = 'paid'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Return all kids for a registration, ordered by sort_order.
 */
function admin_get_registration_kids(PDO $pdo, int $id): array
{
    $db   = db_prefix();
    $stmt = $pdo->prepare("SELECT k.* FROM {$db}registration_kids k
                            JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'
                            WHERE k.registration_id = ? ORDER BY k.sort_order, k.id");
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── Admin: Settings (admin/settings.php) ─────────────────────────────────────

/**
 * Upsert an array of key → value pairs into the settings table.
 */
function admin_save_settings(PDO $pdo, array $values): void
{
    $db   = db_prefix();
    $stmt = $pdo->prepare(
        "INSERT INTO {$db}settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    foreach ($values as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

// ─── Admin: Groups (admin/groups.php) ─────────────────────────────────────────

function groups_create(PDO $pdo, string $name): void
{
    $db   = db_prefix();
    $next = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM {$db}groups")
                       ->fetch(PDO::FETCH_ASSOC)['next'];
    $pdo->prepare("INSERT INTO {$db}groups (name, sort_order) VALUES (?, ?)")->execute([$name, $next]);
}

function groups_rename(PDO $pdo, int $gid, string $name): void
{
    $db = db_prefix();
    $pdo->prepare("UPDATE {$db}groups SET name = ? WHERE id = ?")->execute([$name, $gid]);
}

function groups_delete(PDO $pdo, int $gid): void
{
    $db = db_prefix();
    $pdo->prepare("UPDATE {$db}registration_kids SET group_id = NULL WHERE group_id = ?")->execute([$gid]);
    $pdo->prepare("DELETE FROM {$db}groups WHERE id = ?")->execute([$gid]);
}

function groups_add_volunteer(PDO $pdo, int $gid, string $name, string $email, string $role): void
{
    $db = db_prefix();
    $pdo->prepare("INSERT INTO {$db}group_volunteers (group_id, name, email, role) VALUES (?, ?, ?, ?)")
        ->execute([$gid, $name, $email, $role]);
}

function groups_edit_volunteer(PDO $pdo, int $vid, string $name, string $email, string $role): void
{
    $db = db_prefix();
    $pdo->prepare("UPDATE {$db}group_volunteers SET name=?, email=?, role=? WHERE id=?")
        ->execute([$name, $email, $role, $vid]);
}

function groups_delete_volunteer(PDO $pdo, int $vid): void
{
    $db = db_prefix();
    $pdo->prepare("DELETE FROM {$db}group_volunteers WHERE id = ?")->execute([$vid]);
}

function groups_get_all(PDO $pdo): array
{
    $db = db_prefix();
    return $pdo->query("SELECT * FROM {$db}groups ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
}

/** Returns volunteers keyed by group_id. */
function groups_get_volunteers_by_group(PDO $pdo, array $group_ids): array
{
    if (empty($group_ids)) return [];
    $db   = db_prefix();
    $in   = implode(',', array_map('intval', $group_ids));
    $vols = $pdo->query("SELECT * FROM {$db}group_volunteers WHERE group_id IN ($in) ORDER BY role, name")
                ->fetchAll(PDO::FETCH_ASSOC);
    $map  = [];
    foreach ($vols as $v) {
        $map[(int) $v['group_id']][] = $v;
    }
    return $map;
}

/** Returns kid count keyed by group_id. */
function groups_get_kid_counts(PDO $pdo, array $group_ids): array
{
    if (empty($group_ids)) return [];
    $db   = db_prefix();
    $in   = implode(',', array_map('intval', $group_ids));
    $rows = $pdo->query("SELECT k.group_id, COUNT(*) AS cnt 
                         FROM {$db}registration_kids k
                         JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'
                         WHERE k.group_id IN ($in) GROUP BY k.group_id")
                ->fetchAll(PDO::FETCH_ASSOC);
    $map  = [];
    foreach ($rows as $r) {
        $map[(int) $r['group_id']] = (int) $r['cnt'];
    }
    return $map;
}

function groups_get_volunteer(PDO $pdo, int $vid): ?array
{
    $db   = db_prefix();
    $stmt = $pdo->prepare("SELECT * FROM {$db}group_volunteers WHERE id = ?");
    $stmt->execute([$vid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─── Success page / Stripe webhook ────────────────────────────────────────────

/**
 * Atomically finalize a paid registration and claim the right to send the
 * confirmation email.
 *
 * Uses SELECT … FOR UPDATE so that success.php and stripe-webhook.php cannot
 * both claim the email send — exactly one caller gets the registration data
 * back, the other gets null.
 *
 * Behaviour:
 *  - Sets status = 'paid' and stripe_session_id (idempotent; safe to call even
 *    if the webhook already flipped the status).
 *  - If confirmation_email_sent is already 1  → commits and returns null.
 *  - Otherwise sets confirmation_email_sent = 1, commits, and returns the full
 *    registration row with a nested 'kids' array for email-building.
 *
 * @return array|null  Registration data when the caller should send the email;
 *                     null when the email was already claimed by another process.
 */
function registration_finalize_payment(PDO $pdo, int $reg_id, string $session_id): ?array
{
    $db  = db_prefix();
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // Lock the row for the duration of this transaction
        $stmt = $pdo->prepare(
            "SELECT id, status, confirmation_email_sent
             FROM {$db}registrations WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$reg_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            app_log('high', 'Payment', 'registration_finalize_payment: registration not found', [
                'reg_id' => $reg_id,
            ]);
            return null;
        }

        // Mark as paid (idempotent — no-op if already paid)
        if ($row['status'] !== 'paid') {
            $pdo->prepare(
                "UPDATE {$db}registrations
                 SET status = 'paid', stripe_session_id = ?, updated_at = ?
                 WHERE id = ?"
            )->execute([$session_id, $now, $reg_id]);
        }

        // Check whether another process already claimed the email send
        if ((int) $row['confirmation_email_sent'] === 1) {
            $pdo->commit();
            app_log('high', 'Payment', 'registration_finalize_payment: email already claimed', [
                'reg_id' => $reg_id,
            ]);
            return null;
        }

        // Claim the email send right
        $pdo->prepare(
            "UPDATE {$db}registrations SET confirmation_email_sent = 1 WHERE id = ?"
        )->execute([$reg_id]);

        $pdo->commit();

        app_log('high', 'Payment', 'registration_finalize_payment: email claimed', [
            'reg_id' => $reg_id,
        ]);

        return success_get_registration_with_kids($pdo, $reg_id);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        app_log('high', 'Payment', 'registration_finalize_payment: exception', [
            'reg_id' => $reg_id,
            'error'  => $e->getMessage(),
        ]);
        return null;
    }
}

/**
 * Return a registration with its kids array, or null if not found.
 */
function success_get_registration_with_kids(PDO $pdo, int $reg_id): ?array
{
    $db   = db_prefix();
    $stmt = $pdo->prepare("SELECT * FROM {$db}registrations WHERE id = ?");
    $stmt->execute([$reg_id]);
    $reg  = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reg) return null;
    $stmt = $pdo->prepare("SELECT * FROM {$db}registration_kids WHERE registration_id = ? ORDER BY sort_order");
    $stmt->execute([$reg_id]);
    $reg['kids'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $reg;
}

// ─── Registration (register.php) ──────────────────────────────────────────────

/**
 * Insert or update a draft registration and its kids inside a single transaction.
 *
 * If $existing_draft_id is non-null and a matching draft row still exists,
 * that row is updated and its kids are replaced; otherwise a fresh row is
 * inserted.  Returns the final registration_id.
 *
 * @param int|null $existing_draft_id  ID of an existing draft to reuse, or null.
 * @param array    $wanted             Column → value map for the registrations table.
 *                                     Columns absent from the live schema are skipped.
 * @param array    $kid_rows           Each element has keys: first_name, last_name,
 *                                     age, gender, date_of_birth, last_grade_completed,
 *                                     t_shirt_size, medical_allergy_info.
 * @throws Exception on DB error
 */
function reg_save_draft(PDO $pdo, ?int $existing_draft_id, array $wanted, array $kid_rows): int
{
    app_log('high', 'DB', 'reg_save_draft: start', [
        'existing_draft_id' => $existing_draft_id,
        'kid_count'         => count($kid_rows),
        'email'             => $wanted['email'] ?? null,
    ]);

    $reg_columns = $pdo
        ->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations'")
        ->fetchAll(PDO::FETCH_COLUMN);

    $kid_columns_all = $pdo
        ->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_kids'")
        ->fetchAll(PDO::FETCH_COLUMN);

    app_log('high', 'DB', 'reg_save_draft: schema introspected', [
        'reg_columns'  => count($reg_columns),
        'kid_columns'  => count($kid_columns_all),
    ]);

    $pdo->beginTransaction();
    app_log('high', 'DB', 'reg_save_draft: transaction started');

    try {
        $registration_id = null;

        if ($existing_draft_id && $existing_draft_id > 0) {
            $chk = $pdo->prepare("SELECT id FROM registrations WHERE id = ? AND status = 'draft'");
            $chk->execute([$existing_draft_id]);
            if ($chk->fetch()) {
                $registration_id = $existing_draft_id;
                app_log('high', 'DB', 'reg_save_draft: reusing existing draft', ['registration_id' => $registration_id]);

                $update_cols = [];
                $update_vals = [];
                foreach ($wanted as $col => $val) {
                    if (in_array($col, $reg_columns, true) && $col !== 'created_at') {
                        $update_cols[] = '`' . $col . '` = ?';
                        $update_vals[] = $val;
                    }
                }
                $update_vals[] = $registration_id;
                $pdo->prepare('UPDATE registrations SET ' . implode(', ', $update_cols) . ' WHERE id = ?')
                    ->execute($update_vals);
                app_log('high', 'DB', 'reg_save_draft: registrations row updated', ['registration_id' => $registration_id]);

                $pdo->prepare("DELETE FROM registration_kids WHERE registration_id = ?")
                    ->execute([$registration_id]);
                app_log('high', 'DB', 'reg_save_draft: old kids deleted', ['registration_id' => $registration_id]);
            } else {
                app_log('high', 'DB', 'reg_save_draft: draft not found, will insert new', ['existing_draft_id' => $existing_draft_id]);
            }
        }

        if (!$registration_id) {
            $cols = [];
            $vals = [];
            foreach ($wanted as $col => $val) {
                if (in_array($col, $reg_columns, true)) {
                    $cols[] = '`' . $col . '`';
                    $vals[] = $val;
                }
            }
            $pdo->prepare(
                'INSERT INTO registrations (' . implode(',', $cols) . ') VALUES (' .
                implode(',', array_fill(0, count($cols), '?')) . ')'
            )->execute($vals);
            $registration_id = (int) $pdo->lastInsertId();
            app_log('high', 'DB', 'reg_save_draft: new registration inserted', ['registration_id' => $registration_id]);
        }

        $kid_cols_wanted = [
            'registration_id', 'first_name', 'last_name', 'age', 'gender',
            'date_of_birth', 'last_grade_completed', 't_shirt_size',
            'medical_allergy_info', 'sort_order',
        ];
        $kid_cols = array_values(array_filter(
            $kid_cols_wanted,
            fn($c) => in_array($c, $kid_columns_all, true)
        ));
        $kid_stmt = $pdo->prepare(
            'INSERT INTO registration_kids (`' . implode('`,`', $kid_cols) . '`) VALUES (' .
            implode(',', array_fill(0, count($kid_cols), '?')) . ')'
        );
        foreach ($kid_rows as $i => $k) {
            $kid_vals = [];
            foreach ($kid_cols as $c) {
                switch ($c) {
                    case 'registration_id':      $kid_vals[] = $registration_id;            break;
                    case 'first_name':           $kid_vals[] = $k['first_name'];            break;
                    case 'last_name':            $kid_vals[] = $k['last_name'];             break;
                    case 'age':
                        $age = $k['age'] ?? null;
                        if ($age !== null && $age !== '') {
                            $age = (int) $age;
                            $age = max(0, min(18, $age));
                        } else {
                            $age = null;
                        }
                        $kid_vals[] = $age;
                        break;
                    case 'gender':               $kid_vals[] = $k['gender'];                break;
                    case 'date_of_birth':        $kid_vals[] = $k['date_of_birth'] ?? null; break;
                    case 'last_grade_completed': $kid_vals[] = $k['last_grade_completed'] ?? null; break;
                    case 't_shirt_size':         $kid_vals[] = $k['t_shirt_size'] ?? null;  break;
                    case 'medical_allergy_info': $kid_vals[] = $k['medical_allergy_info'] ?? null; break;
                    case 'sort_order':           $kid_vals[] = $i;                          break;
                }
            }
            if (!empty($kid_vals)) {
                $kid_stmt->execute($kid_vals);
            }
        }
        app_log('high', 'DB', 'reg_save_draft: kids inserted', [
            'registration_id' => $registration_id,
            'kid_count'       => count($kid_rows),
        ]);

        $pdo->commit();
        app_log('high', 'DB', 'reg_save_draft: transaction committed', ['registration_id' => $registration_id]);
        return $registration_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        app_log('high', 'Error', 'reg_save_draft: transaction rolled back', [
            'error'             => $e->getMessage(),
            'existing_draft_id' => $existing_draft_id,
        ]);
        throw $e;
    }
}

// ─── Assign-groups helpers (admin/assigngroups.php) ───────────────────────────

/**
 * Database helpers for admin/assigngroups.php.
 * Every function receives $pdo and $db (the optional DB-name prefix string)
 * as its first two arguments.
 */

// ─── Reads ────────────────────────────────────────────────────────────────────

/**
 * Return volunteers keyed by group_id, ordered Crew Leader → Assistant → Crew Member.
 *
 * @return array<int, array[]>  group_id => [ ['name'=>…, 'role'=>…], … ]
 */
function ag_get_volunteers_by_group(PDO $pdo, string $db): array
{
    $rows = $pdo->query("
        SELECT group_id, name, role
        FROM {$db}group_volunteers
        ORDER BY
            FIELD(role, 'Crew Leader', 'Assistant', 'Crew Member'),
            name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['group_id']][] = $r;
    }
    return $map;
}

/**
 * Return all groups ordered by sort_order.
 *
 * @return array<int, array{id: int, name: string, sort_order: int}>
 */
function ag_get_groups(PDO $pdo, string $db): array
{
    return $pdo
        ->query("SELECT id, name, sort_order FROM {$db}groups ORDER BY sort_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Return all kids joined with their registration (home_church),
 * ordered for display on the assign-groups board.
 *
 * @return array<int, array>
 */
function ag_get_kids(PDO $pdo, string $db): array
{
    return $pdo->query("
        SELECT k.id, k.first_name, k.last_name, k.age, k.gender,
               k.last_grade_completed, k.date_of_birth, k.group_id,
               k.registration_id, k.t_shirt_size, k.medical_allergy_info,
               r.home_church
        FROM {$db}registration_kids k
        JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'
        ORDER BY k.age, k.date_of_birth, k.last_grade_completed,
                 k.last_name, k.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ─── Writes ───────────────────────────────────────────────────────────────────

/**
 * Save an array of kid-id → group-id assignments in a single transaction.
 * Kids not present in $assignments are set to NULL (unassigned).
 *
 * @param int[]      $valid_kid_ids   All known kid IDs (used as the update set)
 * @param array      $assignments     kid_id => group_id|null
 * @throws Exception on DB error
 */
function ag_save_assignments(
    PDO    $pdo,
    string $db,
    array  $valid_kid_ids,
    array  $assignments
): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE {$db}registration_kids SET group_id = ? WHERE id = ?"
        );
        foreach ($valid_kid_ids as $kid_id) {
            $gid = isset($assignments[$kid_id]) ? $assignments[$kid_id] : null;
            $stmt->execute([$gid ?: null, $kid_id]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Auto-assign every kid to a group by grade.
 * One grade per group, matched in the order defined by $grade_order.
 * Kids whose grade doesn't map to any group are left unassigned.
 *
 * @param array  $grade_order    Ordered list of canonical grade strings
 * @param array  $grade_aliases  Raw-grade → canonical-grade map
 * @throws Exception on DB error
 */
function ag_auto_assign_by_grade(
    PDO    $pdo,
    string $db,
    array  $grade_order,
    array  $grade_aliases
): void {
    $groups_list = $pdo
        ->query("SELECT id, sort_order FROM {$db}groups ORDER BY sort_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);

    $kids_list = $pdo
        ->query("SELECT k.id, k.last_grade_completed 
                 FROM {$db}registration_kids k
                 JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'")
        ->fetchAll(PDO::FETCH_ASSOC);

    // Append any grades found in the DB that aren't in the predefined order
    $db_grades = array_unique(array_filter(array_map(
        fn($k) => isset($k['last_grade_completed']) && $k['last_grade_completed'] !== ''
            ? trim($k['last_grade_completed'])
            : null,
        $kids_list
    )));
    $extra = [];
    foreach ($db_grades as $g) {
        $canonical = $grade_aliases[$g] ?? $g;
        if (!in_array($canonical, $grade_order, true)) {
            $extra[] = $canonical;
        }
    }
    sort($extra, SORT_STRING);
    $grade_order    = array_values(array_merge($grade_order, $extra));
    $grade_to_index = array_flip($grade_order);
    $num_groups     = count($groups_list);

    $pdo->beginTransaction();
    try {
        // Clear group_id only for paid kids
        $pdo->exec("UPDATE {$db}registration_kids k
                    JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'
                    SET k.group_id = NULL");

        if ($num_groups > 0) {
            $stmt = $pdo->prepare(
                "UPDATE {$db}registration_kids k
                 JOIN {$db}registrations r ON r.id = k.registration_id AND r.status = 'paid'
                 SET k.group_id = ? WHERE k.id = ?"
            );
            foreach ($kids_list as $k) {
                $grade_raw = isset($k['last_grade_completed']) && $k['last_grade_completed'] !== ''
                    ? trim($k['last_grade_completed'])
                    : null;
                $grade = ($grade_raw !== null && isset($grade_aliases[$grade_raw]))
                    ? $grade_aliases[$grade_raw]
                    : $grade_raw;

                $gid = null;
                if ($grade !== null && isset($grade_to_index[$grade])) {
                    $idx = $grade_to_index[$grade];
                    if ($idx < $num_groups) {
                        $gid = (int) $groups_list[$idx]['id'];
                    }
                }
                $stmt->execute([$gid, $k['id']]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
