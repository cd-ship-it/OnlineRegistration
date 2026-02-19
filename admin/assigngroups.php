<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

require_admin();

const UNASSIGNED_GROUP_ID = 0;

// Grade → light background color (hex). Add or change grades here. Default used when grade not in map.
$grade_colors = [
  'Preschool' => '#fce7f3',  // pink-100
  'PreK'      => '#e0f2fe',  // sky-100
  'K'         => '#fef3c7',  // amber-100
  '1st'       => '#dbeafe',  // blue-100
  '2nd'       => '#d1fae5',  // emerald-100
  '3rd'       => '#e9d5ff',  // violet-100
  '4th'       => '#fce7f3',  // pink-100
  '5th'       => '#fed7aa',  // orange-100
  '6th'       => '#e0e7ff',  // indigo-100
];
$grade_color_default = '#f3f4f6'; // gray-100

$db = (defined('DB_NAME') && DB_NAME !== '') ? '`' . str_replace('`', '``', DB_NAME) . '`.' : '';

$message = '';
$errors  = [];

// POST: Auto-assign every kid to a group by grade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_assign') {
  csrf_verify();
  $grade_aliases = ['Pre K' => 'PreK', 'pre k' => 'PreK', 'prek' => 'PreK'];
  ag_auto_assign_by_grade($pdo, $db, array_keys($grade_colors), $grade_aliases);
  header('Location: ' . APP_URL . '/admin/assigngroups?auto_assigned=1', true, 302);
  exit;
}

// Load data
$kids   = ag_get_kids($pdo, $db);
$groups = ag_get_groups($pdo, $db);

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
$has_assignments = count(array_filter($kids, function ($k) { return isset($k['group_id']) && $k['group_id'] !== null && $k['group_id'] !== ''; })) > 0;

// Export Students to Excel
if (isset($_GET['export_students_excel']) && $_GET['export_students_excel'] === '1') {
  require_once dirname(__DIR__) . '/includes/excel_export.php';
  export_students_excel($groups, $by_group, $unassigned, ag_get_volunteers_by_group($pdo, $db));
}

// Export view: open in new tab, two columns, one group per card
if (isset($_GET['export']) && $_GET['export'] === '1') {
  layout_head('Group Assignments – Export');
  ?>
  <div class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Group Assignments</h1>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
      <?php foreach ($groups as $g):
        $group_kids = $by_group[(int) $g['id']] ?? [];
      ?>
      <div class="card border border-gray-200 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-900 mb-3 pb-2 border-b border-gray-200"><?= htmlspecialchars($g['name'] ?: 'Group ' . (int) $g['id']) ?></h2>
        <ul class="space-y-1.5 text-sm text-gray-700">
          <?php foreach ($group_kids as $k):
            $age = $k['age'] !== null && $k['age'] !== '' ? (int) $k['age'] : null;
            $grade = isset($k['last_grade_completed']) && $k['last_grade_completed'] !== '' ? $k['last_grade_completed'] : null;
            $dob = null;
            if (!empty($k['date_of_birth'])) { $ts = strtotime($k['date_of_birth']); $dob = ($ts !== false) ? date('m/d/Y', $ts) : null; }
            $parts = array_filter([
              $age !== null ? "$age" : null,
              $grade !== null ? "$grade" : null,
              $dob !== null ? "$dob" : null
            ]);
            $meta = $parts ? ' (' . implode('. ', $parts) . ')' : '';
          ?>
          <li><?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?><?= $meta ? htmlspecialchars($meta) : '' ?></li>
          <?php endforeach; ?>
          <?php if (empty($group_kids)): ?>
          <li class="text-gray-400 italic">No children assigned</li>
          <?php endif; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  layout_footer();
  exit;
}

// POST: Save kid → group assignments
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $assignments_raw = $_POST['assignments'] ?? [];
  $assignments     = [];

  if (is_string($assignments_raw)) {
    $decoded = json_decode($assignments_raw, true);
    if (is_array($decoded)) $assignments = $decoded;
  } elseif (is_array($assignments_raw)) {
    foreach ($assignments_raw as $kid_id => $group_id) {
      $assignments[(int) $kid_id] = ($group_id === '' || $group_id === null) ? null : (int) $group_id;
    }
  }

  $valid_kid_ids   = array_column($kids,   'id');
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
      ag_save_assignments($pdo, $db, $valid_kid_ids, $assignments);
      header('Location: ' . APP_URL . '/admin/assigngroups?saved=1', true, 302);
      exit;
    } catch (Exception $e) {
      $errors[] = 'Save failed: ' . $e->getMessage();
    }
  }
}

$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$auto_assigned = isset($_GET['auto_assigned']) && $_GET['auto_assigned'] === '1';
layout_head('Admin – Assign Groups');
admin_nav('assigngroups');
?>

<div class="max-w-7xl mx-auto px-4 py-6">
  <div class="flex flex-wrap items-center gap-3 mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Assign Groups</h1>
    <button type="button" class="btn-primary" onclick="document.getElementById('assign-form').requestSubmit()">Save Assignments</button>
    <button type="button" id="auto-assign-btn" class="btn-secondary" data-has-assignments="<?= $has_assignments ? '1' : '0' ?>">Auto assign</button>
    <a href="<?= APP_URL ?>/admin/assigngroups?export=1" target="_blank" rel="noopener noreferrer" class="btn-secondary">Export</a>
    <a href="<?= APP_URL ?>/admin/assigngroups?export_students_excel=1" class="btn-secondary">Export Students (Excel)</a>
  </div>

  <?php if ($saved): ?>
  <div class="card border-green-200 bg-green-50 text-green-800 mb-6">Assignments saved.</div>
  <?php endif; ?>
  <?php if ($auto_assigned): ?>
  <div class="card border-green-200 bg-green-50 text-green-800 mb-6">Children auto-assigned to groups by grade.</div>
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
      <div class="w-20">
        <label for="filter-gender" class="block text-xs font-medium text-gray-500 mb-1">Gender</label>
        <select id="filter-gender" class="input-field py-1.5 text-sm">
          <option value="">All</option>
          <option value="B">Boy</option>
          <option value="G">Girl</option>
        </select>
      </div>
      <div class="w-20">
        <label for="filter-church" class="block text-xs font-medium text-gray-500 mb-1">Church</label>
        <select id="filter-church" class="input-field py-1.5 text-sm">
          <option value="">All</option>
          <option value="CH">CH</option>
          <option value="noCH">noCH</option>
        </select>
      </div>
      <button type="button" id="filter-clear" class="btn-secondary text-sm py-1.5">Clear</button>
    </div>
  </div>

  <form id="assign-form" method="post" action="" class="space-y-6">
    <div id="assign-layout" class="grid grid-cols-1 lg:grid-cols-[30fr_70fr] gap-6 items-start">
      <!-- Left: Children (Unassigned) -->
      <div id="unassigned-panel" class="card transition-[grid-column] duration-200">
        <div class="flex items-center justify-between gap-2 mb-2">
          <h2 class="text-lg font-semibold text-gray-900">Unassigned</h2>
          <div class="flex items-center gap-2">
            <div class="relative">
              <button type="button" id="batch-assign-btn" class="btn-secondary text-xs py-1 px-2" title="Assign selected kids to a group">Batch Assign</button>
              <div id="batch-assign-sub" class="hidden absolute left-0 top-full mt-1 min-w-[160px] py-1 bg-white rounded-lg shadow-lg border border-gray-200 z-50" style="padding: 0.25rem 0;"></div>
            </div>
            <button type="button" id="toggle-unassigned-btn" class="btn-secondary text-xs py-1 px-2" title="Collapse Unassigned panel">Collapse</button>
          </div>
        </div>
        <p class="text-sm text-gray-500 mb-3">Drag children here or into a group.</p>

        <p class="text-sm text-gray-500 mb-3">Double click on a child to view his/her info.</p>
        <div id="list-unassigned" class="min-h-[120px] space-y-2 rounded-lg  border-dashed border-gray-200 bg-gray-50/50 p-3" data-group-id="0">
          <?php foreach ($unassigned as $k):
            $age = $k['age'] !== null && $k['age'] !== '' ? (int) $k['age'] : null;
            $grade = isset($k['last_grade_completed']) && $k['last_grade_completed'] !== '' ? $k['last_grade_completed'] : null;
            $grade_display = ($grade === 'Preschool') ? 'PreSch' : $grade;
            $dob = null;
            if (!empty($k['date_of_birth'])) { $ts = strtotime($k['date_of_birth']); $dob = ($ts !== false) ? date('m/d/Y', $ts) : null; }
            $g = trim($k['gender'] ?? '');
            $gender_display = ($g !== '' && (strtoupper(substr($g, 0, 1)) === 'F' || strtolower($g) === 'female' || strtolower($g) === 'girl')) ? 'G' : (($g !== '' && (strtoupper(substr($g, 0, 1)) === 'M' || strtolower($g) === 'male' || strtolower($g) === 'boy')) ? 'B' : null);
            $ch_display = (isset($k['home_church']) && trim($k['home_church'] ?? '') !== '') ? 'CH' : 'noCH';
            $parts_full = array_filter([
              $age !== null ? "".$age : null,
              $grade_display !== null ? "" . $grade_display : null,
              $dob !== null ? "" . $dob : null,
              $gender_display !== null ? $gender_display : null,
              $ch_display
            ]);
            $parts_short = array_filter([ $age !== null ? (string) $age : null, $grade_display, $gender_display, $ch_display ]);
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
          <div class="kid-card flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-2 py-2 shadow-sm cursor-grab active:cursor-grabbing" style="font-size: 70%; background-color: <?= htmlspecialchars($card_bg) ?>;" data-kid-id="<?= (int) $k['id'] ?>" data-registration-id="<?= (int) $k['registration_id'] ?>" data-name="<?= htmlspecialchars($kid_name_lc) ?>" data-age="<?= htmlspecialchars($kid_age) ?>" data-grade="<?= htmlspecialchars($kid_grade) ?>" data-gender="<?= $gender_display !== null ? htmlspecialchars($gender_display) : '' ?>" data-church="<?= htmlspecialchars($ch_display) ?>" data-meta-full="<?= htmlspecialchars($meta_full) ?>" data-meta-short="<?= htmlspecialchars($meta_short) ?>" data-meta-dob="<?= htmlspecialchars($meta_dob) ?>">
            <input type="checkbox" class="unassigned-kid-checkbox shrink-0" data-kid-id="<?= (int) $k['id'] ?>" aria-label="Select for batch">
            <span class="font-bold text-gray-900 flex-1 min-w-0"><?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?></span>
            <div class="shrink-0 text-right">
              <span class="kid-meta text-xs text-gray-500"><?= $meta_full !== '' ? htmlspecialchars($meta_full) : '' ?></span>
              <span class="kid-meta-dob text-xs text-gray-500 block" style="display: none;"></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Right: Groups (3 or 4 per row when Unassigned collapsed) -->
      <div id="groups-panel" class="min-w-0">
        <div id="groups-grid" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <div id="groups-header-row" class="sm:col-span-3 flex flex-wrap items-center gap-2">
            <button type="button" id="show-unassigned-btn" class="btn-secondary text-sm hidden" title="Expand Unassigned panel">Show Unassigned</button>
          </div>
        <?php foreach ($groups as $g):
          $group_child_count = count($by_group[(int) $g['id']] ?? []);
        ?>
        <div class="card min-h-[100px] flex flex-col" style="padding: 0.5rem;">
          <div class="flex items-center justify-between gap-1 mb-1">
            <span class="font-semibold text-gray-900 flex-1 truncate" style="font-size: 80%;"><?= htmlspecialchars($g['name']) ?></span>
          </div>
          <div class="text-sm text-gray-500 mb-1" style="font-size: 70%;" ><span id="count-<?= (int) $g['id'] ?>">Children Count: <?= $group_child_count ?></span></div>
          <div id="list-group-<?= (int) $g['id'] ?>" class="min-h-[120px] flex-1 space-y-2 rounded-lg  border-dashed border-indigo-200 bg-indigo-50/30 p-3" data-group-id="<?= (int) $g['id'] ?>">
            <?php foreach ($by_group[(int) $g['id']] ?? [] as $k):
              $age = $k['age'] !== null && $k['age'] !== '' ? (int) $k['age'] : null;
              $grade = isset($k['last_grade_completed']) && $k['last_grade_completed'] !== '' ? $k['last_grade_completed'] : null;
              $grade_display = ($grade === 'Preschool') ? 'PreSch' : $grade;
              $dob = null;
              if (!empty($k['date_of_birth'])) { $ts = strtotime($k['date_of_birth']); $dob = ($ts !== false) ? date('m/d/Y', $ts) : null; }
              $gnd = trim($k['gender'] ?? '');
              $gender_display = ($gnd !== '' && (strtoupper(substr($gnd, 0, 1)) === 'F' || strtolower($gnd) === 'female' || strtolower($gnd) === 'girl')) ? 'G' : (($gnd !== '' && (strtoupper(substr($gnd, 0, 1)) === 'M' || strtolower($gnd) === 'male' || strtolower($gnd) === 'boy')) ? 'B' : null);
              $ch_display = (isset($k['home_church']) && trim($k['home_church'] ?? '') !== '') ? 'CH' : 'noCH';
              $parts_full = array_filter([
                $age !== null ? $age : null,
                $grade_display !== null ? "" . $grade_display : null,
                $dob !== null ? "" . $dob : null,
                $gender_display !== null ? $gender_display : null,
                $ch_display
              ]);
              $parts_short = array_filter([ $age !== null ? (string) $age : null, $grade_display, $gender_display, $ch_display ]);
              $meta_full = implode('. ', $parts_full);
              $meta_short = implode('. ', $parts_short);
              $meta_dob = $dob !== null ? $dob : '';
              $kid_name_lc = strtolower(trim($k['first_name'] . ' ' . $k['last_name']));
              $kid_age = $age !== null ? (string) $age : '';
              $kid_grade = $grade !== null ? $grade : '';
              $card_bg = (isset($grade) && $grade !== null && isset($grade_colors[$grade])) ? $grade_colors[$grade] : $grade_color_default;
            ?>
            <div class="kid-card flex items-center justify-between gap-1 rounded-lg border-gray-200 px-2 py-2 shadow-sm cursor-grab active:cursor-grabbing" style="font-size: 70%; background-color: <?= htmlspecialchars($card_bg) ?>;" data-kid-id="<?= (int) $k['id'] ?>" data-registration-id="<?= (int) $k['registration_id'] ?>" data-name="<?= htmlspecialchars($kid_name_lc) ?>" data-age="<?= htmlspecialchars($kid_age) ?>" data-grade="<?= htmlspecialchars($kid_grade) ?>" data-gender="<?= $gender_display !== null ? htmlspecialchars($gender_display) : '' ?>" data-church="<?= htmlspecialchars($ch_display) ?>" data-meta-full="<?= htmlspecialchars($meta_full) ?>" data-meta-short="<?= htmlspecialchars($meta_short) ?>" data-meta-dob="<?= htmlspecialchars($meta_dob) ?>">
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
    </div>
    <?= csrf_input() ?>
    <input type="hidden" name="assignments" id="assignments-input" value="">
    <button type="submit" class="btn-primary">Save assignments</button>

  </form>
  <div id="kid-card-context-menu" class="hidden fixed z-50 min-w-[180px] py-1 bg-white rounded-lg shadow-lg border border-gray-200" style="padding: 0.25rem 0;">
    <button type="button" id="kid-context-menu-view" class="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">View Kid info</button>
    <div id="kid-context-menu-assign-wrap" class="relative" style="display: none;">
      <button type="button" id="kid-context-menu-assign" class="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">Assign to Group ▸</button>
      <div id="kid-context-menu-assign-sub" class="hidden absolute left-full top-0 ml-0.5 min-w-[140px] py-1 bg-white rounded-lg shadow-lg border border-gray-200 z-50" style="padding: 0.25rem 0;"></div>
    </div>
    <button type="button" id="kid-context-menu-remove" class="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">Remove Kid from group</button>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function() {
  var appUrl = <?= json_encode(defined('APP_URL') ? APP_URL : '') ?>;
  var form = document.getElementById('assign-form');
  var assignmentsInput = document.getElementById('assignments-input');

  var toggleUnassignedBtn = document.getElementById('toggle-unassigned-btn');
  var showUnassignedBtn = document.getElementById('show-unassigned-btn');
  var assignLayout = document.getElementById('assign-layout');
  var unassignedPanel = document.getElementById('unassigned-panel');
  var groupsGrid = document.getElementById('groups-grid');
  var groupsHeaderRow = document.getElementById('groups-header-row');
  var collapsed = false;
  function setCollapsed(isCollapsed) {
    collapsed = isCollapsed;
    if (collapsed) {
      assignLayout.classList.remove('lg:grid-cols-[30fr_70fr]');
      assignLayout.classList.add('lg:grid-cols-1');
      unassignedPanel.classList.add('hidden');
      groupsGrid.classList.remove('sm:grid-cols-3');
      groupsGrid.classList.add('sm:grid-cols-5');
      groupsHeaderRow.classList.remove('sm:col-span-3');
      groupsHeaderRow.classList.add('sm:col-span-5');
      if (showUnassignedBtn) showUnassignedBtn.classList.remove('hidden');
    } else {
      assignLayout.classList.add('lg:grid-cols-[30fr_70fr]');
      assignLayout.classList.remove('lg:grid-cols-1');
      unassignedPanel.classList.remove('hidden');
      groupsGrid.classList.add('sm:grid-cols-3');
      groupsGrid.classList.remove('sm:grid-cols-5');
      groupsHeaderRow.classList.add('sm:col-span-3');
      groupsHeaderRow.classList.remove('sm:col-span-5');
      if (showUnassignedBtn) showUnassignedBtn.classList.add('hidden');
    }
  }
  var batchAssignBtn = document.getElementById('batch-assign-btn');
  var batchAssignSub = document.getElementById('batch-assign-sub');
  var batchAssignCards = [];
  if (batchAssignBtn && batchAssignSub) {
    batchAssignBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      batchAssignCards = [];
      document.querySelectorAll('.unassigned-kid-checkbox:checked').forEach(function(cb) {
        var card = cb.closest('.kid-card');
        if (card) batchAssignCards.push(card);
      });
      if (batchAssignCards.length === 0) {
        alert('No kids selected. Check one or more kids in Unassigned first.');
        return;
      }
      batchAssignSub.innerHTML = '';
      var groups = getGroupLists();
      groups.forEach(function(g) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded';
        btn.textContent = g.name;
        btn.addEventListener('click', function(ev) {
          ev.preventDefault();
          ev.stopPropagation();
          var groupList = g.list;
          batchAssignCards.forEach(function(card) {
            if (card.parentNode) {
              card.parentNode.removeChild(card);
              groupList.appendChild(card);
              removeUnassignedCardCheckbox(card);
              updateKidCardMeta(card, groupList);
            }
          });
          updateCounts();
          batchAssignSub.classList.add('hidden');
          batchAssignCards = [];
        });
        batchAssignSub.appendChild(btn);
      });
      batchAssignSub.classList.toggle('hidden', groups.length === 0);
      if (groups.length > 0) batchAssignSub.classList.remove('hidden');
    });
    document.addEventListener('click', function() {
      batchAssignSub.classList.add('hidden');
    });
  }
  if (toggleUnassignedBtn && assignLayout && unassignedPanel && groupsGrid && groupsHeaderRow) {
    toggleUnassignedBtn.addEventListener('click', function() { setCollapsed(true); });
    if (showUnassignedBtn) showUnassignedBtn.addEventListener('click', function() { setCollapsed(false); });
  }

  function openKidRegistrationView(card) {
    var regId = card && card.getAttribute('data-registration-id');
    if (!regId || !appUrl) return;
    var name = (card.getAttribute('data-name') || '').trim();
    var parts = name.split(/\s+/).filter(Boolean);
    var first = parts[0] || '';
    var last = parts.length > 1 ? parts[parts.length - 1] : first;
    function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).toLowerCase() : ''; }
    var anchorName = (cap(first) + cap(last)).replace(/[^A-Za-z0-9\-_:.]/g, '');
    var url = appUrl + '/admin/registrations/view?id=' + regId + (anchorName ? '#' + anchorName : '');
    window.open(url, '_blank');
  }
  form.addEventListener('dblclick', function(e) {
    var card = e.target.closest('.kid-card');
    if (!card) return;
    openKidRegistrationView(card);
  });

  var csrfToken = <?= json_encode(csrf_generate()) ?>;

  function postAction(action, extra) {
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '';
    var i = document.createElement('input');
    i.name = 'action';
    i.value = action;
    i.type = 'hidden';
    f.appendChild(i);
    var csrf = document.createElement('input');
    csrf.type  = 'hidden';
    csrf.name  = 'csrf_token';
    csrf.value = csrfToken;
    f.appendChild(csrf);
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

  var autoAssignBtn = document.getElementById('auto-assign-btn');
  if (autoAssignBtn) {
    autoAssignBtn.addEventListener('click', function() {
      var hasAssignments = this.getAttribute('data-has-assignments') === '1';
      var msg = hasAssignments
        ? 'Some children are already assigned to groups. Auto-assign will replace these assignments and assign by grade (one grade per group). Continue?'
        : 'Assign all children to groups by grade (one grade per group)?';
      if (!confirm(msg)) return;
      postAction('auto_assign');
    });
  }

  function applyFilter() {
    var nameQ = (document.getElementById('filter-name') && document.getElementById('filter-name').value) ? document.getElementById('filter-name').value.trim().toLowerCase() : '';
    var ageVal = document.getElementById('filter-age') ? document.getElementById('filter-age').value : '';
    var gradeVal = document.getElementById('filter-grade') ? document.getElementById('filter-grade').value : '';
    var genderVal = document.getElementById('filter-gender') ? document.getElementById('filter-gender').value : '';
    var churchVal = document.getElementById('filter-church') ? document.getElementById('filter-church').value : '';
    document.querySelectorAll('.kid-card').forEach(function(card) {
      var name = (card.getAttribute('data-name') || '');
      var age = (card.getAttribute('data-age') || '');
      var grade = (card.getAttribute('data-grade') || '');
      var gender = (card.getAttribute('data-gender') || '');
      var church = (card.getAttribute('data-church') || '');
      var nameMatch = !nameQ || name.indexOf(nameQ) !== -1;
      var ageMatch = !ageVal || age === ageVal;
      var gradeMatch = !gradeVal || grade === gradeVal;
      var genderMatch = !genderVal || gender === genderVal;
      var churchMatch = !churchVal || church === churchVal;
      card.style.display = (nameMatch && ageMatch && gradeMatch && genderMatch && churchMatch) ? '' : 'none';
    });
  }
  var filterName = document.getElementById('filter-name');
  var filterAge = document.getElementById('filter-age');
  var filterGrade = document.getElementById('filter-grade');
  var filterGender = document.getElementById('filter-gender');
  var filterChurch = document.getElementById('filter-church');
  var filterClear = document.getElementById('filter-clear');
  if (filterName) filterName.addEventListener('input', applyFilter);
  if (filterAge) filterAge.addEventListener('change', applyFilter);
  if (filterGrade) filterGrade.addEventListener('change', applyFilter);
  if (filterGender) filterGender.addEventListener('change', applyFilter);
  if (filterChurch) filterChurch.addEventListener('change', applyFilter);
  if (filterClear) filterClear.addEventListener('click', function() {
    if (filterName) filterName.value = '';
    if (filterAge) filterAge.value = '';
    if (filterGrade) filterGrade.value = '';
    if (filterGender) filterGender.value = '';
    if (filterChurch) filterChurch.value = '';
    applyFilter();
  });

  function listEl(groupId) {
    return groupId === 0 ? document.getElementById('list-unassigned') : document.getElementById('list-group-' + groupId);
  }

  function dobToSortKey(dobStr) {
    if (!dobStr || dobStr.trim() === '') return 0;
    var m = dobStr.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return 0;
    return parseInt(m[3] + m[1].padStart(2, '0') + m[2].padStart(2, '0'), 10);
  }
  function compareCardsForSort(cardA, cardB) {
    var ageA = cardA.getAttribute('data-age') || '';
    var ageB = cardB.getAttribute('data-age') || '';
    var na = ageA === '' ? -1 : parseInt(ageA, 10);
    var nb = ageB === '' ? -1 : parseInt(ageB, 10);
    if (na !== nb) return na < nb ? -1 : 1;
    var dobA = dobToSortKey(cardA.getAttribute('data-meta-dob') || '');
    var dobB = dobToSortKey(cardB.getAttribute('data-meta-dob') || '');
    if (dobA !== dobB) return dobA < dobB ? -1 : 1;
    var gradeA = (cardA.getAttribute('data-grade') || '').toLowerCase();
    var gradeB = (cardB.getAttribute('data-grade') || '').toLowerCase();
    if (gradeA !== gradeB) return gradeA < gradeB ? -1 : 1;
    var nameA = cardA.getAttribute('data-name') || '';
    var nameB = cardB.getAttribute('data-name') || '';
    return nameA < nameB ? -1 : (nameA > nameB ? 1 : 0);
  }
  function removeKidFromGroup(card, unassignedList) {
    card.parentNode.removeChild(card);
    var kids = unassignedList.querySelectorAll('.kid-card');
    var insertBefore = null;
    for (var i = 0; i < kids.length; i++) {
      if (compareCardsForSort(card, kids[i]) < 0) {
        insertBefore = kids[i];
        break;
      }
    }
    if (insertBefore) unassignedList.insertBefore(card, insertBefore);
    else unassignedList.appendChild(card);
    ensureUnassignedCardCheckbox(card);
    updateKidCardMeta(card, unassignedList);
    updateCounts();
  }

  function getGroupLists() {
    var out = [];
    document.querySelectorAll('div[data-group-id]').forEach(function(list) {
      var gid = list.getAttribute('data-group-id');
      if (!gid || gid === '0') return;
      var card = list.closest('.card');
      var nameEl = card ? card.querySelector('span.font-semibold') : null;
      var name = (nameEl && nameEl.textContent.trim()) ? nameEl.textContent.trim() : ('Group ' + gid);
      out.push({ gid: gid, name: name, list: list });
    });
    return out;
  }
  function assignKidToGroup(card, groupList) {
    card.parentNode.removeChild(card);
    groupList.appendChild(card);
    removeUnassignedCardCheckbox(card);
    updateKidCardMeta(card, groupList);
    updateCounts();
  }
  function ensureUnassignedCardCheckbox(card) {
    if (card.querySelector('.unassigned-kid-checkbox')) return;
    var kidId = card.getAttribute('data-kid-id');
    if (!kidId) return;
    var cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'unassigned-kid-checkbox shrink-0';
    cb.setAttribute('data-kid-id', kidId);
    cb.setAttribute('aria-label', 'Select for batch');
    card.insertBefore(cb, card.firstChild);
    var nameSpan = card.querySelector('span.font-bold');
    if (nameSpan && !nameSpan.classList.contains('flex-1')) nameSpan.classList.add('flex-1', 'min-w-0');
  }
  function removeUnassignedCardCheckbox(card) {
    var cb = card.querySelector('.unassigned-kid-checkbox');
    if (cb) cb.remove();
  }

  var kidContextMenu = document.getElementById('kid-card-context-menu');
  var kidContextMenuView = document.getElementById('kid-context-menu-view');
  var kidContextMenuAssignWrap = document.getElementById('kid-context-menu-assign-wrap');
  var kidContextMenuAssign = document.getElementById('kid-context-menu-assign');
  var kidContextMenuAssignSub = document.getElementById('kid-context-menu-assign-sub');
  var kidContextMenuRemove = document.getElementById('kid-context-menu-remove');
  var kidContextMenuCard = null;
  function closeContextMenus() {
    kidContextMenu.classList.add('hidden');
    if (kidContextMenuAssignSub) kidContextMenuAssignSub.classList.add('hidden');
    kidContextMenuCard = null;
  }
  form.addEventListener('contextmenu', function(e) {
    var card = e.target.closest('.kid-card');
    if (!card) {
      closeContextMenus();
      return;
    }
    e.preventDefault();
    kidContextMenuCard = card;
    var list = card.closest('[data-group-id]');
    var groupId = list ? list.getAttribute('data-group-id') : null;
    var isUnassigned = !groupId || groupId === '0';
    if (kidContextMenuRemove) kidContextMenuRemove.style.display = (groupId && groupId !== '0') ? '' : 'none';
    if (kidContextMenuAssignWrap) kidContextMenuAssignWrap.style.display = isUnassigned ? '' : 'none';
    if (kidContextMenuAssignSub) kidContextMenuAssignSub.classList.add('hidden');
    kidContextMenu.classList.remove('hidden');
    kidContextMenu.style.left = e.clientX + 'px';
    kidContextMenu.style.top = e.clientY + 'px';
  });
  if (kidContextMenuView && kidContextMenu) {
    kidContextMenuView.addEventListener('click', function(e) {
      e.preventDefault();
      if (kidContextMenuCard) openKidRegistrationView(kidContextMenuCard);
      closeContextMenus();
    });
  }
  if (kidContextMenuAssign && kidContextMenuAssignSub && kidContextMenu) {
    kidContextMenuAssign.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      if (!kidContextMenuCard) return;
      kidContextMenuAssignSub.innerHTML = '';
      var groups = getGroupLists();
      groups.forEach(function(g) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded';
        btn.textContent = g.name;
        btn.setAttribute('data-group-id', g.gid);
        btn.addEventListener('click', function(ev) {
          ev.preventDefault();
          ev.stopPropagation();
          assignKidToGroup(kidContextMenuCard, g.list);
          closeContextMenus();
        });
        kidContextMenuAssignSub.appendChild(btn);
      });
      kidContextMenuAssignSub.classList.toggle('hidden', groups.length === 0);
    });
  }
  if (kidContextMenuRemove && kidContextMenu) {
    kidContextMenuRemove.addEventListener('click', function(e) {
      e.preventDefault();
      if (kidContextMenuCard) {
        var unassignedList = document.getElementById('list-unassigned');
        if (unassignedList) removeKidFromGroup(kidContextMenuCard, unassignedList);
      }
      closeContextMenus();
    });
  }
  document.addEventListener('click', function() {
    closeContextMenus();
  });
  document.addEventListener('scroll', function() { closeContextMenus(); }, true);

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
    document.querySelectorAll('div[data-group-id]').forEach(function(list) {
      var gid = list.getAttribute('data-group-id');
      if (gid === '0') return;
      var n = list.querySelectorAll('[data-kid-id]').length;
      var el = document.getElementById('count-' + gid);
      if (el) el.textContent = 'Children Count: ' + n;
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
    filter: '.unassigned-kid-checkbox',
    onEnd: function(evt) {
      var toList = evt.to;
      var groupId = toList ? toList.getAttribute('data-group-id') : null;
      if (groupId && groupId !== '0') removeUnassignedCardCheckbox(evt.item);
      else ensureUnassignedCardCheckbox(evt.item);
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
