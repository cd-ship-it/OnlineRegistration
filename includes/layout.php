<?php
/**
 * Shared HTML head with Tailwind CDN. Call with layout_head() before </head>.
 */
function layout_head($title = 'VBS Registration') {
    $title = htmlspecialchars($title);
    $base = defined('APP_URL') ? APP_URL : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $title ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/css/app.css">
</head>
<body class="min-h-screen font-sans text-gray-900 bg-gradient-to-br from-orange-50 via-amber-50 to-sky-100">
<?php
}

/**
 * Render the shared admin top nav bar.
 *
 * @param string $active  Key of the current page: 'registrations' | 'groups' |
 *                        'assigngroups' | 'settings' (highlights that link)
 */
function admin_nav(string $active = '') {
    $base = defined('APP_URL') ? APP_URL : '';

    $links = [
        'registrations' => ['Registrations', $base . '/admin/registrations'],
        'groups'        => ['Groups',         $base . '/admin/groups'],
        'assigngroups'  => ['Assign Groups',  $base . '/admin/assigngroups'],
        'settings'      => ['Settings',       $base . '/admin/settings'],
    ];
    ?>
<nav class="bg-white border-b border-gray-200 sticky top-0 z-40 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 flex items-center justify-between h-12">
    <div class="flex items-center gap-1">
      <a href="<?= $base ?>/admin" class="shrink-0 mr-2">
        <img src="<?= $base ?>/img/Xpt-ID2015_color_round-1.png" alt="Crosspoint" class="h-8 w-8 object-contain">
      </a>
      <?php foreach ($links as $key => [$label, $href]): ?>
        <?php $isActive = ($key === $active); ?>
        <a href="<?= $href ?>"
           class="px-3 py-1.5 rounded text-sm font-medium transition
                  <?= $isActive
                      ? 'bg-indigo-50 text-indigo-700'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>
    <a href="<?= $base ?>/admin/logout"
       class="text-sm text-gray-500 hover:text-gray-700 hover:underline">
      Logout
    </a>
  </div>
</nav>
<?php
}

function layout_footer() {
    $year = date('Y');
    ?>

  <div class="mt-10 flex justify-center">
    <img src="https://crosspointchurchsv.org/branding/logos/Xpt-ID2015-1_1400x346.png" alt="Crosspoint Church" class="max-w-xs sm:max-w-md h-auto" width="250">
  </div>
<footer class="mt-auto py-6 text-center text-sm text-gray-500">
  &copy; <?= $year ?> <a href="https://crosspointchurchsv.org" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">Crosspoint Church</a>. All rights reserved.
  <p><a target="_blank" rel="noopener noreferrer" href="<?= defined('APP_URL') ? rtrim(APP_URL, '/') : '' ?>/privacy_summary.html" class="text-indigo-600 hover:underline">Our Privacy Promise</a>
</footer>
<?php
}
