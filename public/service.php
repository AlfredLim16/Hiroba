<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

$serviceId = max(0, (int)($_GET['id'] ?? 0));
if ($serviceId === 0) {
  setFlash('danger', 'Invalid service listing requested.');
  redirect('index.php');
}

$pdo = getDbConnection();

// 1. Fetch Single Service Full Scope and Seller Profile (Query 3.1)
$serviceStmt = $pdo->prepare('
  SELECT 
    s.id AS service_id,
    s.seller_id,
    s.category_id,
    s.title,
    s.slug,
    s.description,
    s.price,
    s.delivery_days,
    s.requirements,
    s.status AS service_status,
    s.created_at AS service_created_at,
    c.name AS category_name,
    c.slug AS category_slug,
    u.name AS seller_name,
    u.email AS seller_email,
    sp.student_id,
    sp.bio AS seller_bio,
    sp.photo_path AS seller_photo,
    sp.created_at AS seller_joined_at
  FROM services s
  JOIN categories c ON s.category_id = c.id
  JOIN users u ON s.seller_id = u.id
  JOIN seller_profiles sp ON u.id = sp.user_id
  WHERE s.id = :service_id AND sp.status = \'approved\'
');
$serviceStmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
$serviceStmt->execute();
$service = $serviceStmt->fetch();

if (!$service) {
  setFlash('danger', 'The requested service was not found or is currently unavailable.');
  redirect('index.php');
}

// 2. Fetch Portfolio Images (Query 3.2)
$imagesStmt = $pdo->prepare('
  SELECT id, image_path, is_primary
  FROM service_images
  WHERE service_id = :service_id
  ORDER BY is_primary DESC, id ASC
');
$imagesStmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
$imagesStmt->execute();
$images = $imagesStmt->fetchAll();

// 3. Fetch Seller Metrics (Query 3.3)
$metricsStmt = $pdo->prepare('
  SELECT 
    COUNT(DISTINCT CASE WHEN o.status = \'completed\' THEN o.id END) AS completed_orders_count,
    COUNT(DISTINCT CASE WHEN s2.status = \'active\' THEN s2.id END) AS active_services_count
  FROM users u
  LEFT JOIN orders o ON u.id = o.seller_id
  LEFT JOIN services s2 ON u.id = s2.seller_id
  WHERE u.id = :seller_id
');
$metricsStmt->bindValue(':seller_id', (int)$service['seller_id'], PDO::PARAM_INT);
$metricsStmt->execute();
$metrics = $metricsStmt->fetch();

$orderError = '';
$briefInput = '';
$currentUser = currentUser();
$isOwner = ($currentUser !== null && (int)$currentUser['id'] === (int)$service['seller_id']);

// 4. Handle Order Submission (Checkout)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isLoggedIn()) {
    setFlash('warn', 'Please sign in to your student account to place an order.');
    redirect('login.php');
  }

  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $orderError = 'Security verification failed (CSRF). Please try again.';
  } elseif (isAdmin()) {
    $orderError = 'Administrator accounts cannot place service orders.';
  } elseif ($isOwner) {
    $orderError = 'You cannot purchase your own service listing.';
  } else {
    $briefInput = trim((string)($_POST['brief'] ?? ''));

    if ($briefInput === '') {
      $orderError = 'Please provide project details and requirements in your brief.';
    } elseif (mb_strlen($briefInput) < 10) {
      $orderError = 'Your order brief should contain at least 10 characters.';
    } elseif (mb_strlen($briefInput) > 2000) {
      $orderError = 'Order brief cannot exceed 2000 characters.';
    } else {
      try {
        // Execute Stored Procedure sp_place_order
        $orderStmt = $pdo->prepare('
          CALL sp_place_order(:service_id, :buyer_id, :brief, @new_order_id, @new_order_number)
        ');
        $orderStmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
        $orderStmt->bindValue(':buyer_id', (int)$currentUser['id'], PDO::PARAM_INT);
        $orderStmt->bindValue(':brief', $briefInput, PDO::PARAM_STR);
        $orderStmt->execute();
        $orderStmt->closeCursor();

        $res = $pdo->query('SELECT @new_order_id AS order_id, @new_order_number AS order_number')->fetch();
        $newOrderId = (int)($res['order_id'] ?? 0);
        $newOrderNumber = (string)($res['order_number'] ?? '');

        if ($newOrderId > 0) {
          setFlash('success', 'Order #' . $newOrderNumber . ' has been initiated! The seller has been notified.');
          redirect('order.php?id=' . $newOrderId);
        } else {
          $orderError = 'Unable to create order. Please try again.';
        }
      } catch (PDOException $e) {
        $orderError = 'Database error during checkout: ' . $e->getMessage();
      }
    }
  }
}

renderHeader($service['title'] . ' — Hiroba', ['current_page' => 'plaza']);
?>

<div class="container py-4">
  <!-- Breadcrumb Navigation -->
  <nav aria-label="Breadcrumb" class="mb-4">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="<?= e(url('index.php')) ?>">Plaza</a></li>
      <li class="breadcrumb-item"><a href="<?= e(url('index.php?category=' . (int)$service['category_id'])) ?>"><?= e($service['category_name']) ?></a></li>
      <li class="breadcrumb-item active text-truncate" aria-current="page" style="max-width: 300px;"><?= e($service['title']) ?></li>
    </ol>
  </nav>

  <!-- Golden Ratio 62/38 Split Layout -->
  <div class="split-layout">
    <!-- Left Workspace (61.8%) -->
    <div class="split-primary">
      <div class="mb-3">
        <span class="eyebrow"><?= e($service['category_name']) ?></span>
        <h1 class="display-h1 my-2"><?= e($service['title']) ?></h1>
        <div class="d-flex align-items-center gap-3 text-muted small">
          <span>Posted <?= e(formatDate($service['service_created_at'])) ?></span>
          <span>&middot;</span>
          <span class="pill pill--progress"><?= (int)$service['delivery_days'] ?> Days Turnaround</span>
        </div>
      </div>

      <!-- Portfolio Gallery / Showcase -->
      <div class="service-gallery mb-5">
        <div class="thumb-wrapper rounded border" style="aspect-ratio: 4 / 3; max-height: 480px; overflow: hidden; background-color: var(--surface);">
          <?php if (!empty($images[0]['image_path']) && file_exists(__DIR__ . '/' . $images[0]['image_path'])): ?>
            <img 
              src="<?= e(url($images[0]['image_path'])) ?>" 
              alt="<?= e($service['title']) ?>" 
              class="w-100 h-100 object-fit-cover"
            >
          <?php else: ?>
            <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-muted">
              <?= renderIcon('badge', 'icon--xl') ?>
              <span class="small mt-2 font-monospace">Verified Service Portfolio</span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Service Scope & Description -->
      <section class="mb-5">
        <h2 class="h2 mb-3">Service Scope and Deliverables</h2>
        <div class="body-text" style="white-space: pre-line; line-height: 1.75;">
          <?= e($service['description']) ?>
        </div>
      </section>

      <!-- Requirements from Buyer -->
      <?php if (!empty($service['requirements'])): ?>
        <section class="service-instruction-box mb-5 p-4 rounded" style="background-color: var(--surface); border: 1px solid var(--line);">
          <h3 class="h3 mb-2">Instructions and Information Needed</h3>
          <p class="body-text small text-muted mb-3">The seller requires the following details from you before starting the order:</p>
          <div class="body-text" style="white-space: pre-line;">
            <?= e($service['requirements']) ?>
          </div>
        </section>
      <?php endif; ?>

      <!-- Vetted Seller Profile Card -->
      <section class="seller-profile-card p-4 rounded mb-4" style="background-color: var(--surface); border: 1px solid var(--line);">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle overflow-hidden d-flex align-items-center justify-content-center bg-secondary-subtle" style="width: 56px; height: 56px;">
            <?php if (!empty($service['seller_photo']) && file_exists(__DIR__ . '/' . $service['seller_photo'])): ?>
              <img src="<?= e(url($service['seller_photo'])) ?>" alt="<?= e($service['seller_name']) ?>" class="w-100 h-100 object-fit-cover">
            <?php else: ?>
              <?= renderIcon('user', 'icon--lg text-muted') ?>
            <?php endif; ?>
          </div>
          <div>
            <div class="d-flex align-items-center gap-2">
              <h3 class="h3 mb-0" style="font-size: 1.15rem;"><?= e($service['seller_name']) ?></h3>
              <?= renderIcon('verified', 'icon--sm text-ok') ?>
              <span class="pill pill--completed" style="font-size: 0.60rem;">Verified Student Seller</span>
            </div>
            <small class="text-muted">Campus Peer Talent</small>
          </div>
        </div>

        <?php if (!empty($service['seller_bio'])): ?>
          <p class="body-text small mb-3"><?= e($service['seller_bio']) ?></p>
        <?php endif; ?>

        <div class="row g-2 pt-2 border-top text-center text-md-start">
          <div class="col-6 col-md-4">
            <small class="text-muted d-block">Orders Completed</small>
            <strong class="font-monospace"><?= (int)($metrics['completed_orders_count'] ?? 0) ?></strong>
          </div>
          <div class="col-6 col-md-4">
            <small class="text-muted d-block">Active Services</small>
            <strong class="font-monospace"><?= (int)($metrics['active_services_count'] ?? 0) ?></strong>
          </div>
          <div class="col-12 col-md-4">
            <small class="text-muted d-block">Member Since</small>
            <span class="small font-monospace"><?= e(formatDate($service['seller_joined_at'] ?? 'now', 'M Y')) ?></span>
          </div>
        </div>
      </section>
    </div>

    <!-- Right Sidebar (38.2% - Sticky Action Console) -->
    <div class="split-sidebar">
      <aside class="sticky-sidebar">
        <div class="card p-4 shadow-sm" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
          <span class="eyebrow">Fixed Price Snapshot</span>
          <div class="d-flex align-items-baseline justify-content-between my-2">
            <span class="price-lg"><?= e(formatPrice((float)$service['price'])) ?></span>
            <span class="pill pill--progress"><?= (int)$service['delivery_days'] ?>d Delivery</span>
          </div>
          <small class="text-muted d-block mb-3">Transparent fixed price. Zero platform bidding fees.</small>

          <?php if ($orderError !== ''): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="font-size: 0.85rem; background-color: var(--danger-soft); border-color: transparent; color: var(--danger);">
              <?= renderIcon('alert', 'icon flex-shrink-0') ?>
              <div><?= e($orderError) ?></div>
            </div>
          <?php endif; ?>

          <?php if (!isAdmin()): ?>
            <?php if ($isOwner): ?>
              <!-- Owner Notice -->
              <div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="font-size: 0.85rem; background-color: var(--surface-raised); border-color: var(--line);">
                <?= renderIcon('info', 'icon flex-shrink-0 text-accent') ?>
                <div>This is your active service listing. You can update details or manage orders from your seller console.</div>
              </div>
              <a href="<?= e(url('service-edit.php?id=' . $serviceId)) ?>" class="btn-tactile btn-outline w-100 justify-content-center mb-2">
                <?= renderIcon('settings', 'icon') ?>
                <span>Edit Service Details</span>
              </a>
            <?php else: ?>
              <!-- Order Placement Form -->
              <form action="<?= e(url('service.php?id=' . $serviceId)) ?>" method="POST">
                <?= csrfField() ?>

                <div class="mb-3">
                  <label for="order-brief" class="form-label">Project Instructions & Brief</label>
                  <textarea 
                    name="brief" 
                    id="order-brief" 
                    rows="4" 
                    class="form-control" 
                    placeholder="Describe your requirements, deadlines, and project details for the student freelancer..."
                    required
                  ><?= e($briefInput) ?></textarea>
                </div>

                <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center mb-2">
                  <?= renderIcon('check', 'icon') ?>
                  <span>Order Now &bull; <?= e(formatPrice((float)$service['price'])) ?></span>
                </button>
              </form>
            <?php endif; ?>

            <!-- Community Safety & Reporting -->
            <div class="mt-4 pt-3 border-top text-center">
              <a href="<?= e(url('report.php?service_id=' . $serviceId)) ?>" class="text-muted small text-decoration-none d-inline-flex align-items-center gap-1">
                <?= renderIcon('alert', 'icon--sm') ?>
                <span>Report this service listing</span>
              </a>
            </div>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </div>
</div>

<?php
renderFooter();
