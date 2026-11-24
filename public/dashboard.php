<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Authentication required (student workspace only; admins use Admin Desk)
requireLogin();
if (isAdmin()) {
  redirect('admin.php');
}

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];
$isSeller = isVerifiedSeller();
$sellerProfile = getSellerProfile();

// Active tab ('purchases' or 'seller')
$activeTab = trim((string)($_GET['tab'] ?? ($isSeller ? 'seller' : 'purchases')));
if ($activeTab !== 'purchases' && $activeTab !== 'seller' && $activeTab !== 'listings') {
  $activeTab = $isSeller ? 'seller' : 'purchases';
}

// 1. Fetch Buyer Orders (Query 5.1)
$buyerStmt = $pdo->prepare('
  SELECT 
    o.id AS order_id,
    o.order_number,
    o.service_id,
    s.title AS service_title,
    c.name AS category_name,
    u.name AS seller_name,
    o.price_at_order AS price_snapshot,
    o.status,
    o.created_at,
    o.updated_at
  FROM orders o
  JOIN services s ON o.service_id = s.id
  JOIN categories c ON s.category_id = c.id
  JOIN users u ON o.seller_id = u.id
  WHERE o.buyer_id = :buyer_id
  ORDER BY o.created_at DESC
');
$buyerStmt->bindValue(':buyer_id', $userId, PDO::PARAM_INT);
$buyerStmt->execute();
$buyerOrders = $buyerStmt->fetchAll();

// 2. Fetch Seller Orders (Query 5.2) if seller
$sellerOrders = [];
$sellerListings = [];
$totalEarnings = 0.0;
$sellerActiveCount = 0;

if ($isSeller) {
  $sellerStmt = $pdo->prepare('
    SELECT 
      o.id AS order_id,
      o.order_number,
      o.service_id,
      s.title AS service_title,
      c.name AS category_name,
      u.name AS buyer_name,
      u.email AS buyer_email,
      o.price_at_order AS price_snapshot,
      o.status,
      o.revision_count,
      o.created_at,
      o.updated_at
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    JOIN users u ON o.buyer_id = u.id
    WHERE o.seller_id = :seller_id
    ORDER BY o.created_at DESC
  ');
  $sellerStmt->bindValue(':seller_id', $userId, PDO::PARAM_INT);
  $sellerStmt->execute();
  $sellerOrders = $sellerStmt->fetchAll();

  foreach ($sellerOrders as $so) {
    if ($so['status'] === 'completed') {
      $totalEarnings += (float)$so['price_snapshot'];
    } elseif ($so['status'] === 'in_progress' || $so['status'] === 'submitted' || $so['status'] === 'pending') {
      $sellerActiveCount++;
    }
  }

  // 3. Fetch Seller Listings (Query 5.3)
  $listingsStmt = $pdo->prepare('
    SELECT 
      s.id AS service_id,
      s.title,
      s.slug,
      s.price,
      s.delivery_days,
      s.status,
      c.name AS category_name,
      (SELECT COUNT(*) FROM orders o WHERE o.service_id = s.id) AS total_orders_count
    FROM services s
    JOIN categories c ON s.category_id = c.id
    WHERE s.seller_id = :seller_id
    ORDER BY s.created_at DESC
  ');
  $listingsStmt->bindValue(':seller_id', $userId, PDO::PARAM_INT);
  $listingsStmt->execute();
  $sellerListings = $listingsStmt->fetchAll();
}

renderHeader('Dashboard — Hiroba', ['current_page' => 'dashboard']);
?>

<div class="container py-4">
  <!-- Masthead -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-3 border-bottom">
    <div>
      <span class="eyebrow">Academic Workspace</span>
      <h1 class="dashboard-masthead-title my-1">Welcome, <?= e($currentUser['name']) ?></h1>
      <p class="body-text small text-muted mb-0">Track and manage your campus orders, client requests, and academic collaborations.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <?php if (isAdmin()): ?>
        <a href="<?= e(url('admin.php')) ?>" class="btn-tactile btn-outline">
          <?= renderIcon('shield', 'icon') ?>
          <span>Admin Desk</span>
        </a>
      <?php elseif ($isSeller): ?>
        <a href="<?= e(url('service-new.php')) ?>" class="btn-tactile btn-accent">
          <?= renderIcon('plus', 'icon') ?>
          <span>New Service</span>
        </a>
      <?php else: ?>
        <a href="<?= e(url('settings.php#seller-application')) ?>" class="btn-tactile btn-outline">
          <?= renderIcon('shield', 'icon') ?>
          <span>Become a Seller</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Metric Statistics Cards -->
  <div class="row g-2 g-md-3 mb-4 mb-md-5">
    <?php if ($isSeller): ?>
      <div class="col-6 col-md-3">
        <div class="dashboard-stat-card">
          <small class="text-muted d-block mb-1 text-truncate" title="Active Client Orders">Active Client Orders</small>
          <div class="price-lg" style="color: var(--progress);"><?= $sellerActiveCount ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="dashboard-stat-card">
          <small class="text-muted d-block mb-1 text-truncate" title="Completed Earnings">Completed Earnings</small>
          <div class="price-lg" style="color: var(--ok);"><?= e(formatPrice($totalEarnings)) ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="dashboard-stat-card">
          <small class="text-muted d-block mb-1 text-truncate" title="Active Listings">Active Listings</small>
          <div class="price-lg"><?= count($sellerListings) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <div class="col-6 col-md-3">
      <div class="dashboard-stat-card">
        <small class="text-muted d-block mb-1 text-truncate" title="Purchases Placed">Purchases Placed</small>
        <div class="price-lg"><?= count($buyerOrders) ?></div>
      </div>
    </div>
  </div>

  <!-- Workspace Navigation Tabs -->
  <div class="nav-pill-row mb-4" role="tablist">
    <?php if ($isSeller): ?>
      <a href="<?= e(url('dashboard.php?tab=seller')) ?>" class="nav-tab-pill <?= $activeTab === 'seller' ? 'active' : '' ?>">
        Client Orders (<?= count($sellerOrders) ?>)
      </a>
      <a href="<?= e(url('dashboard.php?tab=listings')) ?>" class="nav-tab-pill <?= $activeTab === 'listings' ? 'active' : '' ?>">
        My Services (<?= count($sellerListings) ?>)
      </a>
    <?php endif; ?>
    <a href="<?= e(url('dashboard.php?tab=purchases')) ?>" class="nav-tab-pill <?= $activeTab === 'purchases' ? 'active' : '' ?>">
      My Purchases (<?= count($buyerOrders) ?>)
    </a>
  </div>

  <!-- Tab Content: Client Orders (Seller) -->
  <?php if ($activeTab === 'seller' && $isSeller): ?>
    <section>
      <?php if (empty($sellerOrders)): ?>
        <div class="empty-state">
          <div class="empty-state__icon-wrap">
            <?= renderIcon('dashboard', 'icon--xl') ?>
          </div>
          <h3 class="empty-state__title">No Incoming Orders Yet</h3>
          <p class="empty-state__text">
            When students purchase one of your service listings, orders will appear here for you to accept and complete.
          </p>
        </div>
      <?php else: ?>
        <div class="table-responsive dashboard-table-wrap">
          <table class="table align-middle dashboard-table">
            <thead style="background-color: var(--surface); font-family: var(--font-sans); font-size: 0.82rem; color: var(--ink-2);">
              <tr>
                <th class="col-order-no">Order #</th>
                <th class="col-service">Service</th>
                <th class="d-none d-md-table-cell">Client</th>
                <th class="d-none d-md-table-cell">Amount</th>
                <th class="d-none d-sm-table-cell">Status</th>
                <th class="d-none d-lg-table-cell">Initiated</th>
                <th class="col-action text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sellerOrders as $so): ?>
                <tr>
                  <td class="col-order-no">
                    <a href="<?= e(url('order.php?id=' . (int)$so['order_id'])) ?>" class="order-no-link">
                      #<?= e($so['order_number']) ?>
                    </a>
                  </td>
                  <td class="col-service">
                    <div class="service-title-cell text-truncate"><?= e($so['service_title']) ?></div>
                    <small class="text-muted d-block text-truncate"><?= e($so['category_name']) ?></small>
                    <div class="d-sm-none mt-1 d-flex align-items-center gap-1 flex-wrap">
                      <span class="pill pill--<?= e($so['status']) ?>" style="font-size: 0.65rem; padding: 1px 6px;">
                        <?= e(ucwords(str_replace('_', ' ', (string)$so['status']))) ?>
                      </span>
                      <span class="font-monospace fw-bold small"><?= e(formatPrice((float)$so['price_snapshot'])) ?></span>
                    </div>
                  </td>
                  <td class="d-none d-md-table-cell">
                    <div><?= e($so['buyer_name']) ?></div>
                    <small class="text-muted font-monospace"><?= e($so['buyer_email']) ?></small>
                  </td>
                  <td class="font-monospace fw-bold d-none d-md-table-cell"><?= e(formatPrice((float)$so['price_snapshot'])) ?></td>
                  <td class="d-none d-sm-table-cell">
                    <span class="pill pill--<?= e($so['status']) ?>">
                      <?= e(ucwords(str_replace('_', ' ', (string)$so['status']))) ?>
                    </span>
                  </td>
                  <td class="small text-muted font-monospace d-none d-lg-table-cell"><?= e(formatDate($so['created_at'])) ?></td>
                  <td class="col-action text-end">
                    <a href="<?= e(url('order.php?id=' . (int)$so['order_id'])) ?>" class="btn-tactile btn-outline btn-sm d-none d-md-inline-flex">
                      Open Console
                    </a>
                    <button type="button" class="btn-icon-dots d-md-none" data-bs-toggle="offcanvas" data-bs-target="#orderSheet-<?= (int)$so['order_id'] ?>" aria-label="Order details">
                      <?= renderIcon('dots', 'icon--sm') ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile Rising Bottom Sheets for Incoming Orders -->
        <?php foreach ($sellerOrders as $so): ?>
          <div class="offcanvas offcanvas-bottom" tabindex="-1" id="orderSheet-<?= (int)$so['order_id'] ?>" aria-labelledby="orderSheetLabel-<?= (int)$so['order_id'] ?>">
            <div class="sheet-grab-handle"></div>
            <div class="offcanvas-header pt-0 pb-2">
              <div>
                <span class="eyebrow d-block mb-1">Incoming Client Order</span>
                <h5 class="offcanvas-title font-monospace fw-bold" id="orderSheetLabel-<?= (int)$so['order_id'] ?>" style="color: var(--accent);">
                  #<?= e($so['order_number']) ?>
                </h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body pt-2 pb-4">
              <div class="mb-3">
                <small class="text-muted d-block mb-1">Service Listing</small>
                <div class="fw-normal fs-6"><?= e($so['service_title']) ?></div>
                <span class="pill pill--progress mt-1"><?= e($so['category_name']) ?></span>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Client</small>
                  <div class="fw-normal"><?= e($so['buyer_name']) ?></div>
                  <small class="font-monospace text-muted"><?= e($so['buyer_email']) ?></small>
                </div>
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Total Amount</small>
                  <div class="price-card fs-5" style="color: var(--ok);"><?= e(formatPrice((float)$so['price_snapshot'])) ?></div>
                </div>
              </div>

              <div class="row g-2 mb-4">
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Order Status</small>
                  <span class="pill pill--<?= e($so['status']) ?>">
                    <?= e(ucwords(str_replace('_', ' ', (string)$so['status']))) ?>
                  </span>
                </div>
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Date Initiated</small>
                  <div class="small font-monospace"><?= e(formatDate($so['created_at'])) ?></div>
                </div>
              </div>

              <a href="<?= e(url('order.php?id=' . (int)$so['order_id'])) ?>" class="btn-tactile btn-accent w-100 justify-content-center">
                <?= renderIcon('arrow-right', 'icon') ?>
                <span>Open Order Console</span>
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

  <!-- Tab Content: My Services (Seller Listings) -->
  <?php elseif ($activeTab === 'listings' && $isSeller): ?>
    <section>
      <?php if (empty($sellerListings)): ?>
        <div class="empty-state">
          <div class="empty-state__icon-wrap">
            <?= renderIcon('badge', 'icon--xl') ?>
          </div>
          <h3 class="empty-state__title">No Active Listings</h3>
          <p class="empty-state__text">You have not published any services yet. Create a listing to start offering your skills.</p>
          <a href="<?= e(url('service-new.php')) ?>" class="btn-tactile btn-accent">Offer a Service</a>
        </div>
      <?php else: ?>
        <div class="table-responsive dashboard-table-wrap">
          <table class="table align-middle dashboard-table">
            <thead style="background-color: var(--surface); font-family: var(--font-sans); font-size: 0.82rem; color: var(--ink-2);">
              <tr>
                <th class="col-listing-title">Listing Title</th>
                <th class="d-none d-md-table-cell">Category</th>
                <th class="d-none d-sm-table-cell">Price</th>
                <th class="d-none d-lg-table-cell">Delivery</th>
                <th class="col-listing-status text-center">Status</th>
                <th class="d-none d-md-table-cell">Orders</th>
                <th class="col-action text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sellerListings as $sl): ?>
                <tr>
                  <td class="col-listing-title">
                    <a href="<?= e(url('service.php?id=' . (int)$sl['service_id'])) ?>" class="service-title-cell text-truncate d-block text-decoration-none color-inherit">
                      <?= e($sl['title']) ?>
                    </a>
                    <small class="text-muted d-md-none d-block text-truncate"><?= e($sl['category_name']) ?> &bull; <strong class="font-monospace"><?= e(formatPrice((float)$sl['price'])) ?></strong></small>
                  </td>
                  <td class="small text-muted d-none d-md-table-cell"><?= e($sl['category_name']) ?></td>
                  <td class="font-monospace fw-bold d-none d-sm-table-cell"><?= e(formatPrice((float)$sl['price'])) ?></td>
                  <td class="small d-none d-lg-table-cell"><?= (int)$sl['delivery_days'] ?> days</td>
                  <td class="col-listing-status text-center">
                    <span class="badge <?= $sl['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-muted' ?>" style="font-size: 0.72rem; padding: 3px 6px;">
                      <?= e(ucfirst((string)$sl['status'])) ?>
                    </span>
                  </td>
                  <td class="font-monospace d-none d-md-table-cell"><?= (int)$sl['total_orders_count'] ?></td>
                  <td class="col-action text-end">
                    <div class="d-none d-md-inline-flex align-items-center gap-1">
                      <a href="<?= e(url('service-edit.php?id=' . (int)$sl['service_id'])) ?>" class="btn-tactile btn-outline btn-sm">Edit</a>
                      <form action="<?= e(url('service-hide.php')) ?>" method="POST" class="d-inline" onsubmit="return confirm('Toggle listing status?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="service_id" value="<?= (int)$sl['service_id'] ?>">
                        <button type="submit" class="btn-tactile btn-outline btn-sm text-muted">
                          <?= $sl['status'] === 'active' ? 'Hide' : 'Activate' ?>
                        </button>
                      </form>
                    </div>
                    <button type="button" class="btn-icon-dots d-md-none" data-bs-toggle="offcanvas" data-bs-target="#listingSheet-<?= (int)$sl['service_id'] ?>" aria-label="Listing options">
                      <?= renderIcon('dots', 'icon--sm') ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile Rising Bottom Sheets for Listings -->
        <?php foreach ($sellerListings as $sl): ?>
          <div class="offcanvas offcanvas-bottom" tabindex="-1" id="listingSheet-<?= (int)$sl['service_id'] ?>" aria-labelledby="listingSheetLabel-<?= (int)$sl['service_id'] ?>">
            <div class="sheet-grab-handle"></div>
            <div class="offcanvas-header pt-0 pb-2">
              <div>
                <span class="eyebrow d-block mb-1">Service Listing Options</span>
                <h5 class="offcanvas-title fw-normal fs-6 text-truncate" id="listingSheetLabel-<?= (int)$sl['service_id'] ?>" style="max-width: 280px;">
                  <?= e($sl['title']) ?>
                </h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body pt-2 pb-4">
              <div class="row g-2 mb-3">
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Category</small>
                  <span class="pill pill--progress"><?= e($sl['category_name']) ?></span>
                </div>
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Fixed Price</small>
                  <div class="price-card fs-5"><?= e(formatPrice((float)$sl['price'])) ?></div>
                </div>
              </div>

              <div class="row g-2 mb-4">
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Turnaround</small>
                  <span class="small font-monospace"><?= (int)$sl['delivery_days'] ?> Days</span>
                </div>
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Total Client Orders</small>
                  <span class="font-monospace fw-bold"><?= (int)$sl['total_orders_count'] ?></span>
                </div>
              </div>

              <div class="d-flex flex-column gap-2">
                <a href="<?= e(url('service-edit.php?id=' . (int)$sl['service_id'])) ?>" class="btn-tactile btn-accent w-100 justify-content-center">
                  <?= renderIcon('settings', 'icon') ?>
                  <span>Edit Service Scope & Pricing</span>
                </a>
                <a href="<?= e(url('service.php?id=' . (int)$sl['service_id'])) ?>" class="btn-tactile btn-outline w-100 justify-content-center">
                  <?= renderIcon('arrow-right', 'icon') ?>
                  <span>View Public Listing</span>
                </a>
                <form action="<?= e(url('service-hide.php')) ?>" method="POST" onsubmit="return confirm('Toggle listing status?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="service_id" value="<?= (int)$sl['service_id'] ?>">
                  <button type="submit" class="btn-tactile btn-outline w-100 justify-content-center text-muted">
                    <?= $sl['status'] === 'active' ? 'Hide from Marketplace' : 'Activate in Marketplace' ?>
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

  <!-- Tab Content: My Purchases (Buyer) -->
  <?php else: ?>
    <section>
      <?php if (empty($buyerOrders)): ?>
        <div class="empty-state">
          <div class="empty-state__icon-wrap">
            <?= renderIcon('search', 'icon--xl') ?>
          </div>
          <h3 class="empty-state__title">No Purchases Yet</h3>
          <p class="empty-state__text">
            You have not placed any orders. Browse verified campus skills in the Plaza to hire talented student peers.
          </p>
          <a href="<?= e(url('index.php')) ?>" class="btn-tactile btn-accent">Explore Plaza</a>
        </div>
      <?php else: ?>
        <div class="table-responsive dashboard-table-wrap">
          <table class="table align-middle dashboard-table">
            <thead style="background-color: var(--surface); font-family: var(--font-sans); font-size: 0.82rem; color: var(--ink-2);">
              <tr>
                <th class="col-order-no">Order #</th>
                <th class="col-service">Service</th>
                <th class="d-none d-md-table-cell">Seller</th>
                <th class="d-none d-md-table-cell">Amount</th>
                <th class="d-none d-sm-table-cell">Status</th>
                <th class="d-none d-lg-table-cell">Date</th>
                <th class="col-action text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($buyerOrders as $bo): ?>
                <tr>
                  <td class="col-order-no">
                    <a href="<?= e(url('order.php?id=' . (int)$bo['order_id'])) ?>" class="order-no-link">
                      #<?= e($bo['order_number']) ?>
                    </a>
                  </td>
                  <td class="col-service">
                    <div class="service-title-cell text-truncate"><?= e($bo['service_title']) ?></div>
                    <small class="text-muted d-block text-truncate"><?= e($bo['category_name']) ?></small>
                    <div class="d-sm-none mt-1 d-flex align-items-center gap-1 flex-wrap">
                      <span class="pill pill--<?= e($bo['status']) ?>" style="font-size: 0.65rem; padding: 1px 6px;">
                        <?= e(ucwords(str_replace('_', ' ', (string)$bo['status']))) ?>
                      </span>
                      <span class="font-monospace fw-bold small"><?= e(formatPrice((float)$bo['price_snapshot'])) ?></span>
                    </div>
                  </td>
                  <td class="d-none d-md-table-cell"><?= e($bo['seller_name']) ?></td>
                  <td class="font-monospace fw-bold d-none d-md-table-cell"><?= e(formatPrice((float)$bo['price_snapshot'])) ?></td>
                  <td class="d-none d-sm-table-cell">
                    <span class="pill pill--<?= e($bo['status']) ?>">
                      <?= e(ucwords(str_replace('_', ' ', (string)$bo['status']))) ?>
                    </span>
                  </td>
                  <td class="small text-muted font-monospace d-none d-lg-table-cell"><?= e(formatDate($bo['created_at'])) ?></td>
                  <td class="col-action text-end">
                    <a href="<?= e(url('order.php?id=' . (int)$bo['order_id'])) ?>" class="btn-tactile btn-outline btn-sm d-none d-md-inline-flex">
                      View Progress
                    </a>
                    <button type="button" class="btn-icon-dots d-md-none" data-bs-toggle="offcanvas" data-bs-target="#purchaseSheet-<?= (int)$bo['order_id'] ?>" aria-label="Order details">
                      <?= renderIcon('dots', 'icon--sm') ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile Rising Bottom Sheets for Purchases -->
        <?php foreach ($buyerOrders as $bo): ?>
          <div class="offcanvas offcanvas-bottom" tabindex="-1" id="purchaseSheet-<?= (int)$bo['order_id'] ?>" aria-labelledby="purchaseSheetLabel-<?= (int)$bo['order_id'] ?>">
            <div class="sheet-grab-handle"></div>
            <div class="offcanvas-header pt-0 pb-2">
              <div>
                <span class="eyebrow d-block mb-1">Purchased Service Order</span>
                <h5 class="offcanvas-title font-monospace fw-bold" id="purchaseSheetLabel-<?= (int)$bo['order_id'] ?>" style="color: var(--accent);">
                  #<?= e($bo['order_number']) ?>
                </h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body pt-2 pb-4">
              <div class="mb-3">
                <small class="text-muted d-block mb-1">Service Listing</small>
                <div class="fw-normal fs-6"><?= e($bo['service_title']) ?></div>
                <span class="pill pill--progress mt-1"><?= e($bo['category_name']) ?></span>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Student Freelancer</small>
                  <div class="fw-normal"><?= e($bo['seller_name']) ?></div>
                </div>
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Price Snapshot</small>
                  <div class="price-card fs-5" style="color: var(--ok);"><?= e(formatPrice((float)$bo['price_snapshot'])) ?></div>
                </div>
              </div>

              <div class="row g-2 mb-4">
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Status</small>
                  <span class="pill pill--<?= e($bo['status']) ?>">
                    <?= e(ucwords(str_replace('_', ' ', (string)$bo['status']))) ?>
                  </span>
                </div>
                <div class="col-6">
                  <small class="text-muted d-block mb-1">Date Placed</small>
                  <div class="small font-monospace"><?= e(formatDate($bo['created_at'])) ?></div>
                </div>
              </div>

              <a href="<?= e(url('order.php?id=' . (int)$bo['order_id'])) ?>" class="btn-tactile btn-accent w-100 justify-content-center">
                <?= renderIcon('arrow-right', 'icon') ?>
                <span>Open Order Console</span>
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<?php
renderFooter();
