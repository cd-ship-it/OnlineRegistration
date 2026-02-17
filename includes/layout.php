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
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['DM Sans', 'system-ui', 'sans-serif'] }
        }
      }
    }
  </script>
  <style type="text/tailwindcss">
    @layer components {
      .btn-primary { @apply px-4 py-2 rounded-lg font-medium bg-indigo-600 text-white hover:bg-indigo-700 transition; }
      .btn-secondary { @apply px-4 py-2 rounded-lg font-medium bg-gray-200 text-gray-800 hover:bg-gray-300 transition; }
      .input-field { @apply w-full rounded-lg border border-gray-300 px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500; }
      .card { @apply bg-white rounded-xl shadow-sm border border-gray-100 p-6 transition-colors duration-150; }
      .card:focus-within { @apply bg-sky-50; }
      .kid-block { @apply transition-colors duration-150; }
      .kid-block:focus-within { @apply bg-sky-50; }
    }
  </style>
</head>
<body class="min-h-screen font-sans text-gray-900 bg-gradient-to-br from-orange-50 via-amber-50 to-sky-100">
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
