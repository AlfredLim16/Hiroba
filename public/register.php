<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Only unauthenticated guests can register for new accounts
requireGuest();

$errors = [];
$nameInput = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $errors['general'] = 'Security validation failed (CSRF). Please refresh the page and try again.';
  } else {
    $nameInput = trim((string)($_POST['name'] ?? ''));
    $emailInput = trim((string)($_POST['email'] ?? ''));
    $passwordInput = (string)($_POST['password'] ?? '');
    $passwordConfirmInput = (string)($_POST['password_confirm'] ?? '');
    $acceptTerms = isset($_POST['accept_terms']);

    // Validation Rules
    if ($nameInput === '') {
      $errors['name'] = 'Full legal or campus name is required.';
    } elseif (mb_strlen($nameInput) > 80) {
      $errors['name'] = 'Name cannot exceed 80 characters.';
    }

    if ($emailInput === '') {
      $errors['email'] = 'University email address is required.';
    } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = 'Please enter a valid email address.';
    } elseif (mb_strlen($emailInput) > 100) {
      $errors['email'] = 'Email address cannot exceed 100 characters.';
    }

    if ($passwordInput === '') {
      $errors['password'] = 'Password is required.';
    } elseif (strlen($passwordInput) < 8) {
      $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($passwordConfirmInput !== $passwordInput) {
      $errors['password_confirm'] = 'Passwords do not match.';
    }

    if (!$acceptTerms) {
      $errors['terms'] = 'You must agree to the academic integrity terms.';
    }

    // Process Registration if validation succeeds
    if (empty($errors)) {
      $regResult = registerStudent($nameInput, $emailInput, $passwordInput);

      if ($regResult['success']) {
        // Automatically authenticate the new user
        attemptLogin($emailInput, $passwordInput, false);
        setFlash('success', 'Your student account has been created successfully. Welcome to Hiroba!');
        redirect('dashboard.php');
      } else {
        $errors['email'] = $regResult['message'];
      }
    }
  }
}

renderHeader('Join Marketplace - Academic Student Registration', ['current_page' => 'register']);
?>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-9 col-lg-6">
      <div class="card auth-card border-0 p-3 p-sm-4 p-md-5">
        <!-- Heading -->
        <div class="text-center mb-4">
          <span class="eyebrow">Academic Student Registration</span>
          <h1 class="h2 mt-1 mb-2">Create your Hiroba Account</h1>
          <p class="body-text small text-muted mb-0">Join your campus community to discover services, hire student freelancers, or offer skills.</p>
        </div>

        <!-- General Alert Banner -->
        <?php if (!empty($errors['general'])): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger); font-size: 0.88rem;">
            <?= renderIcon('alert', 'icon flex-shrink-0') ?>
            <div><?= e($errors['general']) ?></div>
          </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <form action="<?= e(url('register.php')) ?>" method="POST" novalidate>
          <?= csrfField() ?>

          <div class="mb-3">
            <label for="reg-name" class="form-label">Full Name</label>
            <input 
              type="text" 
              name="name" 
              id="reg-name" 
              class="form-control <?= (!empty($errors['name']) ? 'is-invalid' : '') ?>" 
              placeholder="e.g., Juan Dela Cruz" 
              value="<?= e($nameInput) ?>" 
              required 
              autocomplete="name"
              autofocus
            >
            <?php if (!empty($errors['name'])): ?>
              <div class="invalid-feedback"><?= e($errors['name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label for="reg-email" class="form-label">University Email Address</label>
            <input 
              type="email" 
              name="email" 
              id="reg-email" 
              class="form-control <?= (!empty($errors['email']) ? 'is-invalid' : '') ?>" 
              placeholder="e.g., yourname@campus.edu.ph" 
              value="<?= e($emailInput) ?>" 
              required 
              autocomplete="email"
            >
            <?php if (!empty($errors['email'])): ?>
              <div class="invalid-feedback"><?= e($errors['email']) ?></div>
            <?php endif; ?>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="reg-password" class="form-label">Password (Min. 8 chars)</label>
              <input 
                type="password" 
                name="password" 
                id="reg-password" 
                class="form-control <?= (!empty($errors['password']) ? 'is-invalid' : '') ?>" 
                placeholder="Choose a strong password" 
                required 
                autocomplete="new-password"
              >
              <?php if (!empty($errors['password'])): ?>
                <div class="invalid-feedback"><?= e($errors['password']) ?></div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label for="reg-password-confirm" class="form-label">Confirm Password</label>
              <input 
                type="password" 
                name="password_confirm" 
                id="reg-password-confirm" 
                class="form-control <?= (!empty($errors['password_confirm']) ? 'is-invalid' : '') ?>" 
                placeholder="Repeat password" 
                required 
                autocomplete="new-password"
              >
              <?php if (!empty($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= e($errors['password_confirm']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="mb-4">
            <div class="form-check">
              <input 
                class="form-check-input <?= (!empty($errors['terms']) ? 'is-invalid' : '') ?>" 
                type="checkbox" 
                name="accept_terms" 
                id="accept_terms" 
                value="1" 
                required
              >
              <label class="form-check-label small text-muted" for="accept_terms">
                I agree to the <a href="#codeOfConductModal" data-bs-toggle="modal" data-bs-target="#codeOfConductModal" class="text-decoration-underline" style="color: var(--accent);">Hiroba Code of Conduct</a> and commit to upholding peer collaboration and academic integrity standards.
              </label>
              <?php if (!empty($errors['terms'])): ?>
                <div class="invalid-feedback d-block"><?= e($errors['terms']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center">
            <?= renderIcon('check', 'icon') ?>
            <span>Complete Registration</span>
          </button>
        </form>

        <div class="mt-4 pt-3 border-top text-center">
          <p class="small text-muted mb-0">
            Already have an account? <a href="<?= e(url('login.php')) ?>" class="text-decoration-none" style="color: var(--accent); font-weight: 400;">Sign in here</a>.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
renderFooter();
