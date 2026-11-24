<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Only unauthenticated guests can access the login portal
requireGuest();

$errorMessage = '';
$savedEmail = $_COOKIE['hiroba_saved_email'] ?? '';
$emailInput = $savedEmail;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $errorMessage = 'Security validation failed (CSRF). Please refresh the page and try again.';
  } else {
    $emailInput = trim((string)($_POST['email'] ?? ''));
    $passwordInput = (string)($_POST['password'] ?? '');
    $rememberEmail = isset($_POST['remember_email']);

    if ($emailInput === '' || $passwordInput === '') {
      $errorMessage = 'Please enter both your university email address and password.';
    } else {
      $loginResult = attemptLogin($emailInput, $passwordInput, $rememberEmail);

      if ($loginResult['success']) {
        setFlash('success', 'Welcome back, ' . ($loginResult['user']['name'] ?? 'Student') . '!');
        if (isAdmin()) {
          redirect('admin.php');
        } else {
          redirect('dashboard.php');
        }
      } else {
        $errorMessage = $loginResult['message'];
      }
    }
  }
}

renderHeader('Sign In - Academic Student Marketplace', ['current_page' => 'login']);
?>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-5">
      <div class="card auth-card border-0 p-3 p-sm-4 p-md-5">
        <!-- Heading -->
        <div class="text-center mb-4">
          <span class="eyebrow">Student Authentication</span>
          <h1 class="h2 mt-1 mb-2">Sign in to Hiroba</h1>
          <p class="body-text small text-muted mb-0">Enter your university credentials to access your campus workspace.</p>
        </div>

        <!-- Feedback Alert Banner -->
        <?php if ($errorMessage !== ''): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger); font-size: 0.88rem;">
            <?= renderIcon('alert', 'icon flex-shrink-0') ?>
            <div><?= e($errorMessage) ?></div>
          </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="<?= e(url('login.php')) ?>" method="POST" novalidate>
          <?= csrfField() ?>

          <div class="mb-3">
            <label for="login-email" class="form-label">University Email Address</label>
            <input 
              type="email" 
              name="email" 
              id="login-email" 
              class="form-control <?= ($errorMessage !== '' ? 'is-invalid' : '') ?>" 
              placeholder="e.g., student@campus.edu.ph" 
              value="<?= e($emailInput) ?>" 
              required 
              autocomplete="email"
              autofocus
            >
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <label for="login-password" class="form-label mb-0">Password</label>
            </div>
            <input 
              type="password" 
              name="password" 
              id="login-password" 
              class="form-control mt-1 <?= ($errorMessage !== '' ? 'is-invalid' : '') ?>" 
              placeholder="Enter your account password" 
              required 
              autocomplete="current-password"
            >
          </div>

          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
              <input 
                class="form-check-input" 
                type="checkbox" 
                name="remember_email" 
                id="remember_email" 
                value="1" 
                <?= ($savedEmail !== '' ? 'checked' : '') ?>
              >
              <label class="form-check-label small text-muted" for="remember_email">
                Remember my email
              </label>
            </div>
          </div>

          <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center">
            <?= renderIcon('check', 'icon') ?>
            <span>Sign In</span>
          </button>
        </form>

        <!-- Recovery Help Section -->
        <div class="mt-4 pt-3 border-top text-center">
          <small class="text-muted d-block mb-2">
            Forgot your password? Account recovery is facilitated directly by Hiroba Administrators to ensure student identity security.
          </small>
          <p class="small text-muted mb-0">
            Do not have an account? <a href="<?= e(url('register.php')) ?>" class="text-decoration-none" style="color: var(--accent); font-weight: 400;">Join Plaza here</a>.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
renderFooter();
