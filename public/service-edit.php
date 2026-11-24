<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/upload.php';
require_once __DIR__ . '/../src/layout.php';

// Authentication & Seller Guard
requireSeller();

$serviceId = max(0, (int)($_GET['id'] ?? 0));
if ($serviceId === 0) {
  setFlash('danger', 'Invalid service listing.');
  redirect('dashboard.php?tab=listings');
}

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];

// Fetch service
$stmt = $pdo->prepare('SELECT * FROM services WHERE id = :id');
$stmt->execute([':id' => $serviceId]);
$service = $stmt->fetch();

if (!$service || ((int)$service['seller_id'] !== $userId && !isAdmin())) {
  setFlash('danger', 'You do not have permission to modify this service.');
  redirect('dashboard.php?tab=listings');
}

// Fetch categories
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

$errors = [];
$title = (string)$service['title'];
$categoryId = (int)$service['category_id'];
$price = (float)$service['price'];
$deliveryDays = (int)$service['delivery_days'];
$description = (string)$service['description'];
$requirements = (string)($service['requirements'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $errors['general'] = 'Security validation failed (CSRF). Please refresh and try again.';
  } else {
    $title = trim((string)($_POST['title'] ?? ''));
    $categoryId = max(0, (int)($_POST['category_id'] ?? 0));
    $price = (float)($_POST['price'] ?? 0);
    $deliveryDays = (int)($_POST['delivery_days'] ?? 3);
    $description = trim((string)($_POST['description'] ?? ''));
    $requirements = trim((string)($_POST['requirements'] ?? ''));

    if ($title === '') {
      $errors['title'] = 'Service title is required.';
    } elseif (mb_strlen($title) > 80) {
      $errors['title'] = 'Title cannot exceed 80 characters.';
    }

    if ($categoryId === 0) {
      $errors['category_id'] = 'Please select a valid academic category.';
    }

    if ($price < 1.00 || $price > 50000.00) {
      $errors['price'] = 'Price must be between ₱1.00 and ₱50,000.00.';
    }

    if ($deliveryDays < 1 || $deliveryDays > 30) {
      $errors['delivery_days'] = 'Turnaround time must be between 1 and 30 days.';
    }

    if ($description === '') {
      $errors['description'] = 'Service description is required.';
    }

    $uploadedImagePath = null;
    if (isset($_FILES['cover_image']) && !empty($_FILES['cover_image']['name'])) {
      $uploadResult = processImageUpload($_FILES['cover_image'], 'covers');
      if ($uploadResult['success']) {
        $uploadedImagePath = $uploadResult['path'];
      } else {
        $errors['cover_image'] = $uploadResult['message'];
      }
    }

    if (empty($errors)) {
      try {
        dbTransaction(function (PDO $db) use ($serviceId, $userId, $categoryId, $title, $description, $price, $deliveryDays, $requirements, $uploadedImagePath) {
          $upd = $db->prepare('
            UPDATE services 
            SET category_id = :category_id,
                title = :title,
                description = :description,
                price = :price,
                delivery_days = :delivery_days,
                requirements = :requirements,
                updated_at = NOW()
            WHERE id = :id
          ');
          $upd->execute([
            ':id' => $serviceId,
            ':category_id' => $categoryId,
            ':title' => $title,
            ':description' => $description,
            ':price' => $price,
            ':delivery_days' => $deliveryDays,
            ':requirements' => $requirements !== '' ? $requirements : null
          ]);

          if ($uploadedImagePath !== null) {
            // Remove previous primary status
            $db->prepare('UPDATE service_images SET is_primary = 0 WHERE service_id = :service_id')->execute([':service_id' => $serviceId]);

            // Insert new primary image
            $imgStmt = $db->prepare('
              INSERT INTO service_images (service_id, image_path, is_primary)
              VALUES (:service_id, :image_path, 1)
            ');
            $imgStmt->execute([
              ':service_id' => $serviceId,
              ':image_path' => $uploadedImagePath
            ]);
          }

          // Audit log
          $audit = $db->prepare('
            INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
            VALUES (\'services\', :service_id, :actor_id, \'update\', :details, :ip)
          ');
          $audit->execute([
            ':service_id' => $serviceId,
            ':actor_id' => $userId,
            ':details' => json_encode(['title' => $title, 'price' => $price]),
            ':ip' => getClientIp()
          ]);
        });

        setFlash('success', 'Service listing updated successfully.');
        redirect('service.php?id=' . $serviceId);
      } catch (PDOException $e) {
        $errors['general'] = 'Database error updating listing: ' . $e->getMessage();
      }
    }
  }
}

renderHeader('Edit Service — Hiroba', ['current_page' => 'plaza']);
?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
      <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
          <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php?tab=listings')) ?>" class="text-decoration-none text-muted">My Services</a></li>
          <li class="breadcrumb-item active" aria-current="page">Edit Listing</li>
        </ol>
      </nav>

      <div class="card p-4 p-md-5 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
        <span class="eyebrow">Listing Editor</span>
        <h1 class="h2 mt-1 mb-3">Update Service Details</h1>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger);">
            <?= renderIcon('alert', 'icon flex-shrink-0') ?>
            <div><?= e($errors['general']) ?></div>
          </div>
        <?php endif; ?>

        <form action="<?= e(url('service-edit.php?id=' . $serviceId)) ?>" method="POST" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>

          <div class="mb-3">
            <label for="service-title" class="form-label">Service Title</label>
            <input type="text" name="title" id="service-title" class="form-control" value="<?= e($title) ?>" maxlength="80" required>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="service-category" class="form-label">Category</label>
              <select name="category_id" id="service-category" class="form-select" required>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label for="service-price" class="form-label">Price (₱)</label>
              <input type="number" name="price" id="service-price" class="form-control" step="0.01" min="1.00" max="50000.00" value="<?= e((string)$price) ?>" required>
            </div>

            <div class="col-md-3">
              <label for="service-delivery" class="form-label">Turnaround (Days)</label>
              <input type="number" name="delivery_days" id="service-delivery" class="form-control" min="1" max="30" value="<?= e((string)$deliveryDays) ?>" required>
            </div>
          </div>

          <div class="mb-3">
            <label for="service-desc" class="form-label">Description</label>
            <textarea name="description" id="service-desc" rows="5" class="form-control" required><?= e($description) ?></textarea>
          </div>

          <div class="mb-3">
            <label for="service-reqs" class="form-label">Requirements from Buyer</label>
            <textarea name="requirements" id="service-reqs" rows="3" class="form-control"><?= e($requirements) ?></textarea>
          </div>

          <div class="mb-4">
            <label for="cover-image" class="form-label">Replace Showcase Image (Optional, Max 2MB)</label>
            <input type="file" name="cover_image" id="cover-image" class="form-control" accept="image/jpeg,image/png,image/webp">
          </div>

          <div class="d-flex align-items-center justify-content-between pt-3 border-top">
            <a href="<?= e(url('dashboard.php?tab=listings')) ?>" class="btn-tactile btn-outline">Cancel</a>
            <button type="submit" class="btn-tactile btn-accent">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
renderFooter();
