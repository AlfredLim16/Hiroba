<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/upload.php';
require_once __DIR__ . '/../src/layout.php';

// Only accredited, approved student sellers can create listings
requireSeller();

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];

// Fetch categories for dropdown
$categoriesStmt = $pdo->query('SELECT id, name FROM categories WHERE status = \'active\' ORDER BY name ASC');
$categories = $categoriesStmt->fetchAll();

$errors = [];
$title = '';
$categoryId = 0;
$isNewCategory = false;
$newCategoryName = '';
$newCategoryDescription = '';
$price = '';
$deliveryDays = 3;
$description = '';
$requirements = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $errors['general'] = 'Security validation failed (CSRF). Please refresh and try again.';
  } else {
    $title = trim((string)($_POST['title'] ?? ''));
    $rawCategory = trim((string)($_POST['category_id'] ?? '0'));
    $isNewCategory = ($rawCategory === '__new__');
    $categoryId = $isNewCategory ? 0 : max(0, (int)$rawCategory);
    $newCategoryName = trim((string)($_POST['new_category_name'] ?? ''));
    $newCategoryDescription = trim((string)($_POST['new_category_description'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $deliveryDays = (int)($_POST['delivery_days'] ?? 3);
    $description = trim((string)($_POST['description'] ?? ''));
    $requirements = trim((string)($_POST['requirements'] ?? ''));

    // Validation
    if ($title === '') {
      $errors['title'] = 'Service title is required.';
    } elseif (mb_strlen($title) > 80) {
      $errors['title'] = 'Title cannot exceed 80 characters.';
    }

    if ($isNewCategory) {
      if ($newCategoryName === '') {
        $errors['new_category_name'] = 'Please enter a name for the new category.';
      } elseif (mb_strlen($newCategoryName) < 2 || mb_strlen($newCategoryName) > 50) {
        $errors['new_category_name'] = 'Category name must be between 2 and 50 characters.';
      }
      if (mb_strlen($newCategoryDescription) > 255) {
        $errors['new_category_description'] = 'Category description cannot exceed 255 characters.';
      }
    } elseif ($categoryId === 0) {
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
    } elseif (mb_strlen($description) < 20) {
      $errors['description'] = 'Description should be at least 20 characters to inform buyers.';
    }

    // Process Image Upload with GD EXIF Stripping
    $uploadedImagePath = null;
    if (isset($_FILES['cover_image']) && !empty($_FILES['cover_image']['name'])) {
      $uploadResult = processImageUpload($_FILES['cover_image'], 'covers');
      if ($uploadResult['success']) {
        $uploadedImagePath = $uploadResult['path'];
      } else {
        $errors['cover_image'] = $uploadResult['message'];
      }
    }

    // Save Service Listing
    if (empty($errors)) {
      try {
        $newServiceId = 0;
        dbTransaction(function (PDO $db) use ($userId, $categoryId, $isNewCategory, $newCategoryName, $newCategoryDescription, $title, $description, $price, $deliveryDays, $requirements, $uploadedImagePath, &$newServiceId) {
          $resolvedCategoryId = $categoryId;
          if ($isNewCategory) {
            $catResult = findOrCreateCategory($db, $newCategoryName, $newCategoryDescription, $userId);
            $resolvedCategoryId = $catResult['id'];
          }

          // Generate unique slug
          $baseSlug = slugify($title);
          $slug = $baseSlug;
          $counter = 1;
          while (true) {
            $chk = $db->prepare('SELECT id FROM services WHERE slug = :slug LIMIT 1');
            $chk->execute([':slug' => $slug]);
            if (!$chk->fetch()) {
              break;
            }
            $slug = $baseSlug . '-' . $counter;
            $counter++;
          }

          $stmt = $db->prepare('
            INSERT INTO services (seller_id, category_id, title, slug, description, price, delivery_days, requirements, status, created_at, updated_at)
            VALUES (:seller_id, :category_id, :title, :slug, :description, :price, :delivery_days, :requirements, \'active\', NOW(), NOW())
          ');
          $stmt->execute([
            ':seller_id' => $userId,
            ':category_id' => $resolvedCategoryId,
            ':title' => $title,
            ':slug' => $slug,
            ':description' => $description,
            ':price' => $price,
            ':delivery_days' => $deliveryDays,
            ':requirements' => $requirements !== '' ? $requirements : null
          ]);

          $newServiceId = (int)$db->lastInsertId();

          // Save primary portfolio image if uploaded
          if ($uploadedImagePath !== null) {
            $imgStmt = $db->prepare('
              INSERT INTO service_images (service_id, image_path, is_primary)
              VALUES (:service_id, :image_path, 1)
            ');
            $imgStmt->execute([
              ':service_id' => $newServiceId,
              ':image_path' => $uploadedImagePath
            ]);
          }

          // Audit Log
          $audit = $db->prepare('
            INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
            VALUES (\'services\', :service_id, :actor_id, \'create\', :details, :ip)
          ');
          $audit->execute([
            ':service_id' => $newServiceId,
            ':actor_id' => $userId,
            ':details' => json_encode(['title' => $title, 'price' => $price]),
            ':ip' => getClientIp()
          ]);
        });

        setFlash('success', 'Your service listing has been published to the Plaza!');
        redirect('service.php?id=' . $newServiceId);
      } catch (PDOException $e) {
        $errors['general'] = 'Database error creating service listing: ' . $e->getMessage();
      }
    }
  }
}

renderHeader('Offer a Service — Hiroba', ['current_page' => 'plaza']);
?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
      <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
          <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')) ?>" class="text-decoration-none text-muted">Dashboard</a></li>
          <li class="breadcrumb-item active" aria-current="page">Offer a Service</li>
        </ol>
      </nav>

      <div class="card p-4 p-md-5 shadow-sm border" style="background-color: var(--surface); border-color: var(--line) !important; border-radius: var(--radius);">
        <span class="eyebrow">Seller Marketplace Console</span>
        <h1 class="h2 mt-1 mb-2">Create New Service Listing</h1>
        <p class="body-text small text-muted mb-4">Publish your academic skills and deliverables to fellow campus students.</p>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger);">
            <?= renderIcon('alert', 'icon flex-shrink-0') ?>
            <div><?= e($errors['general']) ?></div>
          </div>
        <?php endif; ?>

        <form action="<?= e(url('service-new.php')) ?>" method="POST" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>

          <div class="mb-3">
            <label for="service-title" class="form-label">Service Title (Deliverable-focused)</label>
            <input 
              type="text" 
              name="title" 
              id="service-title" 
              class="form-control <?= !empty($errors['title']) ? 'is-invalid' : '' ?>" 
              placeholder="e.g., Academic Infographic & Slide Deck Layout" 
              value="<?= e($title) ?>" 
              maxlength="80" 
              required
            >
            <?php if (!empty($errors['title'])): ?>
              <div class="invalid-feedback"><?= e($errors['title']) ?></div>
            <?php endif; ?>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="service-category" class="form-label">Skill Category</label>
              <select name="category_id" id="service-category" class="form-select <?= !empty($errors['category_id']) ? 'is-invalid' : '' ?>" required>
                <option value="0">-- Select Category --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= !$isNewCategory && $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
                <option value="__new__" <?= $isNewCategory ? 'selected' : '' ?>>+ Add new category...</option>
              </select>
              <?php if (!empty($errors['category_id'])): ?>
                <div class="invalid-feedback"><?= e($errors['category_id']) ?></div>
              <?php endif; ?>
            </div>

            <div class="col-md-3">
              <label for="service-price" class="form-label">Fixed Price (₱)</label>
              <input 
                type="number" 
                name="price" 
                id="service-price" 
                class="form-control <?= !empty($errors['price']) ? 'is-invalid' : '' ?>" 
                placeholder="500.00" 
                step="0.01" 
                min="1.00" 
                max="50000.00" 
                value="<?= e((string)$price) ?>" 
                required
              >
              <?php if (!empty($errors['price'])): ?>
                <div class="invalid-feedback"><?= e($errors['price']) ?></div>
              <?php endif; ?>
            </div>

            <div class="col-md-3">
              <label for="service-delivery" class="form-label">Turnaround (Days)</label>
              <input 
                type="number" 
                name="delivery_days" 
                id="service-delivery" 
                class="form-control <?= !empty($errors['delivery_days']) ? 'is-invalid' : '' ?>" 
                min="1" 
                max="30" 
                value="<?= e((string)$deliveryDays) ?>" 
                required
              >
              <?php if (!empty($errors['delivery_days'])): ?>
                <div class="invalid-feedback"><?= e($errors['delivery_days']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div id="new-category-fields" class="p-3 mb-3 rounded border <?= $isNewCategory ? '' : 'd-none' ?>" style="background-color: var(--ground); border-color: var(--line) !important;">
            <div class="row g-3">
              <div class="col-md-6">
                <label for="new-category-name" class="form-label">New Category Name</label>
                <input
                  type="text"
                  name="new_category_name"
                  id="new-category-name"
                  class="form-control <?= !empty($errors['new_category_name']) ? 'is-invalid' : '' ?>"
                  placeholder="e.g., Data Analysis & Spreadsheets"
                  value="<?= e($newCategoryName) ?>"
                  maxlength="50"
                >
                <?php if (!empty($errors['new_category_name'])): ?>
                  <div class="invalid-feedback"><?= e($errors['new_category_name']) ?></div>
                <?php endif; ?>
              </div>
              <div class="col-md-6">
                <label for="new-category-desc" class="form-label">Category Description (Optional)</label>
                <input
                  type="text"
                  name="new_category_description"
                  id="new-category-desc"
                  class="form-control <?= !empty($errors['new_category_description']) ? 'is-invalid' : '' ?>"
                  placeholder="Briefly describe services under this category"
                  value="<?= e($newCategoryDescription) ?>"
                  maxlength="255"
                >
                <?php if (!empty($errors['new_category_description'])): ?>
                  <div class="invalid-feedback"><?= e($errors['new_category_description']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label for="service-desc" class="form-label">Service Scope & Deliverable Description</label>
            <textarea 
              name="description" 
              id="service-desc" 
              rows="5" 
              class="form-control <?= !empty($errors['description']) ? 'is-invalid' : '' ?>" 
              placeholder="Clearly specify what you will deliver, tools used, and deliverables provided..." 
              required
            ><?= e($description) ?></textarea>
            <?php if (!empty($errors['description'])): ?>
              <div class="invalid-feedback"><?= e($errors['description']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label for="service-reqs" class="form-label">Requirements from Buyer (Optional)</label>
            <textarea 
              name="requirements" 
              id="service-reqs" 
              rows="3" 
              class="form-control" 
              placeholder="What files, links, or instructions does the student buyer need to provide you?"
            ><?= e($requirements) ?></textarea>
          </div>

          <div class="mb-4">
            <label for="cover-image" class="form-label">Cover Showcase Image (Max 2MB, JPEG/PNG/WebP)</label>
            <input 
              type="file" 
              name="cover_image" 
              id="cover-image" 
              class="form-control <?= !empty($errors['cover_image']) ? 'is-invalid' : '' ?>" 
              accept="image/jpeg,image/png,image/webp"
            >
            <small class="text-muted d-block mt-1">All uploaded images undergo automatic GD re-rasterization to permanently strip camera serials and GPS metadata for privacy.</small>
            <?php if (!empty($errors['cover_image'])): ?>
              <div class="invalid-feedback d-block"><?= e($errors['cover_image']) ?></div>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center justify-content-between pt-3 border-top">
            <a href="<?= e(url('dashboard.php')) ?>" class="btn-tactile btn-outline">Cancel</a>
            <button type="submit" class="btn-tactile btn-accent">
              <?= renderIcon('check', 'icon') ?>
              <span>Publish Listing</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
renderFooter();
