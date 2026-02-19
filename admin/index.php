<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim($_POST['username'] ?? '');
  $pass = $_POST['password'] ?? '';
  if (admin_login($user, $pass)) {
    header('Location: ' . APP_URL . '/admin/settings', true, 302);
    exit;
  }
  $error = 'Invalid username or password.';
}

if (!empty($_SESSION['admin_logged_in'])) {
  header('Location: ' . APP_URL . '/admin/settings', true, 302);
  exit;
}

layout_head('Admin Login');
?>
<div class="min-h-screen flex items-center justify-center px-4 bg-gray-100">
  <div class="card w-full max-w-md">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Admin Login</h1>
    <?php if ($error): ?>
      <p class="text-red-600 text-sm mb-4"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" action="">
      <div class="mb-4">
        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="input-field">
      </div>
      <div class="mb-6">
        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password"
          class="input-field">
      </div>
      <button type="submit" class="btn-primary w-full">Sign in</button>
    </form>

    <div class="mt-6">
      <div class="relative">
        <div class="absolute inset-0 flex items-center">
          <div class="w-full border-t border-gray-300"></div>
        </div>
        <div class="relative flex justify-center text-sm"><span class="px-2 bg-gray-100 text-gray-500">Or continue
            with</span></div>
      </div>
      <div class="mt-6">
        <a href="<?= APP_URL ?>/admin/google-login.php"
          class="flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
          <img src="https://www.gstatic.com/images/branding/product/1x/googleg_48dp.png" alt="Google"
            class="h-5 w-5 mr-2">
          Sign in with Google
        </a>
      </div>
    </div>

    <p class="mt-4 text-center text-sm text-gray-500"><a href="<?= APP_URL ?>/register"
        class="text-indigo-600 hover:underline">Back to registration</a></p>
  </div>
</div>
<?php layout_footer(); ?>
</body>

</html>