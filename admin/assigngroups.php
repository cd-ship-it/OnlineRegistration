<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_admin();

const UNASSIGNED_GROUP_ID = 0;

// Grade → light background color (hex). Add or change grades here. Default used when grade not in map.
$grade_colors = [
  'Preschool' => '#fce7f3',  // pink-100
  'PreK'      => '#e0f2fe',  // sky-100
  'K'         => '#fef3c7',  // amber-100
  '1st'       => '#dbeafe',  // blue-100
  '2nd'     => '#d1fae5',  // emerald-100
  '3rd'     => '#e9d5ff',  // violet-100
  '4th'     => '#fce7f3',  // pink-100
  '5th'     => '#fed7aa',  // orange-100
  '6th'     => '#e0e7ff',  // indigo-100
];
$grade_color_default = '#f3f4f6'; // gray-100

$message = '';
$errors = [];
$groups_count = (int) get_setting($pdo, 'groups_count', 8);
if ($groups_count < 1) $groups_count = 8;

// POST: Add new group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_group') {
  $stmt = $pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM groups");
  $next = (int) $stmt->fetch(PDO::FETCH_ASSOC)['next_order'];
  $pdo->prepare("INSERT INTO groups (name, sort_order) VALUES (?, ?)")->execute(['New Group', $next]);
  header('Location: ' . APP_URL . '/admin/assigngroups', true, 302);
  exit;
}

// POST: Remove group (move assigned children to unassigned, then delete group)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_group') {
  $gid = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;
  if ($gid > 0) {
    $pdo->prepare("UPDATE registration_kids SET group_id = NULL WHERE group_id = ?")->execute([$gid]);
    $pdo->prepare("DELETE FROM groups WHERE id = ?")->execute([$gid]);
  }
  header('Location: ' . APP_URL . '/admin/assigngroups', true, 302);
  exit;
}

// Load all kids from registration_kids (age, grade, birthday for display)
$stmt = $pdo->query("
  SELECT k.id, k.first_name, k.last_name, k.age, k.last_grade_completed, k.date_of_birth, k.group_id, k.registration_id
  FROM registration_kids k
  JOIN registrations r ON r.id = k.registration_id
  ORDER BY k.age,k.date_of_birth, k.last_grade_completed,  k.last_name, k.first_name
");
$kids = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load groups and ensure we have at least groups_count
$stmt = $pdo->query("SELECT id, name, sort_order FROM groups ORDER BY sort_order, id");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$existing = count($groups);
if ($existing < $groups_count) {
  for ($i = $existing; $i < $groups_count; $i++) {
    $pdo->prepare("INSERT INTO groups (name, sort_order) VALUES (?, ?)")->execute(['Group ' . ($i + 1), $i]);
  }
  $stmt = $pdo->query("SELECT id, name, sort_order FROM groups ORDER BY sort_order, id");
  $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Partition kids: unassigned vs by group
$unassigned = [];
$by_group = [];
foreach ($groups as $g) {
  $by_group[(int) $g['id']] = [];
}
foreach ($kids as $k) {
  $gid = isset($k['group_id']) ? (int) $k['group_id'] : null;
  if ($gid === null || $gid === 0) {
    $unassigned[] = $k;
  } else {
    if (isset($by_group[$gid])) {
      $by_group[$gid][] = $k;
    } else {
      $unassigned[] = $k;
    }
  }
}
// Unique ages and grades for filter dropdowns (UI-level filter)
$unique_ages = array_unique(array_filter(array_map(function ($k) { return isset($k['age']) && $k['age'] !== '' && $k['age'] !== null ? (int) $k['age'] : null; }, $kids)));
$unique_grades = array_unique(array_filter(array_map(function ($k) { return isset($k['last_grade_completed']) && $k['last_grade_completed'] !== '' ? $k['last_grade_completed'] : null; }, $kids)));
sort($unique_ages, SORT_NUMERIC);
sort($unique_grades, SORT_STRING);

// POST: save assignments and group names
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $assignments_raw = $_POST['assignments'] ?? [];
  $group_names_raw = $_POST['group_names'] ?? [];
  $assignments = [];
  if (is_string($assignments_raw)) {
    $decoded = json_decode($assignments_raw, true);
    if (is_array($decoded)) $assignments = $decoded;
  } elseif (is_array($assignments_raw)) {
    foreach ($assignments_raw as $kid_id => $group_id) {
      $assignments[(int) $kid_id] = $group_id === '' || $group_id === null ? null : (int) $group_id;
    }
  }
  $group_names = [];
  if (is_array($group_names_raw)) {
    foreach ($group_names_raw as $gid => $name) {
      $group_names[(int) $gid] = trim($name);
    }
  }

  $valid_kid_ids = array_column($kids, 'id');
  $valid_group_ids = array_column($groups, 'id');
  foreach (array_keys($assignments) as $kid_id) {
    if (!in_array($kid_id, $valid_kid_ids, true)) {
      $errors[] = "Invalid kid id: $kid_id";
      break;
    }
  }
  foreach ($assignments as $kid_id => $group_id) {
    if ($group_id !== null && !in_array($group_id, $valid_group_ids, true)) {
      $errors[] = "Invalid group id: $group_id for kid $kid_id";
      break;
    }
  }

  if (empty($errors)) {
    try {
      $pdo->beginTransaction();
      foreach ($group_names as $gid => $name) {
        if (!in_array($gid, $valid_group_ids, true)) continue;
        $pdo->prepare("UPDATE groups SET name = ? WHERE id = ?")->execute([$name ?: "Group $gid", $gid]);
      }
      $stmt_update = $pdo->prepare("UPDATE registration_kids SET group_id = ? WHERE id = ?");
      foreach ($valid_kid_ids as $kid_id) {
        $gid = isset($assignments[$kid_id]) ? $assignments[$kid_id] : null;
        $stmt_update->execute([$gid ?: null, $kid_id]);
      }
      $pdo->commit();
      header('Location: ' . APP_URL . '/admin/assigngroups?saved=1', true, 302);
      exit;
    } catch (Exception $e) {
      $pdo->rollBack();
      $errors[] = 'Save failed: ' . $e->getMessage();
    }
  }
}

$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
layout_head('Admin – Assign Groups');
?>

<div class="max-w-7xl mx-auto px-4 py-8">
  <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
    <div class="flex items-center gap-3">
      <h1 class="text-2xl font-bold text-gray-900">Assign Groups</h1>
      <button type="button" class="btn-primary" onclick="document.getElementById('assign-form').requestSubmit()">Save Assignments</button>
    </div>
    <nav class="flex gap-4 items-center">
      <a href="<?= APP_URL ?>/admin/registrations" class="text-indigo-600 hover:underline">Registrations</a>
      <a href="<?= APP_URL ?>/admin/settings" class="text-indigo-600 hover:underline">Settings</a>
      <a href="<?= APP_URL ?>/admin/logout" class="text-gray-600 hover:underline">Logout</a>
    </nav>
  </div>

  <?php if ($saved): ?>
  <div class="card border-green-200 bg-green-50 text-green-800 mb-6">Assignments saved.</div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
  <ul class="list-disc list-inside text-red-600 text-sm mb-6">
    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  

  <div class="card mb-6">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Filter children</h3>
    <div class="flex flex-wrap gap-4 items-end">
      <div class="flex-1 min-w-[160px]">
        <label for="filter-name" class="block text-xs font-medium text-gray-500 mb-1">Name</label>
        <input type="text" id="filter-name" placeholder="Search by name..." class="input-field py-1.5 text-sm" autocomplete="off">
      </div>
      <div class="w-24">
        <label for="filter-age" class="block text-xs font-medium text-gray-500 mb-1">Age</label>
        <select id="filter-age" class="input-field py-1.5 text-sm">
          <option value="">All</option>
          <?php foreach ($unique_ages as $a): ?><option value="<?= (int) $a ?>"><?= (int) $a ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="w-32">
        <label for="filter-grade" class="block text-xs font-medium text-gray-500 mb-1">Grade</label>
        <select id="filter-grade" class="input-field py-1.5 text-sm">
          <option value="">All</option>
          <?php foreach ($unique_grades as $gr): ?><option value="<?= htmlspecialchars($gr) ?>"><?= htmlspecialchars($gr) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button type="button" id="filter-clear" class="btn-secondary text-sm py-1.5">Clear</button>
    </div>
  </div>

  <form id="assign-form" method="post" action="" class="space-y-6">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <!-- Left: Children (Unassigned) -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Unassigned</h2>
        <p class="text-sm text-gray-500 mb-3">Drag children here or into a group.</p>
        <div id="list-unassigned" class="min-h-[120px] space-y-2 rounded-lg border-2 border-dashed border-gray-200 bg-gray-50/50 p-3" data-group-id="0">
          <?php foreach ($unassigned as $k):
            $age = $k['age'] !== null && $k['age'] !== '' ? (int) $k['age'] : null;
            $grade = isset($k['last_grade_completed']) && $k['last_grade_completed'] !== '' ? $k['last_grade_completed'] : null;
            $dob = null;
            if (!empty($k['date_of_birth'])) { $ts = strtotime($k['date_of_birth']); $dob = ($ts !== false) ? date('m/d/Y', $ts) : null; }
            $parts_full = array_filter([
              $age !== null ? "Age: $age" : null,
              $grade !== null ? "Grade in Fall: " . $grade : null,
              $dob !== null ? "BD: " . $dob : null
            ]);
            $parts_short = array_filter([ $age !== null ? (string) $age : null, $grade ]);
            $meta_full = implode('. ', $parts_full);
            $meta_short = implode('. ', $parts_short);
            $meta_dob = $dob !== null ? $dob : '';
          ?>
          <?php
            $kid_name_lc = strtolower(trim($k['first_name'] . ' ' . $k['last_name']));
            $kid_age = $age !== null ? (string) $age : '';
            $kid_grade = $grade !== null ? $grade : '';
            $card_bg = (isset($grade) && $grade !== null && isset($grade_colors[$grade])) ? $grade_colors[$grade] : $grade_color_default;
          ?>
          <div class="kid-card flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 shadow-sm cursor-grab active:cursor-grabbing" style="font-size: 70%; background-color: <?= htmlspecialchars($card_bg) ?>;" data-kid-id="<?= (int) $k['id'] ?>" data-name="<?= htmlspecialchars($kid_name_lc) ?>" data-age="<?= htmlspecialchars($kid_age) ?>" data-grade="<?= htmlspecialchars($kid_grade) ?>" data-meta-full="<?= htmlspecialchars($meta_full) ?>" data-meta-short="<?= htmlspecialchars($meta_short) ?>" data-meta-dob="<?= htmlspecialchars($meta_dob) ?>">
            <span class="font-bold text-gray-900"><?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?></span>
            <div class="shrink-0 text-right">
              <span class="kid-meta text-xs text-gray-500"><?= $meta_full !== '' ? htmlspecialchars($meta_full) : '' ?></span>
              <span class="kid-meta-dob text-xs text-gray-500 block" style="display: none;"></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Right: Groups (two per row) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <button type="button" id="add-group-btn" class="btn-secondary text-sm">Add new group</button>
        </div>
        <?php foreach ($groups as $g):
          $group_child_count = count($by_group[(int) $g['id']] ?? []);
        ?>
        <div class="card min-h-[100px] flex flex-col">
          <div class="flex items-center justify-between gap-2 mb-1">
            <input type="text" name="group_names[<?= (int) $g['id'] ?>]" value="<?= htmlspecialchars($g['name']) ?>" class="input-field flex-1" style="font-size: 80%;" placeholder="Group <?= (int) $g['id'] ?>">
            <button type="button" class="remove-group-btn btn-secondary text-xs py-1 px-2 shrink-0" data-group-id="<?= (int) $g['id'] ?>" data-child-count="<?= $group_child_count ?>" title="Remove group (children move to Unassigned)">Remove</button>
          </div>
          <div class="text-sm text-gray-500 mb-1" style="font-size: 70%;" ><span id="count-<?= (int) $g['id'] ?>">Children Count:<?= $group_child_count ?></span></div>
          <div id="list-group-<?= (int) $g['id'] ?>" class="min-h-[120px] flex-1 space-y-2 rounded-lg border-2 border-dashed border-indigo-200 bg-indigo-50/30 p-3" data-group-id="<?= (int) $g['id'] ?>">
            <?php foreach ($by_group[(int) $g['id']] ?? [] as $k):
              $age = $k['age'] !== null && $k['age'] !== '' ? (int) $k['age'] : null;
              $grade = isset($k['last_grade_completed']) && $k['last_grade_completed'] !== '' ? $k['last_grade_completed'] : null;
              $dob = null;
              if (!empty($k['date_of_birth'])) { $ts = strtotime($k['date_of_birth']); $dob = ($ts !== false) ? date('m/d/Y', $ts) : null; }
              $parts_full = array_filter([
                $age !== null ? "Age: $age" : null,
                $grade !== null ? "Grade in Fall: " . $grade : null,
                $dob !== null ? "BD: " . $dob : null
              ]);
              $parts_short = array_filter([ $age !== null ? (string) $age : null, $grade ]);
              $meta_full = implode('. ', $parts_full);
              $meta_short = implode('. ', $parts_short);
              $meta_dob = $dob !== null ? $dob : '';
              $kid_name_lc = strtolower(trim($k['first_name'] . ' ' . $k['last_name']));
              $kid_age = $age !== null ? (string) $age : '';
              $kid_grade = $grade !== null ? $grade : '';
              $card_bg = (isset($grade) && $grade !== null && isset($grade_colors[$grade])) ? $grade_colors[$grade] : $grade_color_default;
            ?>
            <div class="kid-card flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 shadow-sm cursor-grab active:cursor-grabbing" style="font-size: 70%; background-color: <?= htmlspecialchars($card_bg) ?>;" data-kid-id="<?= (int) $k['id'] ?>" data-name="<?= htmlspecialchars($kid_name_lc) ?>" data-age="<?= htmlspecialchars($kid_age) ?>" data-grade="<?= htmlspecialchars($kid_grade) ?>" data-meta-full="<?= htmlspecialchars($meta_full) ?>" data-meta-short="<?= htmlspecialchars($meta_short) ?>" data-meta-dob="<?= htmlspecialchars($meta_dob) ?>">
              <span class="font-bold text-gray-900"><?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?></span>
              <div class="shrink-0 text-right">
                <span class="kid-meta text-xs text-gray-500"><?= $meta_short !== '' ? htmlspecialchars($meta_short) : '' ?></span>
                <span class="kid-meta-dob text-xs text-gray-500 block"><?= $meta_dob !== '' ? htmlspecialchars($meta_dob) : '' ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <input type="hidden" name="assignments" id="assignments-input" value="">
    <button type="submit" class="btn-primary">Save assignments</button>

  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function() {
  var form = document.getElementById('assign-form');
  var assignmentsInput = document.getElementById('assignments-input');

  function postAction(action, extra) {
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '';
    var i = document.createElement('input');
    i.name = 'action';
    i.value = action;
    i.type = 'hidden';
    f.appendChild(i);
    if (extra) for (var k in extra) {
      var j = document.createElement('input');
      j.name = k;
      j.type = 'hidden';
      j.value = String(extra[k]);
      f.appendChild(j);
    }
    document.body.appendChild(f);
    f.submit();
  }

  var addBtn = document.getElementById('add-group-btn');
  if (addBtn) addBtn.addEventListener('click', function() { postAction('add_group'); });

  document.querySelectorAll('.remove-group-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var n = this.getAttribute('data-child-count') || '0';
      var msg = n === '0' ? 'Remove this group?' : 'This group has ' + n + ' child(ren). They will be moved to Unassigned. Remove this group?';
      if (!confirm(msg)) return;
      postAction('remove_group', { group_id: this.getAttribute('data-group-id') });
    });
  });

  function applyFilter() {
    var nameQ = (document.getElementById('filter-name') && document.getElementById('filter-name').value) ? document.getElementById('filter-name').value.trim().toLowerCase() : '';
    var ageVal = document.getElementById('filter-age') ? document.getElementById('filter-age').value : '';
    var gradeVal = document.getElementById('filter-grade') ? document.getElementById('filter-grade').value : '';
    document.querySelectorAll('.kid-card').forEach(function(card) {
      var name = (card.getAttribute('data-name') || '');
      var age = (card.getAttribute('data-age') || '');
      var grade = (card.getAttribute('data-grade') || '');
      var nameMatch = !nameQ || name.indexOf(nameQ) !== -1;
      var ageMatch = !ageVal || age === ageVal;
      var gradeMatch = !gradeVal || grade === gradeVal;
      card.style.display = (nameMatch && ageMatch && gradeMatch) ? '' : 'none';
    });
  }
  var filterName = document.getElementById('filter-name');
  var filterAge = document.getElementById('filter-age');
  var filterGrade = document.getElementById('filter-grade');
  var filterClear = document.getElementById('filter-clear');
  if (filterName) filterName.addEventListener('input', applyFilter);
  if (filterAge) filterAge.addEventListener('change', applyFilter);
  if (filterGrade) filterGrade.addEventListener('change', applyFilter);
  if (filterClear) filterClear.addEventListener('click', function() {
    if (filterName) filterName.value = '';
    if (filterAge) filterAge.value = '';
    if (filterGrade) filterGrade.value = '';
    applyFilter();
  });

  function listEl(groupId) {
    return groupId === 0 ? document.getElementById('list-unassigned') : document.getElementById('list-group-' + groupId);
  }

  function collectAssignments() {
    var out = {};
    var lists = document.querySelectorAll('[data-group-id]');
    lists.forEach(function(list) {
      var gid = list.getAttribute('data-group-id');
      gid = gid === '0' ? null : parseInt(gid, 10);
      list.querySelectorAll('[data-kid-id]').forEach(function(card) {
        var kidId = parseInt(card.getAttribute('data-kid-id'), 10);
        out[kidId] = gid;
      });
    });
    return out;
  }

  function updateCounts() {
    document.querySelectorAll('[data-group-id]').forEach(function(list) {
      var gid = list.getAttribute('data-group-id');
      if (gid === '0') return;
      var n = list.querySelectorAll('[data-kid-id]').length;
      var el = document.getElementById('count-' + gid);
      if (el) el.textContent = n;
    });
  }

  function updateKidCardMeta(card, list) {
    var metaSpan = card.querySelector('.kid-meta');
    var dobSpan = card.querySelector('.kid-meta-dob');
    if (!metaSpan) return;
    var groupId = list ? list.getAttribute('data-group-id') : null;
    var full = card.getAttribute('data-meta-full') || '';
    var short = card.getAttribute('data-meta-short') || '';
    var dob = card.getAttribute('data-meta-dob') || '';
    var isUnassigned = (groupId === '0');
    metaSpan.textContent = (isUnassigned ? full : short);
    metaSpan.style.display = (metaSpan.textContent ? '' : 'none');
    if (dobSpan) {
      dobSpan.textContent = dob;
      dobSpan.style.display = (isUnassigned || !dob ? 'none' : 'block');
    }
  }

  form.addEventListener('submit', function(e) {
    assignmentsInput.value = JSON.stringify(collectAssignments());
  });

  var groupOptions = {
    group: 'kids',
    animation: 150,
    ghostClass: 'opacity-50',
    onEnd: function(evt) {
      updateKidCardMeta(evt.item, evt.to);
      updateCounts();
    }
  };
  var unassigned = document.getElementById('list-unassigned');
  if (unassigned) {
    new Sortable(unassigned, groupOptions);
  }
  <?php foreach ($groups as $g): ?>
  (function() {
    var el = document.getElementById('list-group-<?= (int) $g['id'] ?>');
    if (el) new Sortable(el, groupOptions);
  })();
  <?php endforeach; ?>
})();
</script>
<?php layout_footer(); ?>
</body>
</html>
