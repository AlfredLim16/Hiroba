<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Authentication required (student reporting only; admins review reports in Admin Desk)
requireLogin();
if (isAdmin()) {
  redirect('admin.php?tab=reports');
}

$serviceId = max(0, (int)($_GET['service_id'] ?? ($_POST['service_id'] ?? 0)));
if ($serviceId === 0) {
  setFlash('danger', 'Invalid service listing.');
  redirect('index.php');
}

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];

// Verify service exists
$stmt = $pdo->prepare('SELECT id, title, seller_id FROM services WHERE id = :id');
$stmt->execute([':id' => $serviceId]);
$service = $stmt->fetch();

if (!$service) {
  setFlash('danger', 'The reported service listing does not exist.');
  redirect('index.php');
}

$errors = [];
$reason = 'academic_dishonesty';
$details = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $errors['general'] = 'Security validation failed (CSRF).';
  } else {
    $reason = trim((string)($_POST['reason'] ?? ''));
    $details = trim((string)($_POST['details'] ?? ''));

    $validReasons = [
      'academic_dishonesty',
      'prohibited_item',
      'scam_spam',
      'inappropriate_content',
      'other'
    ];

    if (!in_array($reason, $validReasons, true)) {
      $errors['reason'] = 'Please select a valid report reason.';
    }

    if ($details === '') {
      $errors['details'] = 'Please describe the issue or policy violation in detail.';
    } elseif (mb_strlen($details) < 10) {
      $errors['details'] = 'Report details must be at least 10 characters.';
    }

    if (empty($errors)) {
      try {
        $ins = $pdo->prepare('
          INSERT INTO reports (service_id, reporter_id, reason, details, status, created_at)
          VALUES (:service_id, :reporter_id, :reason, :details, \'pending\', NOW())
        ');
        $ins->execute([
          ':service_id' => $serviceId,
          ':reporter_id' => $userId,
          ':reason' => $reason,
          ':details' => $details
        ]);

        setFlash('success', 'Your report has been submitted to Hiroba Administrators for investigation.');
        redirect('service.php?id=' . $serviceId);
      } catch (PDOException $e) {
        $errors['general'] = 'Database error submitting report: ' . $e->getMessage();
      }
    }
  }
}

renderHeader('Report Service Listing — Hiroba', ['current_page' => 'plaza']);
?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
      <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
          <li class="breadcrumb-item"><a href="<?= e(url('service.php?id=' . $serviceId)) ?>" class="text-decoration-none text-muted">Back to Service</a></li>
          <li class="breadcrumb-item active" aria-current="page">Report Listing</li>
        </ol>
      </nav>

      <div class="card p-4 p-md-5 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
        <span class="eyebrow">Community Moderation</span>
        <h1 class="h2 mt-1 mb-2">Report Service Listing</h1>
        <p class="body-text small text-muted mb-4">
          Listing: <strong><?= e($service['title']) ?></strong>. Hiroba enforces strict campus policies and academic integrity standards.
        </p>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger);">
            <?= renderIcon('alert', 'icon flex-shrink-0') ?>
            <div><?= e($errors['general']) ?></div>
          </div>
        <?php endif; ?>

        <form action="<?= e(url('report.php')) ?>" method="POST" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="service_id" value="<?= $serviceId ?>">

          <div class="mb-3">
            <label for="report-reason" class="form-label">Violation Category</label>
            <select name="reason" id="report-reason" class="form-select <?= !empty($errors['reason']) ? 'is-invalid' : '' ?>" required>
              <option value="academic_dishonesty" <?= $reason === 'academic_dishonesty' ? 'selected' : '' ?>>Academic Dishonesty (Ghostwriting, Exam Cheating)</option>
              <option value="prohibited_item" <?= $reason === 'prohibited_item' ? 'selected' : '' ?>>Prohibited Good or Forbidden Campus Material</option>
              <option value="scam_spam" <?= $reason === 'scam_spam' ? 'selected' : '' ?>>Misleading / Scam / Spam Scope</option>
              <option value="inappropriate_content" <?= $reason === 'inappropriate_content' ? 'selected' : '' ?>>Inappropriate or Offensive Material</option>
              <option value="other" <?= $reason === 'other' ? 'selected' : '' ?>>Other University Policy Violation</option>
            </select>
            <?php if (!empty($errors['reason'])): ?>
              <div class="invalid-feedback"><?= e($errors['reason']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-4">
            <label for="report-details" class="form-label">Detailed Explanation</label>
            <textarea 
              name="details" 
              id="report-details" 
              rows="4" 
              class="form-control <?= !empty($errors['details']) ? 'is-invalid' : '' ?>" 
              placeholder="Provide context, evidence, or specific concerns for administrators..." 
              required
            ><?= e($details) ?></textarea>
            <?php if (!empty($errors['details'])): ?>
              <div class="invalid-feedback"><?= e($errors['details']) ?></div>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center justify-content-between pt-3 border-top">
            <a href="<?= e(url('service.php?id=' . $serviceId)) ?>" class="btn-tactile btn-outline">Cancel</a>
            <button type="submit" class="btn-tactile btn-accent">Submit Report</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
renderFooter();
