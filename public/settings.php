<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/upload.php';
require_once __DIR__ . '/../src/layout.php';

// Authentication required
requireLogin();

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];

// Fetch seller profile status if exists
$sellerProfileStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :user_id');
$sellerProfileStmt->execute([':user_id' => $userId]);
$sellerProfile = $sellerProfileStmt->fetch();

$accountErrors = [];
$sellerErrors = [];

// Handle Profile Updates and Seller Application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    setFlash('danger', 'Security validation failed (CSRF). Please try again.');
    redirect('settings.php');
  }

  $formType = (string)($_POST['form_type'] ?? '');

  // Form 1: Account Profile Update
  if ($formType === 'update_profile') {
    $name = trim((string)($_POST['name'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');

    if ($name === '') {
      $accountErrors['name'] = 'Full name is required.';
    } elseif (mb_strlen($name) > 80) {
      $accountErrors['name'] = 'Name cannot exceed 80 characters.';
    }

    // Password change verification if provided
    $passwordChanged = false;
    if ($newPassword !== '') {
      if (strlen($newPassword) < 8) {
        $accountErrors['new_password'] = 'New password must be at least 8 characters long.';
      } else {
        // Verify current password against database
        $pwdCheck = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $pwdCheck->execute([':id' => $userId]);
        $hash = (string)$pwdCheck->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
          $accountErrors['current_password'] = 'Current password is incorrect.';
        } else {
          $passwordChanged = true;
        }
      }
    }

    if (empty($accountErrors)) {
      if ($passwordChanged) {
        $upd = $pdo->prepare('UPDATE users SET name = :name, password_hash = :hash WHERE id = :id');
        $upd->execute([
          ':name' => $name,
          ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
          ':id' => $userId
        ]);
      } else {
        $upd = $pdo->prepare('UPDATE users SET name = :name WHERE id = :id');
        $upd->execute([':name' => $name, ':id' => $userId]);
      }

      $_SESSION['user_name'] = $name;
      setFlash('success', 'Your account settings have been updated.');
      redirect('settings.php');
    }
  }

  // Form 2: Apply to Become a Seller / Update Seller Bio
  elseif ($formType === 'apply_seller') {
    if (isAdmin()) {
      setFlash('danger', 'Administrator accounts cannot apply to become sellers. Please register a separate student account to offer services.');
      redirect('settings.php');
    }
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $bio = trim((string)($_POST['bio'] ?? ''));

    if ($studentId === '') {
      $sellerErrors['student_id'] = 'Student ID number is mandatory for campus vetting.';
    } elseif (mb_strlen($studentId) > 30) {
      $sellerErrors['student_id'] = 'Student ID cannot exceed 30 characters.';
    }

    if ($bio === '') {
      $sellerErrors['bio'] = 'Please write a brief summary of your skills and services.';
    }

    $photoPath = $sellerProfile['photo_path'] ?? null;
    $idPhotoPath = $sellerProfile['id_card_path'] ?? null;

    // Process Profile Avatar Upload with GD EXIF Stripping
    if (isset($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
      $upPhoto = processImageUpload($_FILES['photo'], 'avatars');
      if ($upPhoto['success']) {
        $photoPath = $upPhoto['path'];
      } else {
        $sellerErrors['photo'] = $upPhoto['message'];
      }
    }

    // Process Student ID Document Upload with GD EXIF Stripping
    if (isset($_FILES['id_photo']) && !empty($_FILES['id_photo']['name'])) {
      $upId = processImageUpload($_FILES['id_photo'], 'ids');
      if ($upId['success']) {
        $idPhotoPath = $upId['path'];
      } else {
        $sellerErrors['id_photo'] = $upId['message'];
      }
    } elseif (!$sellerProfile) {
      $sellerErrors['id_photo'] = 'A scanned photo of your university student ID is required for verification.';
    }

    if (empty($sellerErrors)) {
      if (!$sellerProfile) {
        // Insert new pending seller application
        $ins = $pdo->prepare('
          INSERT INTO seller_profiles (user_id, student_id, bio, photo_path, id_card_path, status, created_at, updated_at)
          VALUES (:user_id, :student_id, :bio, :photo_path, :id_doc, \'pending\', NOW(), NOW())
        ');
        $ins->execute([
          ':user_id' => $userId,
          ':student_id' => $studentId,
          ':bio' => $bio,
          ':photo_path' => $photoPath ?? 'assets/images/default-avatar.png',
          ':id_doc' => $idPhotoPath
        ]);

        setFlash('success', 'Your seller accreditation application has been submitted for Hiroba Administrator review.');
      } else {
        // Update existing seller profile
        $upd = $pdo->prepare('
          UPDATE seller_profiles 
          SET student_id = :student_id,
              bio = :bio,
              photo_path = :photo_path,
              id_card_path = :id_doc,
              updated_at = NOW()
          WHERE user_id = :user_id
        ');
        $upd->execute([
          ':student_id' => $studentId,
          ':bio' => $bio,
          ':photo_path' => $photoPath,
          ':id_doc' => $idPhotoPath,
          ':user_id' => $userId
        ]);

        setFlash('success', 'Your seller profile information has been saved.');
      }

      redirect('settings.php');
    }
  }
}

renderHeader('Account Settings — Hiroba', ['current_page' => 'settings']);
?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
      <!-- Title -->
      <div class="mb-4 pb-2 border-bottom">
        <span class="eyebrow">User Management</span>
        <h1 class="h2 mt-1 mb-0">Account & Profile Settings</h1>
      </div>

      <!-- Section 1: Account Information -->
      <div class="card p-4 shadow-sm mb-4" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
        <h2 class="h3 mb-3">Personal Account Details</h2>

        <form action="<?= e(url('settings.php')) ?>" method="POST" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="form_type" value="update_profile">

          <div class="mb-3">
            <label for="acc-name" class="form-label">Full Name</label>
            <input type="text" name="name" id="acc-name" class="form-control <?= !empty($accountErrors['name']) ? 'is-invalid' : '' ?>" value="<?= e($currentUser['name']) ?>" required>
            <?php if (!empty($accountErrors['name'])): ?>
              <div class="invalid-feedback"><?= e($accountErrors['name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label for="acc-email" class="form-label">University Email</label>
            <input type="email" id="acc-email" class="form-control" value="<?= e($currentUser['email']) ?>" disabled readonly>
            <small class="text-muted">University email addresses are permanent and tied to your campus identity.</small>
          </div>

          <hr class="my-4" style="border-color: var(--line);">

          <h3 class="h4 mb-3" style="font-size: 1.05rem;">Change Password</h3>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="acc-curr-pwd" class="form-label">Current Password</label>
              <input type="password" name="current_password" id="acc-curr-pwd" class="form-control <?= !empty($accountErrors['current_password']) ? 'is-invalid' : '' ?>" placeholder="Leave blank to keep unchanged">
              <?php if (!empty($accountErrors['current_password'])): ?>
                <div class="invalid-feedback"><?= e($accountErrors['current_password']) ?></div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label for="acc-new-pwd" class="form-label">New Password (Min. 8 chars)</label>
              <input type="password" name="new_password" id="acc-new-pwd" class="form-control <?= !empty($accountErrors['new_password']) ? 'is-invalid' : '' ?>" placeholder="Enter new strong password">
              <?php if (!empty($accountErrors['new_password'])): ?>
                <div class="invalid-feedback"><?= e($accountErrors['new_password']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="text-end">
            <button type="submit" class="btn-tactile btn-accent">Save Account Changes</button>
          </div>
        </form>
      </div>

      <!-- Section 2: Seller Accreditation Application -->
      <?php if (!isAdmin()): ?>
      <div class="card p-4 shadow-sm" id="seller-application" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div>
            <h2 class="h3 mb-1">Seller Accreditation Desk</h2>
            <small class="text-muted">Sell services, take project commissions, and showcase your portfolio.</small>
          </div>
          <?php if ($sellerProfile): ?>
            <?php
              $statusPillClass = [
                'approved' => 'pill--completed',
                'pending' => 'pill--pending',
                'rejected' => 'pill--cancelled',
                'suspended' => 'pill--cancelled'
              ][$sellerProfile['status']] ?? 'pill--pending';
            ?>
            <span class="pill <?= $statusPillClass ?>">
              Status: <?= e(ucfirst((string)$sellerProfile['status'])) ?>
            </span>
          <?php endif; ?>
        </div>

        <?php if ($sellerProfile && $sellerProfile['status'] === 'pending'): ?>
          <div class="alert alert-warning d-flex align-items-center gap-2 mb-4" style="background-color: var(--warn-soft); border-color: transparent; color: var(--warn);">
            <?= renderIcon('clock', 'icon flex-shrink-0') ?>
            <div>
              <strong>Application Under Review.</strong> Hiroba Administrators are reviewing your accreditation credentials. You will be notified once vetted.
            </div>
          </div>
        <?php elseif ($sellerProfile && $sellerProfile['status'] === 'suspended'): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger);">
            <?= renderIcon('alert', 'icon flex-shrink-0') ?>
            <div>
              <strong>Account Suspended.</strong> Your seller privileges are temporarily suspended. Please contact the Hiroba Administration.
            </div>
          </div>
        <?php endif; ?>

        <form action="<?= e(url('settings.php')) ?>" method="POST" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="form_type" value="apply_seller">

          <div class="mb-3">
            <label for="seller-student-id" class="form-label">University Student ID Number</label>
            <input 
              type="text" 
              name="student_id" 
              id="seller-student-id" 
              class="form-control font-monospace <?= !empty($sellerErrors['student_id']) ? 'is-invalid' : '' ?>" 
              placeholder="e.g., 2023-01452" 
              value="<?= e($sellerProfile['student_id'] ?? '') ?>" 
              required
            >
            <?php if (!empty($sellerErrors['student_id'])): ?>
              <div class="invalid-feedback"><?= e($sellerErrors['student_id']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label for="seller-bio" class="form-label">Seller Bio & Skill Summary</label>
            <textarea 
              name="bio" 
              id="seller-bio" 
              rows="4" 
              class="form-control <?= !empty($sellerErrors['bio']) ? 'is-invalid' : '' ?>" 
              placeholder="Introduce your skills, academic background, design tools, or writing capabilities..." 
              required
            ><?= e($sellerProfile['bio'] ?? '') ?></textarea>
            <?php if (!empty($sellerErrors['bio'])): ?>
              <div class="invalid-feedback"><?= e($sellerErrors['bio']) ?></div>
            <?php endif; ?>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label for="seller-photo" class="form-label">Profile Avatar (Max 2MB)</label>
              <input type="file" name="photo" id="seller-photo" class="form-control" accept="image/jpeg,image/png,image/webp">
              <small class="text-muted">Display avatar shown on your public service cards.</small>
            </div>
            <div class="col-md-6">
              <label for="seller-id-photo" class="form-label">Student ID Document (Scanned/Photo, Max 2MB)</label>
              <input type="file" name="id_photo" id="seller-id-photo" class="form-control <?= !empty($sellerErrors['id_photo']) ? 'is-invalid' : '' ?>" accept="image/jpeg,image/png,image/webp">
              <small class="text-muted">All uploaded ID photos have EXIF GPS and camera metadata stripped automatically.</small>
              <?php if (!empty($sellerErrors['id_photo'])): ?>
                <div class="invalid-feedback d-block"><?= e($sellerErrors['id_photo']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="text-end">
            <button type="submit" class="btn-tactile btn-accent">
              <?= $sellerProfile ? 'Update Seller Application' : 'Submit Seller Application' ?>
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
renderFooter();
