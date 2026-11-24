<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Strict Admin Gate
requireAdmin();

$pdo = getDbConnection();
$currentUser = currentUser();
$adminId = (int)$currentUser['id'];

$activeTab = trim((string)($_GET['tab'] ?? 'vetting'));
if (!in_array($activeTab, ['vetting', 'reports', 'categories', 'unlock', 'analytics', 'audit'], true)) {
  $activeTab = 'vetting';
}

// 1. Handle Admin Action POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    setFlash('danger', 'Security validation failed (CSRF).');
    redirect('admin.php?tab=' . $activeTab);
  }

  $action = trim((string)($_POST['admin_action'] ?? ''));

  try {
    // Action A: Approve Seller Application
    if ($action === 'approve_seller') {
      $profileId = max(0, (int)($_POST['profile_id'] ?? 0));
      $feedback = trim((string)($_POST['admin_feedback'] ?? 'Approved by Hiroba administration.'));

      dbTransaction(function (PDO $db) use ($profileId, $adminId, $feedback) {
        $upd = $db->prepare('
          UPDATE seller_profiles 
          SET status = \'approved\', verified_at = NOW(), admin_feedback = :feedback, updated_at = NOW() 
          WHERE id = :id
        ');
        $upd->execute([':id' => $profileId, ':feedback' => $feedback]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (\'seller_profiles\', :id, :actor_id, \'seller_approved\', :details, :ip)
        ');
        $audit->execute([
          ':id' => $profileId,
          ':actor_id' => $adminId,
          ':details' => $feedback,
          ':ip' => getClientIp()
        ]);
      });

      setFlash('success', 'Student seller application approved successfully.');
      redirect('admin.php?tab=vetting');
    }

    // Action B: Reject Seller Application
    elseif ($action === 'reject_seller') {
      $profileId = max(0, (int)($_POST['profile_id'] ?? 0));
      $feedback = trim((string)($_POST['admin_feedback'] ?? 'Application declined. Student credentials incomplete.'));

      dbTransaction(function (PDO $db) use ($profileId, $adminId, $feedback) {
        $upd = $db->prepare('
          UPDATE seller_profiles 
          SET status = \'rejected\', admin_feedback = :feedback, updated_at = NOW() 
          WHERE id = :id
        ');
        $upd->execute([':id' => $profileId, ':feedback' => $feedback]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (\'seller_profiles\', :id, :actor_id, \'seller_rejected\', :details, :ip)
        ');
        $audit->execute([
          ':id' => $profileId,
          ':actor_id' => $adminId,
          ':details' => $feedback,
          ':ip' => getClientIp()
        ]);
      });

      setFlash('warn', 'Seller application rejected with explanatory feedback.');
      redirect('admin.php?tab=vetting');
    }

    // Action C: Suspend Seller Account
    elseif ($action === 'suspend_seller') {
      $profileId = max(0, (int)($_POST['profile_id'] ?? 0));
      $sellerUserId = max(0, (int)($_POST['seller_user_id'] ?? 0));
      $reason = trim((string)($_POST['admin_feedback'] ?? 'Privileges suspended for university policy violation.'));

      dbTransaction(function (PDO $db) use ($profileId, $sellerUserId, $adminId, $reason) {
        $upd = $db->prepare('
          UPDATE seller_profiles 
          SET status = \'suspended\', admin_feedback = :reason, updated_at = NOW() 
          WHERE id = :id
        ');
        $upd->execute([':id' => $profileId, ':reason' => $reason]);

        // Hide active services
        $hide = $db->prepare('UPDATE services SET status = \'hidden\', updated_at = NOW() WHERE seller_id = :seller_id');
        $hide->execute([':seller_id' => $sellerUserId]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (\'seller_profiles\', :id, :actor_id, \'seller_suspended\', :details, :ip)
        ');
        $audit->execute([
          ':id' => $profileId,
          ':actor_id' => $adminId,
          ':details' => $reason,
          ':ip' => getClientIp()
        ]);
      });

      setFlash('danger', 'Seller privileges suspended and active listings hidden.');
      redirect('admin.php?tab=vetting');
    }

    // Action D: Moderation - Dismiss or Action Report
    elseif ($action === 'resolve_report') {
      $reportId = max(0, (int)($_POST['report_id'] ?? 0));
      $resolution = (string)($_POST['resolution'] ?? 'dismissed');
      $serviceId = max(0, (int)($_POST['service_id'] ?? 0));
      $adminNotes = trim((string)($_POST['admin_notes'] ?? ''));

      dbTransaction(function (PDO $db) use ($reportId, $resolution, $serviceId, $adminNotes, $adminId) {
        $upd = $db->prepare('
          UPDATE reports 
          SET status = :status, admin_notes = :notes, resolved_by = :admin_id, updated_at = NOW() 
          WHERE id = :id
        ');
        $upd->execute([
          ':status' => $resolution,
          ':notes' => $adminNotes,
          ':admin_id' => $adminId,
          ':id' => $reportId
        ]);

        if ($resolution === 'action_taken' && $serviceId > 0) {
          $hide = $db->prepare('UPDATE services SET status = \'hidden\', updated_at = NOW() WHERE id = :service_id');
          $hide->execute([':service_id' => $serviceId]);
        }

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (\'reports\', :id, :actor_id, :action, :details, :ip)
        ');
        $audit->execute([
          ':id' => $reportId,
          ':actor_id' => $adminId,
          ':action' => 'report_' . $resolution,
          ':details' => $adminNotes,
          ':ip' => getClientIp()
        ]);
      });

      setFlash('success', 'Community report marked as ' . e($resolution) . '.');
      redirect('admin.php?tab=reports');
    }

    // Action E: Unlock Locked Account
    elseif ($action === 'unlock_user') {
      $targetUserId = max(0, (int)($_POST['target_user_id'] ?? 0));

      dbTransaction(function (PDO $db) use ($targetUserId, $adminId) {
        $upd = $db->prepare('
          UPDATE users 
          SET failed_attempts = 0, locked_until = NULL, updated_at = NOW() 
          WHERE id = :id
        ');
        $upd->execute([':id' => $targetUserId]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (\'users\', :id, :actor_id, \'account_unlocked\', \'Administrator lifted login lockout.\', :ip)
        ');
        $audit->execute([
          ':id' => $targetUserId,
          ':actor_id' => $adminId,
          ':ip' => getClientIp()
        ]);
      });

      setFlash('success', 'Student account has been unlocked. Failed login counters reset.');
      redirect('admin.php?tab=unlock');
    }

    // Action F: Reset Password (In-Person Verification)
    elseif ($action === 'reset_password') {
      $targetUserId = max(0, (int)($_POST['target_user_id'] ?? 0));
      $newPassword = trim((string)($_POST['temp_password'] ?? ''));

      if ($newPassword === '' || strlen($newPassword) < 8) {
        setFlash('danger', 'Password must be at least 8 characters long.');
        redirect('admin.php?tab=unlock');
      }

      dbTransaction(function (PDO $db) use ($targetUserId, $newPassword, $adminId) {
        $upd = $db->prepare('
          UPDATE users 
          SET password_hash = :hash, failed_attempts = 0, locked_until = NULL, updated_at = NOW() 
          WHERE id = :id
        ');
        $upd->execute([
          ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
          ':id' => $targetUserId
        ]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (\'users\', :id, :actor_id, \'admin_password_reset\', \'Hiroba administrator issued new temporary credentials.\', :ip)
        ');
        $audit->execute([
          ':id' => $targetUserId,
          ':actor_id' => $adminId,
          ':ip' => getClientIp()
        ]);
      });

      setFlash('success', 'Student password has been reset successfully.');
      redirect('admin.php?tab=unlock');
    }

    // Action G: Create New Service Category
    elseif ($action === 'create_category') {
      $catName = trim((string)($_POST['category_name'] ?? ''));
      $catDesc = trim((string)($_POST['category_description'] ?? ''));

      if (mb_strlen($catName) < 2 || mb_strlen($catName) > 50) {
        setFlash('danger', 'Category name must be between 2 and 50 characters.');
        redirect('admin.php?tab=categories');
      }
      if (mb_strlen($catDesc) > 255) {
        setFlash('danger', 'Category description cannot exceed 255 characters.');
        redirect('admin.php?tab=categories');
      }

      $catResult = null;
      dbTransaction(function (PDO $db) use ($catName, $catDesc, $adminId, &$catResult) {
        $catResult = findOrCreateCategory($db, $catName, $catDesc, $adminId);
      });

      if ($catResult && $catResult['created']) {
        setFlash('success', 'Category "' . $catResult['name'] . '" added for new services.');
      } elseif ($catResult && !empty($catResult['reactivated'])) {
        setFlash('success', 'Category "' . $catResult['name'] . '" was previously hidden and is now active again.');
      } else {
        setFlash('warn', 'Category "' . ($catResult['name'] ?? $catName) . '" already exists.');
      }
      redirect('admin.php?tab=categories');
    }

    // Action H: Edit Existing Service Category
    elseif ($action === 'edit_category') {
      $catId = max(0, (int)($_POST['category_id'] ?? 0));
      $catName = trim((string)($_POST['category_name'] ?? ''));
      $catDesc = trim((string)($_POST['category_description'] ?? ''));

      if ($catId === 0) {
        setFlash('danger', 'Invalid category selected.');
        redirect('admin.php?tab=categories');
      }
      if (mb_strlen($catName) < 2 || mb_strlen($catName) > 50) {
        setFlash('danger', 'Category name must be between 2 and 50 characters.');
        redirect('admin.php?tab=categories');
      }
      if (mb_strlen($catDesc) > 255) {
        setFlash('danger', 'Category description cannot exceed 255 characters.');
        redirect('admin.php?tab=categories');
      }

      $dupStmt = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1');
      $dupStmt->execute([':name' => $catName, ':id' => $catId]);
      if ($dupStmt->fetch()) {
        setFlash('danger', 'Another category already uses the name "' . $catName . '".');
        redirect('admin.php?tab=categories');
      }

      dbTransaction(function (PDO $db) use ($catId, $catName, $catDesc, $adminId) {
        $newSlug = generateUniqueCategorySlug($db, $catName, $catId);
        $upd = $db->prepare('
          UPDATE categories
          SET name = :name, slug = :slug, description = :description
          WHERE id = :id
        ');
        $upd->execute([
          ':name' => $catName,
          ':slug' => $newSlug,
          ':description' => $catDesc !== '' ? $catDesc : null,
          ':id' => $catId,
        ]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (:entity_type, :entity_id, :actor_id, :action, :details, :ip)
        ');
        $audit->execute([
          ':entity_type' => 'categories',
          ':entity_id' => $catId,
          ':actor_id' => $adminId,
          ':action' => 'category_updated',
          ':details' => json_encode(['name' => $catName, 'slug' => $newSlug]),
          ':ip' => getClientIp(),
        ]);
      });

      setFlash('success', 'Category updated successfully.');
      redirect('admin.php?tab=categories');
    }

    // Action I: Hide or Unhide Service Category
    elseif ($action === 'toggle_category_status') {
      $catId = max(0, (int)($_POST['category_id'] ?? 0));
      $curStmt = $pdo->prepare('SELECT id, name, status FROM categories WHERE id = :id LIMIT 1');
      $curStmt->execute([':id' => $catId]);
      $catRow = $curStmt->fetch();

      if (!$catRow) {
        setFlash('danger', 'Category not found.');
        redirect('admin.php?tab=categories');
      }

      $nextStatus = ($catRow['status'] === 'hidden') ? 'active' : 'hidden';
      $auditAction = ($nextStatus === 'hidden') ? 'category_hidden' : 'category_unhidden';

      dbTransaction(function (PDO $db) use ($catId, $catRow, $nextStatus, $auditAction, $adminId) {
        $upd = $db->prepare('UPDATE categories SET status = :status WHERE id = :id');
        $upd->execute([':status' => $nextStatus, ':id' => $catId]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (:entity_type, :entity_id, :actor_id, :action, :details, :ip)
        ');
        $audit->execute([
          ':entity_type' => 'categories',
          ':entity_id' => $catId,
          ':actor_id' => $adminId,
          ':action' => $auditAction,
          ':details' => json_encode(['name' => $catRow['name'], 'status' => $nextStatus]),
          ':ip' => getClientIp(),
        ]);
      });

      $verb = ($nextStatus === 'hidden') ? 'hidden from the Plaza and new service forms.' : 'restored and visible again.';
      setFlash('success', 'Category "' . $catRow['name'] . '" ' . $verb);
      redirect('admin.php?tab=categories');
    }

    // Action J: Delete Service Category (blocked if services still reference it)
    elseif ($action === 'delete_category') {
      $catId = max(0, (int)($_POST['category_id'] ?? 0));
      $curStmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
      $curStmt->execute([':id' => $catId]);
      $catRow = $curStmt->fetch();

      if (!$catRow) {
        setFlash('danger', 'Category not found.');
        redirect('admin.php?tab=categories');
      }

      $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM services WHERE category_id = :id');
      $cntStmt->execute([':id' => $catId]);
      $linkedCount = (int)$cntStmt->fetchColumn();

      if ($linkedCount > 0) {
        setFlash('danger', 'Cannot delete "' . $catRow['name'] . '" because ' . $linkedCount . ' service(s) use it. Hide the category instead or reassign those services first.');
        redirect('admin.php?tab=categories');
      }

      dbTransaction(function (PDO $db) use ($catId, $catRow, $adminId) {
        $del = $db->prepare('DELETE FROM categories WHERE id = :id');
        $del->execute([':id' => $catId]);

        $audit = $db->prepare('
          INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
          VALUES (:entity_type, :entity_id, :actor_id, :action, :details, :ip)
        ');
        $audit->execute([
          ':entity_type' => 'categories',
          ':entity_id' => $catId,
          ':actor_id' => $adminId,
          ':action' => 'category_deleted',
          ':details' => json_encode(['name' => $catRow['name']]),
          ':ip' => getClientIp(),
        ]);
      });

      setFlash('success', 'Category "' . $catRow['name'] . '" deleted permanently.');
      redirect('admin.php?tab=categories');
    }
  } catch (PDOException $e) {
    setFlash('danger', 'Admin operation error: ' . $e->getMessage());
    redirect('admin.php?tab=' . $activeTab);
  }
}

// 2. Fetch Data for Active Tab
// Vetting Queue
$pendingSellers = [];
if ($activeTab === 'vetting') {
  $vettingStmt = $pdo->query('SELECT * FROM vw_admin_pending_sellers ORDER BY applied_at ASC');
  $pendingSellers = $vettingStmt->fetchAll();
}

// Reports Queue
$pendingReports = [];
if ($activeTab === 'reports') {
  $reportsStmt = $pdo->query('
    SELECT 
      r.id AS report_id,
      r.reason,
      r.details,
      r.status,
      r.created_at AS reported_at,
      reporter.name AS reporter_name,
      s.id AS service_id,
      s.title AS service_title,
      s.status AS service_status,
      seller.id AS seller_id,
      seller.name AS seller_name
    FROM reports r
    JOIN users reporter ON r.reporter_id = reporter.id
    JOIN services s ON r.service_id = s.id
    JOIN users seller ON s.seller_id = seller.id
    WHERE r.status = \'pending\'
    ORDER BY r.created_at ASC
  ');
  $pendingReports = $reportsStmt->fetchAll();
}

// Categories Management
$allCategories = [];
if ($activeTab === 'categories') {
  $catListStmt = $pdo->query('
    SELECT c.id, c.name, c.slug, c.description, c.status, c.created_at, COUNT(s.id) AS service_count
    FROM categories c
    LEFT JOIN services s ON c.id = s.category_id
    GROUP BY c.id, c.name, c.slug, c.description, c.status, c.created_at
    ORDER BY c.name ASC
  ');
  $allCategories = $catListStmt->fetchAll();
}

// Locked Accounts Queue
$lockedUsers = [];
if ($activeTab === 'unlock') {
  $lockedStmt = $pdo->query('
    SELECT id, name, email, failed_attempts, locked_until, updated_at
    FROM users
    WHERE locked_until > NOW() OR failed_attempts > 0
    ORDER BY locked_until DESC, failed_attempts DESC
  ');
  $lockedUsers = $lockedStmt->fetchAll();
}

// Analytics and Metrics
$stats = [];
$orderStatusStats = [];
$categoryStats = [];
if ($activeTab === 'analytics') {
  // Query 8.10
  $statsStmt = $pdo->query('
    SELECT 
      (SELECT COUNT(*) FROM users WHERE is_admin = 0) AS total_students_count,
      (SELECT COUNT(*) FROM seller_profiles WHERE status = \'approved\') AS verified_sellers_count,
      (SELECT COUNT(*) FROM seller_profiles WHERE status = \'pending\') AS pending_applications_count,
      (SELECT COUNT(*) FROM services WHERE status = \'active\') AS active_services_count,
      (SELECT COUNT(*) FROM orders WHERE status = \'completed\') AS completed_contracts_count,
      (SELECT COALESCE(SUM(price_at_order), 0.00) FROM orders WHERE status = \'completed\') AS gross_marketplace_volume_php,
      (SELECT COUNT(*) FROM reports WHERE status = \'pending\') AS pending_reports_count
  ');
  $stats = $statsStmt->fetch();

  // Order status distribution
  $ordDistStmt = $pdo->query('
    SELECT status, COUNT(*) AS count
    FROM orders
    GROUP BY status
  ');
  $orderStatusStats = $ordDistStmt->fetchAll();

  // Categories distribution
  $catDistStmt = $pdo->query('
    SELECT c.name, COUNT(s.id) AS service_count
    FROM categories c
    LEFT JOIN services s ON c.id = s.category_id AND s.status = \'active\'
    GROUP BY c.id, c.name
    ORDER BY service_count DESC
    LIMIT 6
  ');
  $categoryStats = $catDistStmt->fetchAll();
}

// Audit Trail
$auditLogs = [];
if ($activeTab === 'audit') {
  $auditStmt = $pdo->query('
    SELECT 
      a.id,
      a.action,
      a.entity_type,
      a.entity_id,
      a.details,
      a.ip_address,
      a.created_at,
      u.name AS actor_name,
      u.email AS actor_email
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_id = u.id
    ORDER BY a.created_at DESC
    LIMIT 50
  ');
  $auditLogs = $auditStmt->fetchAll();
}

renderHeader('Admin Desk — Hiroba', [
  'current_page' => 'admin',
  'chartjs' => ($activeTab === 'analytics')
]);
?>

<div class="container py-4">
  <!-- Desk Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-3 border-bottom">
    <div>
      <span class="eyebrow">Platform Governance & Moderation</span>
      <h1 class="display-h1 my-1">Hiroba Administrator Desk</h1>
      <p class="body-text small text-muted mb-0">Oversee student seller accreditations, community policy reports, and platform integrity.</p>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <nav class="nav-pill-row mb-4" aria-label="Admin Navigation Tabs">
    <a href="<?= e(url('admin.php?tab=vetting')) ?>" class="nav-tab-pill <?= $activeTab === 'vetting' ? 'active' : '' ?>">
      Seller Vetting
    </a>
    <a href="<?= e(url('admin.php?tab=reports')) ?>" class="nav-tab-pill <?= $activeTab === 'reports' ? 'active' : '' ?>">
      Community Reports
    </a>
    <a href="<?= e(url('admin.php?tab=categories')) ?>" class="nav-tab-pill <?= $activeTab === 'categories' ? 'active' : '' ?>">
      Categories
    </a>
    <a href="<?= e(url('admin.php?tab=unlock')) ?>" class="nav-tab-pill <?= $activeTab === 'unlock' ? 'active' : '' ?>">
      Account Recovery & Unlock
    </a>
    <a href="<?= e(url('admin.php?tab=analytics')) ?>" class="nav-tab-pill <?= $activeTab === 'analytics' ? 'active' : '' ?>">
      Analytics & Metrics
    </a>
    <a href="<?= e(url('admin.php?tab=audit')) ?>" class="nav-tab-pill <?= $activeTab === 'audit' ? 'active' : '' ?>">
      Security Audit Trail
    </a>
  </nav>

  <!-- TAB 1: Seller Accreditation Queue -->
  <?php if ($activeTab === 'vetting'): ?>
    <section>
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h3 mb-0">Pending Seller Applications</h2>
        <span class="badge bg-secondary-subtle text-muted font-monospace"><?= count($pendingSellers) ?> Pending</span>
      </div>

      <?php if (empty($pendingSellers)): ?>
        <div class="empty-state">
          <div class="empty-state__icon-wrap">
            <?= renderIcon('check', 'icon--xl') ?>
          </div>
          <h3 class="empty-state__title">Vetting Queue Clear</h3>
          <p class="empty-state__text">All student seller applications have been reviewed and accredited.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle" style="border: 1px solid var(--line);">
            <thead style="background-color: var(--surface); font-size: 0.82rem; color: var(--ink-2);">
              <tr>
                <th>Applicant</th>
                <th>Student ID</th>
                <th>Skills & Bio</th>
                <th>ID Document</th>
                <th>Applied</th>
                <th class="text-end">Vetting Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingSellers as $seller): ?>
                <tr>
                  <td>
                    <strong><?= e($seller['name']) ?></strong>
                    <div class="small text-muted font-monospace"><?= e($seller['email']) ?></div>
                  </td>
                  <td class="font-monospace fw-bold"><?= e($seller['student_id']) ?></td>
                  <td style="max-width: 260px;">
                    <div class="small text-truncate"><?= e($seller['bio']) ?></div>
                  </td>
                  <td>
                    <?php if (!empty($seller['id_card_path']) && file_exists(__DIR__ . '/' . $seller['id_card_path'])): ?>
                      <a href="<?= e(url($seller['id_card_path'])) ?>" target="_blank" rel="noopener noreferrer" class="btn-tactile btn-outline btn-sm">
                        <?= renderIcon('link', 'icon--sm') ?>
                        <span>View ID</span>
                      </a>
                    <?php else: ?>
                      <span class="small text-muted font-monospace"><?= e($seller['id_card_path']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="small text-muted font-monospace"><?= e(formatDate($seller['applied_at'])) ?></td>
                  <td class="text-end">
                    <form action="<?= e(url('admin.php?tab=vetting')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="approve_seller">
                      <input type="hidden" name="profile_id" value="<?= (int)$seller['profile_id'] ?>">
                      <button type="submit" class="btn-tactile btn-accent btn-sm me-1">Approve</button>
                    </form>

                    <form action="<?= e(url('admin.php?tab=vetting')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="reject_seller">
                      <input type="hidden" name="profile_id" value="<?= (int)$seller['profile_id'] ?>">
                      <button type="submit" class="btn-tactile btn-outline btn-sm text-danger" onclick="return confirm('Reject this seller application?');">Reject</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  <!-- TAB 2: Community Moderation Reports -->
  <?php elseif ($activeTab === 'reports'): ?>
    <section>
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h3 mb-0">Community Moderation Reports</h2>
        <span class="badge bg-secondary-subtle text-muted font-monospace"><?= count($pendingReports) ?> Pending</span>
      </div>

      <?php if (empty($pendingReports)): ?>
        <div class="empty-state">
          <div class="empty-state__icon-wrap">
            <?= renderIcon('shield', 'icon--xl') ?>
          </div>
          <h3 class="empty-state__title">No Pending Reports</h3>
          <p class="empty-state__text">The campus marketplace currently has zero community policy reports.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle" style="border: 1px solid var(--line);">
            <thead style="background-color: var(--surface); font-size: 0.82rem; color: var(--ink-2);">
              <tr>
                <th>Report #</th>
                <th>Target Service</th>
                <th>Seller</th>
                <th>Reporter</th>
                <th>Violation Reason</th>
                <th>Details</th>
                <th class="text-end">Adjudication</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingReports as $rep): ?>
                <tr>
                  <td class="font-monospace">#<?= (int)$rep['report_id'] ?></td>
                  <td>
                    <a href="<?= e(url('service.php?id=' . (int)$rep['service_id'])) ?>" target="_blank" class="text-decoration-none fw-normal">
                      <?= e($rep['service_title']) ?>
                    </a>
                  </td>
                  <td><?= e($rep['seller_name']) ?></td>
                  <td class="small text-muted"><?= e($rep['reporter_name']) ?></td>
                  <td>
                    <span class="badge bg-danger-subtle text-danger font-monospace">
                      <?= e(str_replace('_', ' ', (string)$rep['reason'])) ?>
                    </span>
                  </td>
                  <td class="small" style="max-width: 220px;"><?= e($rep['details']) ?></td>
                  <td class="text-end">
                    <form action="<?= e(url('admin.php?tab=reports')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="resolve_report">
                      <input type="hidden" name="report_id" value="<?= (int)$rep['report_id'] ?>">
                      <input type="hidden" name="service_id" value="<?= (int)$rep['service_id'] ?>">
                      <input type="hidden" name="resolution" value="action_taken">
                      <input type="hidden" name="admin_notes" value="Service violates campus integrity. Listing hidden.">
                      <button type="submit" class="btn-tactile btn-accent btn-sm me-1" onclick="return confirm('Hide this service listing?');">Hide Listing</button>
                    </form>

                    <form action="<?= e(url('admin.php?tab=reports')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="resolve_report">
                      <input type="hidden" name="report_id" value="<?= (int)$rep['report_id'] ?>">
                      <input type="hidden" name="resolution" value="dismissed">
                      <input type="hidden" name="admin_notes" value="Report dismissed after administrative evaluation.">
                      <button type="submit" class="btn-tactile btn-outline btn-sm text-muted">Dismiss</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  <!-- TAB 2B: Service Categories Management -->
  <?php elseif ($activeTab === 'categories'): ?>
    <section>
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h3 mb-0">Service Categories</h2>
        <span class="badge bg-secondary-subtle text-muted font-monospace"><?= count($allCategories) ?> Categories</span>
      </div>

      <div class="card p-4 shadow-sm mb-4" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
        <h3 class="h4 mb-2">Add New Category</h3>
        <p class="small text-muted mb-3">Create a new category so verified student sellers can list services under it.</p>
        <form action="<?= e(url('admin.php?tab=categories')) ?>" method="POST" class="row g-3 align-items-end">
          <?= csrfField() ?>
          <input type="hidden" name="admin_action" value="create_category">
          <div class="col-md-4">
            <label for="admin-cat-name" class="form-label">Category Name</label>
            <input type="text" name="category_name" id="admin-cat-name" class="form-control" placeholder="e.g., Data Analysis & Spreadsheets" minlength="2" maxlength="50" required>
          </div>
          <div class="col-md-6">
            <label for="admin-cat-desc" class="form-label">Description (Optional)</label>
            <input type="text" name="category_description" id="admin-cat-desc" class="form-control" placeholder="Brief summary of services in this category" maxlength="255">
          </div>
          <div class="col-md-2 d-grid">
            <button type="submit" class="btn-tactile btn-accent justify-content-center">Add Category</button>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table align-middle" style="border: 1px solid var(--line);">
          <thead style="background-color: var(--surface); font-size: 0.82rem; color: var(--ink-2);">
            <tr>
              <th>Category</th>
              <th>Status</th>
              <th>Description</th>
              <th class="text-end">Services</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allCategories as $cat): ?>
              <?php
                $isHidden = (($cat['status'] ?? 'active') === 'hidden');
                $svcCount = (int)$cat['service_count'];
              ?>
              <tr>
                <td>
                  <strong><?= e($cat['name']) ?></strong>
                  <div class="font-monospace small text-muted"><?= e($cat['slug']) ?></div>
                </td>
                <td>
                  <span class="badge <?= $isHidden ? 'bg-secondary-subtle text-muted' : 'bg-success-subtle text-success' ?> font-monospace">
                    <?= $isHidden ? 'Hidden' : 'Active' ?>
                  </span>
                </td>
                <td class="small text-muted" style="max-width: 280px;"><?= e((string)($cat['description'] ?? '—')) ?></td>
                <td class="text-end font-monospace fw-bold"><?= $svcCount ?></td>
                <td class="text-end" style="min-width: 240px;">
                  <div class="d-inline-flex flex-wrap justify-content-end gap-1 align-items-center">
                    <form action="<?= e(url('admin.php?tab=categories')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="toggle_category_status">
                      <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                      <button type="submit" class="btn-tactile btn-outline btn-sm">
                        <?= $isHidden ? 'Show' : 'Hide' ?>
                      </button>
                    </form>

                    <form action="<?= e(url('admin.php?tab=categories')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="delete_category">
                      <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                      <button
                        type="submit"
                        class="btn-tactile btn-outline btn-sm text-danger"
                        <?= $svcCount > 0 ? 'disabled title="Reassign or remove services in this category before deleting"' : '' ?>
                        onclick="return confirm('Permanently delete this category?');"
                      >Delete</button>
                    </form>
                  </div>

                  <details class="mt-2 text-start">
                    <summary class="small text-muted" style="cursor: pointer;">Edit details</summary>
                    <form action="<?= e(url('admin.php?tab=categories')) ?>" method="POST" class="mt-2 p-2 rounded border" style="background-color: var(--ground); border-color: var(--line) !important;">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="edit_category">
                      <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                      <div class="mb-2">
                        <input type="text" name="category_name" class="form-control form-control-sm" value="<?= e($cat['name']) ?>" minlength="2" maxlength="50" required>
                      </div>
                      <div class="mb-2">
                        <input type="text" name="category_description" class="form-control form-control-sm" value="<?= e((string)($cat['description'] ?? '')) ?>" placeholder="Description (optional)" maxlength="255">
                      </div>
                      <button type="submit" class="btn-tactile btn-accent btn-sm">Save</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  <!-- TAB 3: Account Recovery & Lockout Reset -->
  <?php elseif ($activeTab === 'unlock'): ?>
    <section>
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h3 mb-0">Locked Student Accounts & Recovery</h2>
        <span class="badge bg-secondary-subtle text-muted font-monospace"><?= count($lockedUsers) ?> Records</span>
      </div>

      <?php if (empty($lockedUsers)): ?>
        <div class="empty-state">
          <div class="empty-state__icon-wrap">
            <?= renderIcon('check', 'icon--xl') ?>
          </div>
          <h3 class="empty-state__title">No Accounts Locked</h3>
          <p class="empty-state__text">No student accounts are currently flagged with brute-force lockout timers.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive mb-5">
          <table class="table align-middle" style="border: 1px solid var(--line);">
            <thead style="background-color: var(--surface); font-size: 0.82rem; color: var(--ink-2);">
              <tr>
                <th>Student Name</th>
                <th>University Email</th>
                <th>Failed Attempts</th>
                <th>Locked Until</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lockedUsers as $lu): ?>
                <tr>
                  <td><strong><?= e($lu['name']) ?></strong></td>
                  <td class="font-monospace"><?= e($lu['email']) ?></td>
                  <td>
                    <span class="badge <?= (int)$lu['failed_attempts'] >= 5 ? 'bg-danger text-white' : 'bg-warning text-dark' ?>">
                      <?= (int)$lu['failed_attempts'] ?> attempt(s)
                    </span>
                  </td>
                  <td class="font-monospace small">
                    <?= !empty($lu['locked_until']) ? e(formatDate($lu['locked_until'], 'M d, Y h:i A')) : 'Not locked' ?>
                  </td>
                  <td class="text-end">
                    <form action="<?= e(url('admin.php?tab=unlock')) ?>" method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="admin_action" value="unlock_user">
                      <input type="hidden" name="target_user_id" value="<?= (int)$lu['id'] ?>">
                      <button type="submit" class="btn-tactile btn-accent btn-sm">Unlock Account</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- Password Reset Form for Direct Verification -->
      <div class="card p-4 shadow-sm" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); max-width: 600px;">
        <h3 class="h4 mb-2">Direct Administrator Password Reset</h3>
        <p class="small text-muted mb-3">Reset a student's password after verifying their enrollment credentials.</p>
        <form action="<?= e(url('admin.php?tab=unlock')) ?>" method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="admin_action" value="reset_password">

          <div class="mb-3">
            <label for="target-user-id" class="form-label">Student User ID</label>
            <input type="number" name="target_user_id" id="target-user-id" class="form-control" placeholder="e.g., 3" required>
          </div>

          <div class="mb-3">
            <label for="temp-pwd" class="form-label">New Temporary Password (Min. 8 chars)</label>
            <input type="password" name="temp_password" id="temp-pwd" class="form-control" placeholder="Enter new student password" minlength="8" required>
          </div>

          <button type="submit" class="btn-tactile btn-accent btn-sm">Issue Password Reset</button>
        </form>
      </div>
    </section>

  <!-- TAB 4: Analytics and Metrics -->
  <?php elseif ($activeTab === 'analytics'): ?>
    <section>
      <!-- Overview Statistics Cards -->
      <div class="row g-3 mb-5">
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
            <small class="text-muted d-block mb-1">Total Students</small>
            <div class="price-lg"><?= (int)($stats['total_students_count'] ?? 0) ?></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
            <small class="text-muted d-block mb-1">Verified Sellers</small>
            <div class="price-lg" style="color: var(--ok);"><?= (int)($stats['verified_sellers_count'] ?? 0) ?></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
            <small class="text-muted d-block mb-1">Active Services</small>
            <div class="price-lg" style="color: var(--accent);"><?= (int)($stats['active_services_count'] ?? 0) ?></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
            <small class="text-muted d-block mb-1">Gross Marketplace Volume</small>
            <div class="price-lg" style="color: var(--progress);"><?= e(formatPrice((float)($stats['gross_marketplace_volume_php'] ?? 0))) ?></div>
          </div>
        </div>
      </div>

      <!-- Interactive Chart.js Analytics -->
      <div class="row g-4 mb-5">
        <div class="col-lg-6">
          <div class="card p-4 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
            <h3 class="h4 mb-3" style="font-size: 1.15rem;">Order Status Distribution</h3>
            <div style="height: 280px; position: relative;">
              <canvas id="orderStatusChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card p-4 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
            <h3 class="h4 mb-3" style="font-size: 1.15rem;">Top Category Distribution</h3>
            <div style="height: 280px; position: relative;">
              <canvas id="categoryChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Semantic Table Fallback (Progressive Enhancement) -->
      <div class="card p-4 shadow-sm border mb-4" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
        <h4 class="h5 mb-3">Order Status Data Summary</h4>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Order Status</th>
                <th class="text-end">Volume</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orderStatusStats as $oss): ?>
                <tr>
                  <td><span class="pill pill--<?= e($oss['status']) ?>"><?= e(ucwords(str_replace('_', ' ', (string)$oss['status']))) ?></span></td>
                  <td class="text-end font-monospace fw-bold"><?= (int)$oss['count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <script>
        document.addEventListener('DOMContentLoaded', function () {
          // Chart 1: Order Status Chart
          var orderLabels = <?= json_encode(array_column($orderStatusStats, 'status')) ?>;
          var orderCounts = <?= json_encode(array_map('intval', array_column($orderStatusStats, 'count'))) ?>;

          var ctxOrder = document.getElementById('orderStatusChart');
          if (ctxOrder && typeof Chart !== 'undefined') {
            new Chart(ctxOrder, {
              type: 'doughnut',
              data: {
                labels: orderLabels.map(function(s) { return s.replace('_', ' ').toUpperCase(); }),
                datasets: [{
                  data: orderCounts,
                  backgroundColor: ['#6b7280', '#2b4a7a', '#8a5a12', '#2f6b4f', '#9c2c2c'],
                  borderWidth: 1
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                  legend: { position: 'bottom' }
                }
              }
            });
          }

          // Chart 2: Category Distribution Chart
          var catLabels = <?= json_encode(array_column($categoryStats, 'name')) ?>;
          var catCounts = <?= json_encode(array_map('intval', array_column($categoryStats, 'service_count'))) ?>;

          var ctxCat = document.getElementById('categoryChart');
          if (ctxCat && typeof Chart !== 'undefined') {
            new Chart(ctxCat, {
              type: 'bar',
              data: {
                labels: catLabels,
                datasets: [{
                  label: 'Active Services',
                  data: catCounts,
                  backgroundColor: '#a8412c',
                  borderRadius: 3
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                  y: { beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                  legend: { display: false }
                }
              }
            });
          }
        });
      </script>
    </section>

  <!-- TAB 5: Security Audit Ledger -->
  <?php elseif ($activeTab === 'audit'): ?>
    <section>
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h3 mb-0">System Security Audit Ledger</h2>
        <span class="badge bg-secondary-subtle text-muted font-monospace">Latest 50 Entries</span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle" style="border: 1px solid var(--line);">
          <thead style="background-color: var(--surface); font-size: 0.80rem; color: var(--ink-2);">
            <tr>
              <th>ID</th>
              <th>Action</th>
              <th>Entity</th>
              <th>Actor</th>
              <th>IP Address</th>
              <th>Timestamp</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditLogs as $log): ?>
              <tr>
                <td class="font-monospace">#<?= (int)$log['id'] ?></td>
                <td><span class="badge bg-secondary-subtle text-dark font-monospace"><?= e($log['action']) ?></span></td>
                <td class="small font-monospace"><?= e($log['entity_type']) ?> #<?= (int)$log['entity_id'] ?></td>
                <td>
                  <div class="small fw-normal"><?= e($log['actor_name'] ?? 'System') ?></div>
                  <div class="font-monospace text-muted" style="font-size: 0.72rem;"><?= e($log['actor_email'] ?? '') ?></div>
                </td>
                <td class="font-monospace small"><?= e($log['ip_address'] ?? '127.0.0.1') ?></td>
                <td class="font-monospace small"><?= e(formatDate($log['created_at'], 'M d, Y h:i A')) ?></td>
                <td class="small text-truncate" style="max-width: 250px;"><?= e((string)$log['details']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php
renderFooter();
