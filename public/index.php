<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Request Parameters and Sanitization
$searchKeyword = trim((string)($_GET['q'] ?? ''));
$categoryId = max(0, (int)($_GET['category'] ?? 0));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($currentPage - 1) * $perPage;

$pdo = getDbConnection();

// 1. Fetch Skill Categories with Active Listing Counts for Filter Pills
$categoriesStmt = $pdo->query('
  SELECT 
    c.id,
    c.name,
    c.slug,
    COUNT(s.id) AS active_services_count
  FROM categories c
  LEFT JOIN services s ON c.id = s.category_id AND s.status = \'active\'
  WHERE c.status = \'active\'
  GROUP BY c.id, c.name, c.slug
  ORDER BY c.name ASC
');
$categories = $categoriesStmt->fetchAll();

// Build Query Filter Conditions
$whereConditions = ['1=1'];
$queryParams = [];

if ($categoryId > 0) {
  $whereConditions[] = 'category_id = :category_id';
  $queryParams[':category_id'] = $categoryId;
}

if ($searchKeyword !== '') {
  $whereConditions[] = '(title LIKE :search_title OR description LIKE :search_desc)';
  $likePattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $searchKeyword) . '%';
  $queryParams[':search_title'] = $likePattern;
  $queryParams[':search_desc'] = $likePattern;
}

$whereClause = implode(' AND ', $whereConditions);

// 2. Count Total Matching Services for Pagination
$countSql = '
  SELECT COUNT(*) AS total_count
  FROM vw_plaza_services
  WHERE ' . $whereClause;

$countStmt = $pdo->prepare($countSql);
foreach ($queryParams as $paramKey => $paramVal) {
  $countStmt->bindValue($paramKey, $paramVal, is_int($paramVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalServices = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalServices / $perPage));

// 3. Fetch Active Services for Plaza Grid
$servicesSql = '
  SELECT 
    service_id,
    title,
    slug,
    description,
    price,
    delivery_days,
    category_id,
    category_name,
    category_slug,
    seller_id,
    seller_name,
    seller_photo,
    student_id,
    primary_image_path,
    created_at
  FROM vw_plaza_services
  WHERE ' . $whereClause . '
  ORDER BY created_at DESC
  LIMIT :limit OFFSET :offset
';

$servicesStmt = $pdo->prepare($servicesSql);
foreach ($queryParams as $paramKey => $paramVal) {
  $servicesStmt->bindValue($paramKey, $paramVal, is_int($paramVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$servicesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$servicesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$servicesStmt->execute();
$services = $servicesStmt->fetchAll();

renderHeader('Plaza - Academic Student Marketplace', ['current_page' => 'plaza']);
?>

<div class="container py-4">
  <!-- Masthead Section -->
  <header class="plaza-masthead">
    <div class="row align-items-center g-4">
      <div class="col-lg-8">
        <span class="eyebrow">Academic Student Marketplace</span>
        <h1 class="display-h1 my-2">Campus Skill Plaza</h1>
        <p class="deck-p mb-0">
          Discover verified student services, freelance campus talent, and collaborative academic assistance across all university disciplines.
        </p>
      </div>
      <div class="col-lg-4 text-lg-end">
        <?php if (isLoggedIn()): ?>
          <?php if (isAdmin()): ?>
            <a href="<?= e(url('admin.php')) ?>" class="btn-tactile btn-outline">
              <?= renderIcon('shield', 'icon') ?>
              <span>Admin Desk</span>
            </a>
          <?php elseif (isVerifiedSeller()): ?>
            <a href="<?= e(url('service-new.php')) ?>" class="btn-tactile btn-accent">
              <?= renderIcon('plus', 'icon') ?>
              <span>Offer a Service</span>
            </a>
          <?php else: ?>
            <a href="<?= e(url('settings.php#seller-application')) ?>" class="btn-tactile btn-outline">
              <?= renderIcon('shield', 'icon') ?>
              <span>Become a Seller</span>
            </a>
          <?php endif; ?>
        <?php else: ?>
          <a href="<?= e(url('register.php')) ?>" class="btn-tactile btn-accent">
            <?= renderIcon('user', 'icon') ?>
            <span>Join Marketplace</span>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Search and Category Filter Section -->
  <section class="mb-4" aria-label="Plaza Search and Filters">
    <form action="<?= e(url('index.php')) ?>" method="GET" class="row g-2 mb-3 align-items-center">
      <?php if ($categoryId > 0): ?>
        <input type="hidden" name="category" value="<?= $categoryId ?>">
      <?php endif; ?>
      <div class="col-12 col-md-9 col-lg-10">
        <div class="search-box">
          <?= renderIcon('search', 'search-box__icon') ?>
          <input 
            type="search" 
            name="q" 
            class="search-box__input" 
            placeholder="Search by skill, deliverable title, or description..." 
            value="<?= e($searchKeyword) ?>"
            aria-label="Search campus services"
          >
        </div>
      </div>
      <div class="col-12 col-md-3 col-lg-2 d-grid">
        <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center">
          <span>Search</span>
        </button>
      </div>
    </form>

    <!-- Horizontal Category Filter Pills -->
    <nav class="nav-pill-row" aria-label="Category Filter">
      <a 
        href="<?= e(url('index.php' . ($searchKeyword !== '' ? '?q=' . urlencode($searchKeyword) : ''))) ?>" 
        class="nav-tab-pill <?= $categoryId === 0 ? 'active' : '' ?>"
      >
        All Categories
      </a>
      <?php foreach ($categories as $cat): ?>
        <?php
          $catUrlParams = ['category' => (int)$cat['id']];
          if ($searchKeyword !== '') {
            $catUrlParams['q'] = $searchKeyword;
          }
          $isActive = ($categoryId === (int)$cat['id']);
        ?>
        <a 
          href="<?= e(url('index.php?' . http_build_query($catUrlParams))) ?>" 
          class="nav-tab-pill <?= $isActive ? 'active' : '' ?>"
        >
          <?= e($cat['name']) ?> (<?= (int)$cat['active_services_count'] ?>)
        </a>
      <?php endforeach; ?>
    </nav>
  </section>

  <!-- Marketplace Services Grid -->
  <main id="plaza-main-content">
    <?php if (empty($services)): ?>
      <!-- Empty State -->
      <div class="empty-state">
        <div class="empty-state__icon-wrap">
          <?= renderIcon('search', 'icon--xl') ?>
        </div>
        <h3 class="empty-state__title">No Services Found</h3>
        <p class="empty-state__text">
          We could not find any active student services matching your search criteria. Try using different keywords or reset your category filters.
        </p>
        <a href="<?= e(url('index.php')) ?>" class="btn-tactile btn-outline">
          <?= renderIcon('close', 'icon') ?>
          <span>Clear All Filters</span>
        </a>
      </div>
    <?php else: ?>
      <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-lg-4">
        <?php foreach ($services as $service): ?>
          <div class="col d-flex flex-column">
            <article class="service-card flex-grow-1">
              <a href="<?= e(url('service.php?id=' . (int)$service['service_id'])) ?>" class="text-decoration-none color-inherit d-flex flex-column h-100">
                <div class="thumb-wrapper">
                  <?php if (!empty($service['primary_image_path']) && file_exists(__DIR__ . '/' . $service['primary_image_path'])): ?>
                    <img 
                      src="<?= e(url($service['primary_image_path'])) ?>" 
                      alt="<?= e($service['title']) ?>" 
                      class="thumb"
                      loading="lazy"
                    >
                  <?php else: ?>
                    <div class="thumb-placeholder w-100 h-100 d-flex flex-column align-items-center justify-content-center" aria-hidden="true">
                      <?= renderIcon('badge', 'icon--xl text-muted') ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="card-body-content">
                  <span class="eyebrow mb-1"><?= e($service['category_name']) ?></span>
                  <h2 class="card-title"><?= e($service['title']) ?></h2>
                  <p class="card-desc">
                    <?= e(truncateText($service['description'] ?? '', 85)) ?>
                  </p>
                  <div class="card-badge-row">
                    <span class="pill pill--progress">
                      <?= (int)$service['delivery_days'] ?>d Delivery
                    </span>
                  </div>
                </div>

                <div class="meta-footer">
                  <div class="meta-seller">
                    <?= renderIcon('verified', 'icon--sm text-ok') ?>
                    <span><?= e($service['seller_name']) ?></span>
                  </div>
                  <div class="price-card">
                    <?= e(formatPrice((float)$service['price'])) ?>
                  </div>
                </div>
              </a>
            </article>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination Navigation -->
      <?php if ($totalPages > 1): ?>
        <nav class="mt-5 d-flex justify-content-center" aria-label="Plaza Pagination">
          <ul class="pagination gap-1">
            <?php if ($currentPage > 1): ?>
              <?php
                $prevParams = ['page' => $currentPage - 1];
                if ($categoryId > 0) $prevParams['category'] = $categoryId;
                if ($searchKeyword !== '') $prevParams['q'] = $searchKeyword;
              ?>
              <li class="page-item">
                <a class="page-link btn-tactile btn-outline" href="<?= e(url('index.php?' . http_build_query($prevParams))) ?>">
                  Previous
                </a>
              </li>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <?php
                $pageParams = ['page' => $p];
                if ($categoryId > 0) $pageParams['category'] = $categoryId;
                if ($searchKeyword !== '') $pageParams['q'] = $searchKeyword;
              ?>
              <li class="page-item">
                <a 
                  class="page-link btn-tactile <?= $p === $currentPage ? 'btn-accent' : 'btn-outline' ?>" 
                  href="<?= e(url('index.php?' . http_build_query($pageParams))) ?>"
                >
                  <?= $p ?>
                </a>
              </li>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
              <?php
                $nextParams = ['page' => $currentPage + 1];
                if ($categoryId > 0) $nextParams['category'] = $categoryId;
                if ($searchKeyword !== '') $nextParams['q'] = $searchKeyword;
              ?>
              <li class="page-item">
                <a class="page-link btn-tactile btn-outline" href="<?= e(url('index.php?' . http_build_query($nextParams))) ?>">
                  Next
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php
renderFooter();
