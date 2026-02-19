<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_admin();

$db = (defined('DB_NAME') && DB_NAME !== '') ? '`' . str_replace('`', '``', DB_NAME) . '`.' : '';

$message = '';
$errors = [];
$roles = ['Crew Leader', 'Assistant', 'Crew Member'];

// ─── Helpers ─────────────────────────────────────────────────────────────────

function redirect_groups()
{
  header('Location: ' . APP_URL . '/admin/groups', true, 302);
  exit;
}

// ─── POST handlers ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // CREATE GROUP
  if ($action === 'create_group') {
    $name = trim($_POST['group_name'] ?? '');
    if ($name === '')
      $errors[] = 'Group name is required.';
    if (empty($errors)) {
      global $pdo, $db;
      $stmt = $pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM {$db}groups");
      $next = (int) $stmt->fetch(PDO::FETCH_ASSOC)['next'];
      $pdo->prepare("INSERT INTO {$db}groups (name, sort_order) VALUES (?, ?)")->execute([$name, $next]);
      $message = 'Group created.';
    }
  }

  // RENAME GROUP
  if ($action === 'rename_group') {
    $gid = (int) ($_POST['group_id'] ?? 0);
    $name = trim($_POST['group_name'] ?? '');
    if ($gid < 1)
      $errors[] = 'Invalid group.';
    if ($name === '')
      $errors[] = 'Group name is required.';
    if (empty($errors)) {
      $pdo->prepare("UPDATE {$db}groups SET name = ? WHERE id = ?")->execute([$name, $gid]);
      $message = 'Group renamed.';
    }
  }

  // DELETE GROUP
  if ($action === 'delete_group') {
    $gid = (int) ($_POST['group_id'] ?? 0);
    if ($gid > 0) {
      $pdo->prepare("UPDATE {$db}registration_kids SET group_id = NULL WHERE group_id = ?")->execute([$gid]);
      $pdo->prepare("DELETE FROM {$db}groups WHERE id = ?")->execute([$gid]);
    }
    redirect_groups();
  }

  // ADD VOLUNTEER
  if ($action === 'add_volunteer') {
    global $roles;
    $gid = (int) ($_POST['group_id'] ?? 0);
    $name = trim($_POST['vol_name'] ?? '');
    $email = trim($_POST['vol_email'] ?? '');
    $role = trim($_POST['vol_role'] ?? '');
    if ($gid < 1)
      $errors[] = 'Invalid group.';
    if ($name === '')
      $errors[] = 'Volunteer name is required.';
    if ($email === '')
      $errors[] = 'Volunteer email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
      $errors[] = 'Invalid email address.';
    if (!in_array($role, $roles, true))
      $errors[] = 'Role is required.';
    if (empty($errors)) {
      $pdo->prepare("INSERT INTO {$db}group_volunteers (group_id, name, email, role) VALUES (?, ?, ?, ?)")
        ->execute([$gid, $name, $email, $role]);
      $message = 'Volunteer added.';
    }
  }

  // EDIT VOLUNTEER
  if ($action === 'edit_volunteer') {
    global $roles;
    $vid = (int) ($_POST['vol_id'] ?? 0);
    $name = trim($_POST['vol_name'] ?? '');
    $email = trim($_POST['vol_email'] ?? '');
    $role = trim($_POST['vol_role'] ?? '');
    if ($vid < 1)
      $errors[] = 'Invalid volunteer.';
    if ($name === '')
      $errors[] = 'Volunteer name is required.';
    if ($email === '')
      $errors[] = 'Volunteer email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
      $errors[] = 'Invalid email address.';
    if (!in_array($role, $roles, true))
      $errors[] = 'Role is required.';
    if (empty($errors)) {
      $pdo->prepare("UPDATE {$db}group_volunteers SET name=?, email=?, role=? WHERE id=?")
        ->execute([$name, $email, $role, $vid]);
      $message = 'Volunteer updated.';
    }
  }

  // DELETE VOLUNTEER
  if ($action === 'delete_volunteer') {
    $vid = (int) ($_POST['vol_id'] ?? 0);
    if ($vid > 0) {
      $pdo->prepare("DELETE FROM {$db}group_volunteers WHERE id = ?")->execute([$vid]);
    }
    redirect_groups();
  }
}

// ─── Load data ────────────────────────────────────────────────────────────────

$groups = $pdo->query("SELECT * FROM {$db}groups ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

$volunteers_by_group = [];
if (!empty($groups)) {
  $gids = implode(',', array_map(fn($g) => (int) $g['id'], $groups));
  $vols = $pdo->query("SELECT * FROM {$db}group_volunteers WHERE group_id IN ($gids) ORDER BY role, name")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($vols as $v)
    $volunteers_by_group[(int) $v['group_id']][] = $v;
}

$kid_counts = [];
if (!empty($groups)) {
  $gids = implode(',', array_map(fn($g) => (int) $g['id'], $groups));
  $rows = $pdo->query("SELECT group_id, COUNT(*) AS cnt FROM {$db}registration_kids WHERE group_id IN ($gids) GROUP BY group_id")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r)
    $kid_counts[(int) $r['group_id']] = (int) $r['cnt'];
}

// editing volunteer?
$edit_vol = null;
$edit_gid = 0;
if (isset($_GET['edit_vol'])) {
  $vid = (int) $_GET['edit_vol'];
  $row = $pdo->prepare("SELECT * FROM {$db}group_volunteers WHERE id = ?");
  $row->execute([$vid]);
  $edit_vol = $row->fetch(PDO::FETCH_ASSOC);
  if ($edit_vol)
    $edit_gid = (int) $edit_vol['group_id'];
}

// ─── View ─────────────────────────────────────────────────────────────────────
layout_head('Admin – Groups');
admin_nav('groups');
?>
<div class="max-w-5xl mx-auto px-4 py-6">
  <h1 class="text-2xl font-bold text-gray-900 mb-6">Groups</h1>

  <?php if ($message): ?>
    <div class="mb-4 px-4 py-3 rounded bg-green-50 border border-green-200 text-green-800 text-sm">
      <?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="mb-4 px-4 py-3 rounded bg-red-50 border border-red-200 text-red-800 text-sm">
      <ul class="list-disc list-inside space-y-1"><?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

    <!-- Left: create group + groups list -->
    <div class="md:col-span-1 space-y-4">

      <!-- Create group form -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 mb-3">Create New Group</h2>
        <form method="post" action="">
          <input type="hidden" name="action" value="create_group">
          <div class="mb-3">
            <label class="block text-sm font-medium text-gray-700 mb-1">Group Name <span
                class="text-red-500">*</span></label>
            <input type="text" name="group_name" required class="input-field" placeholder="e.g. Eagles">
          </div>
          <button type="submit" class="btn-primary w-full">Create Group</button>
        </form>
      </div>

      <!-- Groups list -->
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
          <span class="text-sm font-semibold text-gray-700">All Groups</span>
        </div>
        <?php if (empty($groups)): ?>
          <p class="px-4 py-6 text-sm text-gray-500 text-center">No groups yet.</p>
        <?php else: ?>
          <ul class="divide-y divide-gray-100">
            <?php foreach ($groups as $g):
              $gid = (int) $g['id'];
              $kc = $kid_counts[$gid] ?? 0;
              $vc = count($volunteers_by_group[$gid] ?? []);
              ?>
              <li class="px-4 py-3 space-y-2">
                <!-- Display row -->
                <div class="flex items-center justify-between gap-2" id="group-display-<?= $gid ?>">
                  <div class="min-w-0">
                    <div class="font-medium text-gray-900"><?= htmlspecialchars($g['name']) ?></div>
                    <div class="ml-2 text-xs text-gray-500"><?= $kc ?> kids · <?= $vc ?> volunteers</div>
                  </div>
                  <div class="flex items-center gap-2 shrink-0">
                    <button type="button" onclick="toggleRename(<?= $gid ?>)"
                      class="text-xs text-indigo-600 hover:underline">Rename</button>
                    <form method="post" action="" class="inline"
                      onsubmit="return confirm('Delete group «<?= htmlspecialchars(addslashes($g['name'])) ?>»? Kids will be moved to Unassigned.');">
                      <input type="hidden" name="action" value="delete_group">
                      <input type="hidden" name="group_id" value="<?= $gid ?>">
                      <button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
                    </form>
                  </div>
                </div>
                <!-- Rename form (hidden) -->
                <form method="post" action="" id="group-rename-<?= $gid ?>" class="hidden flex gap-2 items-center">
                  <input type="hidden" name="action" value="rename_group">
                  <input type="hidden" name="group_id" value="<?= $gid ?>">
                  <input type="text" name="group_name" required class="input-field text-sm py-1 flex-1"
                    value="<?= htmlspecialchars($g['name']) ?>">
                  <button type="submit" class="btn-primary text-xs py-1 px-2 shrink-0">Save</button>
                  <button type="button" onclick="toggleRename(<?= $gid ?>)"
                    class="btn-secondary text-xs py-1 px-2 shrink-0">Cancel</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: volunteers per group -->
    <div class="md:col-span-2 space-y-6">
      <?php if (empty($groups)): ?>
        <div class="card text-sm text-gray-500 text-center py-10">Create a group first to add volunteers.</div>
      <?php else:
        foreach ($groups as $g):
          $gid = (int) $g['id'];
          $vols = $volunteers_by_group[$gid] ?? [];
          ?>
          <div class="card p-0 overflow-hidden">
            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
              <span class="font-semibold text-gray-900"><?= htmlspecialchars($g['name']) ?></span>
              <button type="button" onclick="toggleAddForm(<?= $gid ?>)" class="btn-secondary text-xs py-1 px-3">+ Add
                Volunteer</button>
            </div>

            <!-- Add volunteer form (hidden by default, shown if this group had an error) -->
            <div id="add-form-<?= $gid ?>"
              class="<?= (!empty($errors) && (int) ($_POST['group_id'] ?? 0) === $gid && ($_POST['action'] ?? '') === 'add_volunteer') ? '' : 'hidden' ?> border-b border-gray-100 bg-indigo-50/40 px-4 py-4">
              <form method="post" action="">
                <input type="hidden" name="action" value="add_volunteer">
                <input type="hidden" name="group_id" value="<?= $gid ?>">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Name <span
                        class="text-red-500">*</span></label>
                    <input type="text" name="vol_name" required class="input-field text-sm py-1.5"
                      value="<?= htmlspecialchars((!empty($errors) && (int) ($_POST['group_id'] ?? 0) === $gid) ? ($_POST['vol_name'] ?? '') : '') ?>"
                      placeholder="Full name">
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Email <span
                        class="text-red-500">*</span></label>
                    <input type="email" name="vol_email" required class="input-field text-sm py-1.5"
                      value="<?= htmlspecialchars((!empty($errors) && (int) ($_POST['group_id'] ?? 0) === $gid) ? ($_POST['vol_email'] ?? '') : '') ?>"
                      placeholder="email@example.com">
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Role <span
                        class="text-red-500">*</span></label>
                    <select name="vol_role" required class="input-field text-sm py-1.5">
                      <option value="">— Select —</option>
                      <?php foreach ($roles as $r): ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= ((!empty($errors) && (int) ($_POST['group_id'] ?? 0) === $gid) && ($_POST['vol_role'] ?? '') === $r) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($r) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="flex gap-2">
                  <button type="submit" class="btn-primary text-sm py-1.5">Add</button>
                  <button type="button" onclick="toggleAddForm(<?= $gid ?>)"
                    class="btn-secondary text-sm py-1.5">Cancel</button>
                </div>
              </form>
            </div>

            <!-- Edit volunteer form (shown when ?edit_vol=X and group matches) -->
            <?php if ($edit_vol && $edit_gid === $gid): ?>
              <div class="border-b border-gray-100 bg-amber-50/40 px-4 py-4">
                <p class="text-xs font-semibold text-amber-700 mb-2">Editing volunteer</p>
                <form method="post" action="">
                  <input type="hidden" name="action" value="edit_volunteer">
                  <input type="hidden" name="vol_id" value="<?= (int) $edit_vol['id'] ?>">
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                    <div>
                      <label class="block text-xs font-medium text-gray-700 mb-1">Name <span
                          class="text-red-500">*</span></label>
                      <input type="text" name="vol_name" required class="input-field text-sm py-1.5"
                        value="<?= htmlspecialchars((!empty($errors) ? ($_POST['vol_name'] ?? '') : $edit_vol['name'])) ?>">
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-700 mb-1">Email <span
                          class="text-red-500">*</span></label>
                      <input type="email" name="vol_email" required class="input-field text-sm py-1.5"
                        value="<?= htmlspecialchars((!empty($errors) ? ($_POST['vol_email'] ?? '') : $edit_vol['email'])) ?>">
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-700 mb-1">Role <span
                          class="text-red-500">*</span></label>
                      <select name="vol_role" required class="input-field text-sm py-1.5">
                        <option value="">— Select —</option>
                        <?php foreach ($roles as $r): ?>
                          <option value="<?= htmlspecialchars($r) ?>" <?= ((!empty($errors) ? ($_POST['vol_role'] ?? '') : $edit_vol['role']) === $r) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="flex gap-2">
                    <button type="submit" class="btn-primary text-sm py-1.5">Save</button>
                    <a href="<?= APP_URL ?>/admin/groups" class="btn-secondary text-sm py-1.5">Cancel</a>
                  </div>
                </form>
              </div>
            <?php endif; ?>

            <!-- Volunteers table -->
            <?php if (empty($vols)): ?>
              <p class="px-4 py-4 text-sm text-gray-400 italic">No volunteers yet.</p>
            <?php else: ?>
              <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 border-b border-gray-100">
                  <tr>
                    <th class="px-4 py-2 font-medium text-gray-600">Name</th>
                    <th class="px-4 py-2 font-medium text-gray-600">Email</th>
                    <th class="px-4 py-2 font-medium text-gray-600">Role</th>
                    <th class="px-4 py-2"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($vols as $v): ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50/50">
                      <td class="px-4 py-2 font-medium text-gray-900"><?= htmlspecialchars($v['name']) ?></td>
                      <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($v['email']) ?></td>
                      <td class="px-4 py-2">
                        <?php
                        $role_colors = [
                          'Crew Leader' => 'bg-indigo-100 text-indigo-700',
                          'Assistant' => 'bg-amber-100 text-amber-700',
                          'Crew Member' => 'bg-gray-100 text-gray-700',
                        ];
                        $rc = $role_colors[$v['role']] ?? 'bg-gray-100 text-gray-700';
                        ?>
                        <span
                          class="px-2 py-0.5 rounded text-xs font-medium <?= $rc ?>"><?= htmlspecialchars($v['role']) ?></span>
                      </td>
                      <td class="px-4 py-2 text-right whitespace-nowrap">
                        <a href="<?= APP_URL ?>/admin/groups?edit_vol=<?= (int) $v['id'] ?>"
                          class="text-xs text-indigo-600 hover:underline mr-3">Edit</a>
                        <form method="post" action="" class="inline"
                          onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($v['name'])) ?>?');">
                          <input type="hidden" name="action" value="delete_volunteer">
                          <input type="hidden" name="vol_id" value="<?= (int) $v['id'] ?>">
                          <button type="submit" class="text-xs text-red-600 hover:underline">Remove</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
  function toggleAddForm(gid) {
    var el = document.getElementById('add-form-' + gid);
    if (el) el.classList.toggle('hidden');
  }
  function toggleRename(gid) {
    var form = document.getElementById('group-rename-' + gid);
    if (form) form.classList.toggle('hidden');
  }
</script>

<?php layout_footer(); ?>
</body>

</html>