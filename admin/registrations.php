<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/price.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

require_admin();

$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'date';
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';

$order_columns = [
    'parent' => 'r.parent_last_name ' . $dir . ', r.parent_first_name ' . $dir,
    'email'  => 'r.email ' . $dir,
    'kids'   => 'kid_count ' . $dir,
    'photo'  => 'r.photo_consent ' . $dir,
    'status' => 'r.status ' . $dir,
    'date'   => 'r.created_at ' . $dir,
];
$sort_key  = isset($order_columns[$sort]) ? $sort : 'date';
$order_sql = $order_columns[$sort_key];

$registrations = admin_get_registrations($pdo, $status_filter, $order_sql);
$all_kids      = admin_get_kids_list($pdo, $status_filter);

function sort_url($list_url, $status_filter, $sort, $dir, $column) {
    $params = [];
    if ($status_filter !== '') $params['status'] = $status_filter;
    $params['sort'] = $column;
    $params['dir'] = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    return $list_url . '?' . http_build_query($params);
}

$csv = isset($_GET['export']) && $_GET['export'] === 'csv';
if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vbs-registrations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Parent name', 'Email', 'Phone', 'Kids', 'Photo consent', 'Payment Status', 'Created']);
    foreach ($registrations as $r) {
        fputcsv($out, [
            $r['id'],
            $r['parent_first_name'] . ' ' . $r['parent_last_name'],
            $r['email'],
            $r['phone'],
            $r['kid_count'],
            $r['photo_consent'] ?? '',
            $r['status'],
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

layout_head('Admin – Registrations');
admin_nav('registrations');
?>
<div class="max-w-5xl mx-auto px-4 py-6">
  <h1 class="text-2xl font-bold text-gray-900 mb-6">Registrations</h1>

  <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <div class="flex gap-2 flex-wrap">
      <?php $list_url = APP_URL . '/admin/registrations'; ?>
      
    </div>
    <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
      <button type="button" id="view-parents-btn" onclick="setView('parents')" class="px-4 py-1.5 bg-indigo-600 text-white">Show Parents</button>
      <button type="button" id="view-kids-btn" onclick="setView('kids')" class="px-4 py-1.5 bg-white text-gray-700 hover:bg-gray-50">Show Children</button>
    </div>
  </div>

  <!-- Parents table -->
  <div id="parents-view" class="card overflow-x-auto p-0">
    <table class="w-full text-left">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'parent') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Parent<?= $sort_key === 'parent' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'email') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Email<?= $sort_key === 'email' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'kids') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Kids<?= $sort_key === 'kids' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'photo') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Photo consent<?= $sort_key === 'photo' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'status') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Payment Status<?= $sort_key === 'status' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
          <th class="px-4 py-3 text-sm font-semibold text-gray-700">
            <a href="<?= sort_url($list_url, $status_filter, $sort_key, $dir, 'date') ?>" class="inline-flex items-center gap-1 hover:text-indigo-600">Registered Date<?= $sort_key === 'date' ? ' ' . ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($registrations as $r):
          $parent_anchor = str_replace(' ', '', trim(($r['parent_first_name'] ?? '') . ($r['parent_last_name'] ?? '')));
          $view_url = APP_URL . '/admin/registrations/view?id=' . (int) $r['id'] . ($parent_anchor !== '' ? '#' . htmlspecialchars($parent_anchor) : '');
        ?>
        <tr class="border-b border-gray-100 hover:bg-gray-50/50">
          <td class="px-4 py-3">
            <a href="<?= $view_url ?>" class="text-indigo-600 hover:underline"><?= htmlspecialchars($r['parent_first_name'] . ' ' . $r['parent_last_name']) ?></a>
          </td>
          <td class="px-4 py-3"><?= htmlspecialchars($r['email']) ?></td>
          <td class="px-4 py-3"><?= (int) $r['kid_count'] ?></td>
          <td class="px-4 py-3">
            <?php
            $pc = $r['photo_consent'] ?? '';
            $pc_yes = strtolower((string) $pc) === 'yes';
            $pc_no = strtolower((string) $pc) === 'no';
            $pc_bg = $pc_no ? 'bg-red-500 text-white' : ($pc_yes ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700');
            $pc_text = $pc_no ? 'No' : ($pc_yes ? 'Yes' : ($pc !== '' ? htmlspecialchars($pc) : '—'));
            ?>
            <span class="px-2 py-1 rounded text-xs font-medium <?= $pc_bg ?>"><?= $pc_text ?></span>
          </td>
          <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs <?= $r['status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= $r['created_at'] ? date('M j, Y g:i A', strtotime($r['created_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($registrations)): ?>
    <p class="px-4 py-8 text-gray-500 text-center">No registrations found.</p>
    <?php endif; ?>
  </div>

  <?php
    $kid_groups  = array_values(array_unique(array_filter(array_column($all_kids, 'group_name'))));
    $kid_ages    = array_values(array_unique(array_filter(array_map(fn($k) => $k['age'] !== null && $k['age'] !== '' ? (string)(int)$k['age'] : '', $all_kids))));
    $kid_genders = array_values(array_unique(array_filter(array_column($all_kids, 'gender'))));
    $kid_grades  = array_values(array_unique(array_filter(array_column($all_kids, 'last_grade_completed'))));
    sort($kid_groups); sort($kid_ages, SORT_NUMERIC); sort($kid_genders); sort($kid_grades);
  ?>
  <!-- Children table -->
  <div id="kids-view" class="hidden">
    <!-- Filters -->
    <div class="flex items-center gap-2 mb-2 overflow-x-auto pb-1">
      <input type="search" id="filter-search" class="input-field text-sm py-1.5 shrink-0" placeholder="Search child or parent…" style="width:200px;">
      <select id="filter-group" class="input-field text-sm py-1.5 shrink-0" style="width:130px;">
        <option value=""> Groups</option>
        <?php foreach ($kid_groups as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
        <?php endforeach; ?>
        <option value="__none__">— Unassigned —</option>
      </select>
      <select id="filter-age" class="input-field text-sm py-1.5 shrink-0" style="width:90px;">
        <option value="">Ages</option>
        <?php foreach ($kid_ages as $a): ?>
        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filter-gender" class="input-field text-sm py-1.5 shrink-0" style="width:100px;">
        <option value="">Genders</option>
        <?php
          $gd_map = ['male' => 'Boy', 'female' => 'Girl', 'boy' => 'Boy', 'girl' => 'Girl'];
          $gender_labels = array_values(array_unique(array_map(fn($g) => $gd_map[strtolower($g)] ?? $g, $kid_genders)));
          sort($gender_labels);
          foreach ($gender_labels as $g_label):
        ?>
        <option value="<?= htmlspecialchars($g_label) ?>"><?= htmlspecialchars($g_label) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filter-grade" class="input-field text-sm py-1.5 shrink-0" style="width:110px;">
        <option value="">Grades</option>
        <?php foreach ($kid_grades as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" id="filter-clear" class="btn-secondary text-sm py-1.5 px-3 shrink-0">Clear</button>
      <span id="filter-count" class="shrink-0 text-sm text-gray-500 whitespace-nowrap"></span>
    </div>
    <div class="card overflow-x-auto p-0">
      <table class="w-full text-left" id="kids-table">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Child</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Group</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Age</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">DOB</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Gender</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Grade</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">T-Shirt</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Medical / Allergies</th>
            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Parent</th>
          </tr>
        </thead>
        <tbody id="kids-tbody">
          <?php foreach ($all_kids as $k):
            $kid_anchor = str_replace(' ', '', trim(($k['first_name'] ?? '') . ($k['last_name'] ?? '')));
            $kid_view_url = APP_URL . '/admin/registrations/view?id=' . (int) $k['registration_id'] . ($kid_anchor !== '' ? '#' . htmlspecialchars($kid_anchor) : '');
            $parent_anchor = str_replace(' ', '', trim(($k['parent_first_name'] ?? '') . ($k['parent_last_name'] ?? '')));
            $parent_view_url = APP_URL . '/admin/registrations/view?id=' . (int) $k['registration_id'] . ($parent_anchor !== '' ? '#' . htmlspecialchars($parent_anchor) : '');
            $dob = !empty($k['date_of_birth']) ? date('M j, Y', strtotime($k['date_of_birth'])) : '—';
            $row_group  = $k['group_name'] ?? '';
            $row_age    = $k['age'] !== null && $k['age'] !== '' ? (string)(int)$k['age'] : '';
            $row_gender = $k['gender'] ?? '';
            $gender_map = ['male' => 'Boy', 'female' => 'Girl', 'boy' => 'Boy', 'girl' => 'Girl'];
            $row_gender_display = $row_gender !== '' ? ($gender_map[strtolower($row_gender)] ?? $row_gender) : '';
            $row_grade  = $k['last_grade_completed'] ?? '';
            $row_child  = strtolower(trim(($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? '')));
            $row_parent = strtolower(trim(($k['parent_first_name'] ?? '') . ' ' . ($k['parent_last_name'] ?? '')));
          ?>
          <tr class="border-b border-gray-100 hover:bg-gray-50/50"
              data-group="<?= htmlspecialchars($row_group) ?>"
              data-age="<?= htmlspecialchars($row_age) ?>"
              data-gender="<?= htmlspecialchars($row_gender_display) ?>"
              data-grade="<?= htmlspecialchars($row_grade) ?>"
              data-child="<?= htmlspecialchars($row_child) ?>"
              data-parent="<?= htmlspecialchars($row_parent) ?>">
            <td class="px-4 py-3">
              <a href="<?= $kid_view_url ?>" class="text-indigo-600 hover:underline font-medium"><?= htmlspecialchars(($k['first_name'] ?? '') . ' ' . ($k['last_name'] ?? '')) ?></a>
            </td>
            <td class="px-4 py-3 text-sm">
              <?php if (!empty($k['group_name'])): ?>
                <span class="px-1.5 py-0.5 rounded text-xs bg-indigo-100 text-indigo-700 font-medium"><?= htmlspecialchars($k['group_name']) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="px-4 py-3 text-sm"><?= $row_age !== '' ? $row_age : '—' ?></td>
            <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($dob) ?></td>
            <td class="px-4 py-3 text-sm"><?= $row_gender_display !== '' ? htmlspecialchars($row_gender_display) : '—' ?></td>
            <td class="px-4 py-3 text-sm"><?= $row_grade !== '' ? htmlspecialchars($row_grade) : '—' ?></td>
            <td class="px-4 py-3 text-sm"><?= $k['t_shirt_size'] !== null && $k['t_shirt_size'] !== '' ? htmlspecialchars($k['t_shirt_size']) : '—' ?></td>
            <td class="px-4 py-3 text-sm text-gray-600"><?= $k['medical_allergy_info'] !== null && $k['medical_allergy_info'] !== '' ? htmlspecialchars($k['medical_allergy_info']) : '—' ?></td>
            <td class="px-4 py-3 text-sm">
              <a href="<?= $parent_view_url ?>" class="text-indigo-600 hover:underline"><?= htmlspecialchars(($k['parent_first_name'] ?? '') . ' ' . ($k['parent_last_name'] ?? '')) ?></a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (empty($all_kids)): ?>
      <p class="px-4 py-8 text-gray-500 text-center">No children found.</p>
      <?php endif; ?>
    </div>
  </div>

  <script>
    function setView(v) {
      var parentsView = document.getElementById('parents-view');
      var kidsView = document.getElementById('kids-view');
      var parentsBtn = document.getElementById('view-parents-btn');
      var kidsBtn = document.getElementById('view-kids-btn');
      if (v === 'kids') {
        parentsView.classList.add('hidden');
        kidsView.classList.remove('hidden');
        parentsBtn.className = 'px-4 py-1.5 bg-white text-gray-700 hover:bg-gray-50';
        kidsBtn.className = 'px-4 py-1.5 bg-indigo-600 text-white';
        sessionStorage.setItem('reg_view', 'kids');
      } else {
        kidsView.classList.add('hidden');
        parentsView.classList.remove('hidden');
        parentsBtn.className = 'px-4 py-1.5 bg-indigo-600 text-white';
        kidsBtn.className = 'px-4 py-1.5 bg-white text-gray-700 hover:bg-gray-50';
        sessionStorage.setItem('reg_view', 'parents');
      }
    }
    // Restore last selection within the session
    if (sessionStorage.getItem('reg_view') === 'kids') setView('kids');

    // Kids table filtering
    var filterSearch = document.getElementById('filter-search');
    var filterGroup  = document.getElementById('filter-group');
    var filterAge    = document.getElementById('filter-age');
    var filterGender = document.getElementById('filter-gender');
    var filterGrade  = document.getElementById('filter-grade');
    var filterClear  = document.getElementById('filter-clear');
    var filterCount  = document.getElementById('filter-count');
    var kidsTbody    = document.getElementById('kids-tbody');

    function applyFilters() {
      var search = filterSearch.value.trim().toLowerCase();
      var group  = filterGroup.value;
      var age    = filterAge.value;
      var gender = filterGender.value;
      var grade  = filterGrade.value;
      var rows   = kidsTbody.querySelectorAll('tr');
      var visible = 0;
      rows.forEach(function(row) {
        var rChild  = row.getAttribute('data-child')  || '';
        var rParent = row.getAttribute('data-parent') || '';
        var rGroup  = row.getAttribute('data-group')  || '';
        var rAge    = row.getAttribute('data-age')    || '';
        var rGender = row.getAttribute('data-gender') || '';
        var rGrade  = row.getAttribute('data-grade')  || '';
        var show =
          (search === '' || rChild.includes(search) || rParent.includes(search)) &&
          (group  === '' || (group === '__none__' ? rGroup === '' : rGroup === group)) &&
          (age    === '' || rAge    === age)    &&
          (gender === '' || rGender === gender) &&
          (grade  === '' || rGrade  === grade);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      filterCount.textContent = visible + ' of ' + rows.length + ' children';
    }

    filterSearch.addEventListener('input', applyFilters);
    [filterGroup, filterAge, filterGender, filterGrade].forEach(function(el) {
      el.addEventListener('change', applyFilters);
    });

    filterClear.addEventListener('click', function() {
      filterSearch.value = '';
      filterGroup.value = '';
      filterAge.value = '';
      filterGender.value = '';
      filterGrade.value = '';
      applyFilters();
    });

    applyFilters();
  </script>
</div>
<?php layout_footer(); ?>
</body>
</html>
