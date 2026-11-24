<?php
declare(strict_types=1);

/**
 * Hiroba Shared Layout Module
 * Header, semantic navigation bar, role-based user controls,
 * flash toast container, and footer scaffolding.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

/**
 * Render the HTML document header and navigation bar.
 *
 * @param string $pageTitle
 * @param array<string, mixed> $options
 */
function renderHeader(string $pageTitle = '', array $options = []): void {
    initSession();

    $cleanPageTitle = trim((string)preg_replace('/\s*[—–-]+\s*' . preg_quote(APP_NAME, '/') . '\s*$/iu', '', $pageTitle));
    $siteTitle = $cleanPageTitle !== '' ? e($cleanPageTitle) . ' — ' . e(APP_NAME) : e(APP_NAME) . ' — Student Services Plaza';
    $activeTheme = ($_COOKIE['hiroba_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $currentUser = getCurrentUser();
    $loggedIn = $currentUser !== null;
    $isSeller = isVerifiedSeller();
    $admin = isAdmin();
    $includeChartJs = (bool)($options['chartjs'] ?? false);
    $activeNav = (string)($options['active_nav'] ?? '');
    $flash = getFlash();
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($activeTheme) ?>" data-bs-theme="<?= e($activeTheme) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="<?= $activeTheme === 'dark' ? '#000000' : '#ffffff' ?>">
  <title><?= $siteTitle ?></title>

  <!-- Google Fonts: Newsreader, Inter, and Geist Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@300;400&family=Inter:wght@300;400;500&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">

  <!-- Bootstrap 5.3 CSS via CDN -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

  <!-- Custom Design System Stylesheet -->
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">

  <?php if ($includeChartJs): ?>
  <!-- Chart.js via CDN (Zero-Build Preserved) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <?php endif; ?>
</head>
<body class="site-body">
  <!-- Navigation Header -->
  <header class="site-header sticky-top">
    <nav class="navbar navbar-expand-lg">
      <div class="container">
        <!-- Brand Wordmark -->
        <a class="navbar-brand d-flex align-items-center me-3" href="<?= e(url('index.php')) ?>">
          <span class="brand-text">Hiroba</span>
          <span class="brand-dot"></span>
        </a>

        <!-- Mobile Controls (Theme Toggle + Hamburger) -->
        <div class="d-flex align-items-center gap-2 d-lg-none ms-auto">
          <button type="button" class="btn-theme-toggle" id="themeToggleBtnMobile" aria-label="Toggle visual theme" title="Toggle Light/Dark Theme">
            <span class="theme-icon theme-icon--light" aria-hidden="true"><?= renderIcon('sun') ?></span>
            <span class="theme-icon theme-icon--dark" aria-hidden="true"><?= renderIcon('moon') ?></span>
          </button>
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hirobaNav" aria-controls="hirobaNav" aria-expanded="false" aria-label="Toggle navigation">
            <?= renderIcon('menu', 'navbar-toggler-icon-svg') ?>
          </button>
        </div>

        <!-- Navbar Links and Collapsible Actions -->
        <div class="collapse navbar-collapse" id="hirobaNav">
          <ul class="navbar-nav me-auto mb-2 mb-lg-0 py-2 py-lg-0">
            <li class="nav-item">
              <a class="nav-link <?= $activeNav === 'plaza' ? 'active' : '' ?>" href="<?= e(url('index.php')) ?>">
                Plaza
              </a>
            </li>
            <?php if ($loggedIn): ?>
            <?php if (!$admin): ?>
            <li class="nav-item">
              <a class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard.php')) ?>">
                Dashboard
              </a>
            </li>
            <?php endif; ?>
            <?php if ($isSeller): ?>
            <li class="nav-item">
              <a class="nav-link <?= $activeNav === 'post_service' ? 'active' : '' ?>" href="<?= e(url('service-new.php')) ?>">
                Post Service
              </a>
            </li>
            <?php endif; ?>
            <?php if ($admin): ?>
            <li class="nav-item">
              <a class="nav-link nav-link--admin <?= $activeNav === 'admin' ? 'active' : '' ?>" href="<?= e(url('admin.php')) ?>">
                Admin Desk
              </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
          </ul>

          <!-- Desktop Theme Switcher and Authentication Actions -->
          <div class="nav-actions d-flex flex-column flex-lg-row align-items-lg-center gap-2 gap-lg-3 mt-2 mt-lg-0">
            <!-- Desktop Theme Toggle Button -->
            <button type="button" class="btn-theme-toggle d-none d-lg-inline-flex" id="themeToggleBtn" aria-label="Toggle visual theme" title="Toggle Light/Dark Theme">
              <span class="theme-icon theme-icon--light" aria-hidden="true"><?= renderIcon('sun') ?></span>
              <span class="theme-icon theme-icon--dark" aria-hidden="true"><?= renderIcon('moon') ?></span>
            </button>

            <?php if ($loggedIn): ?>
            <!-- User Profile Dropdown -->
            <div class="dropdown">
              <button class="btn btn-user-dropdown dropdown-toggle d-flex align-items-center gap-2 w-100 justify-content-between justify-content-lg-start" type="button" id="userMenuBtn" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="d-flex align-items-center gap-2">
                  <span class="user-avatar-disc"><?= e(mb_substr((string)$currentUser['name'], 0, 1)) ?></span>
                  <span class="user-display-name"><?= e($currentUser['name']) ?></span>
                </span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userMenuBtn">
                <li><h6 class="dropdown-header"><?= e($currentUser['email']) ?></h6></li>
                <li><hr class="dropdown-divider"></li>
                <?php if (!$admin): ?>
                <li>
                  <a class="dropdown-item d-flex align-items-center gap-2" href="<?= e(url('dashboard.php')) ?>">
                    <?= renderIcon('dashboard', 'icon--sm') ?>
                    My Orders
                  </a>
                </li>
                <?php endif; ?>
                <li>
                  <a class="dropdown-item d-flex align-items-center gap-2" href="<?= e(url('settings.php')) ?>">
                    <?= renderIcon('settings', 'icon--sm') ?>
                    Account Settings
                  </a>
                </li>
                <?php if (!$isSeller && !$admin): ?>
                <li>
                  <a class="dropdown-item d-flex align-items-center gap-2 text-accent" href="<?= e(url('settings.php#seller-application')) ?>">
                    <?= renderIcon('badge', 'icon--sm') ?>
                    Become a Seller
                  </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="<?= e(url('logout.php')) ?>">
                    <?= renderIcon('logout', 'icon--sm') ?>
                    Sign Out
                  </a>
                </li>
              </ul>
            </div>
            <?php else: ?>
            <!-- Guest Links -->
            <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 w-100 w-lg-auto">
              <a href="<?= e(url('login.php')) ?>" class="btn-tactile btn-outline justify-content-center">Sign In</a>
              <a href="<?= e(url('register.php')) ?>" class="btn-tactile btn-accent justify-content-center">Join Plaza</a>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </nav>
  </header>

  <!-- Flash Toast Notification Container -->
  <?php if ($flash !== null): ?>
  <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1080;">
    <div class="toast show toast--<?= e($flash['type']) ?>" role="alert" aria-live="assertive" aria-atomic="true" id="hirobaFlashToast">
      <div class="toast-body d-flex align-items-start gap-2">
        <span class="toast-icon">
          <?php if ($flash['type'] === 'success'): ?>
            <?= renderIcon('check', 'text-ok') ?>
          <?php elseif ($flash['type'] === 'danger'): ?>
            <?= renderIcon('alert', 'text-danger') ?>
          <?php elseif ($flash['type'] === 'warning'): ?>
            <?= renderIcon('alert', 'text-warn') ?>
          <?php else: ?>
            <?= renderIcon('info', 'text-progress') ?>
          <?php endif; ?>
        </span>
        <div class="toast-message flex-grow-1">
          <?= e($flash['message']) ?>
        </div>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="toast" aria-label="Dismiss notification"></button>
      </div>
      <div class="toast-progress"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Main View Content Landmark -->
  <main class="site-main py-4">
<?php
}

/**
 * Render the document footer, static asset scripts, and close body.
 */
function renderFooter(): void {
    ?>
  </main>

  <!-- Site Footer -->
  <footer class="site-footer mt-auto py-5 border-top">
    <div class="container">
      <div class="row g-4 align-items-center">
        <div class="col-md-6 text-center text-md-start">
          <div class="footer-brand d-inline-flex align-items-center mb-2">
            <span class="brand-text">Hiroba</span>
            <span class="brand-dot"></span>
          </div>
          <p class="footer-tagline mb-0">A Student Services and Micro-Freelancing Marketplace Plaza.</p>
          <small class="text-muted d-block mt-1">Upholding Academic Integrity & Campus Peer Assistance.</small>
        </div>
        <div class="col-md-6 text-center text-md-end">
          <ul class="list-inline mb-2">
            <li class="list-inline-item"><a href="<?= e(url('index.php')) ?>" class="footer-link">Plaza</a></li>
            <li class="list-inline-item"><span class="text-muted">·</span></li>
            <li class="list-inline-item"><a href="<?= e(url('login.php')) ?>" class="footer-link">Sign In</a></li>
            <li class="list-inline-item"><span class="text-muted">·</span></li>
            <li class="list-inline-item"><a href="<?= e(url('register.php')) ?>" class="footer-link">Register</a></li>
            <li class="list-inline-item"><span class="text-muted">·</span></li>
            <li class="list-inline-item"><a href="#codeOfConductModal" data-bs-toggle="modal" data-bs-target="#codeOfConductModal" class="footer-link">Code of Conduct</a></li>
          </ul>
          <small class="text-muted">&copy; <?= date('Y') ?> Hiroba. Built for College Communities.</small>
        </div>
      </div>
    </div>
  </footer>

  <!-- Hiroba Community Code of Conduct Modal -->
  <div class="modal fade" id="codeOfConductModal" tabindex="-1" aria-labelledby="codeOfConductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
      <div class="modal-content" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
        <div class="modal-header border-bottom">
          <div>
            <span class="eyebrow d-block mb-1">Community Standards</span>
            <h2 class="modal-title h3 mb-0" id="codeOfConductModalLabel">Hiroba Code of Conduct</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4" style="color: var(--ink);">
          <p class="body-text mb-3">
            Hiroba is an independent student micro-freelancing marketplace built to empower peer learning, collaborative skill-sharing, and campus talent exchange without involving university administrative offices.
          </p>

          <h4 class="h4 mb-2" style="color: var(--accent);">1. Academic Integrity & Honest Assistance</h4>
          <p class="body-text small text-muted mb-2">
            All users commit to honest, ethical peer assistance:
          </p>
          <ul class="body-text small text-muted mb-3 ps-3">
            <li><strong>Prohibited:</strong> Ghostwriting graded personal coursework, taking online exams or quizzes on another student's behalf, plagiarism, or distributing unauthorized academic materials.</li>
            <li><strong>Permitted & Encouraged:</strong> Peer tutoring, conceptual debugging, document formatting and typography cleanup, citation and bibliography verification, slide deck design, creative branding, and multimedia editing.</li>
          </ul>

          <h4 class="h4 mb-2" style="color: var(--accent);">2. Transparent Scope & Fixed Pricing</h4>
          <ul class="body-text small text-muted mb-3 ps-3">
            <li>All services carry upfront fixed pricing agreed upon before order initiation. No hidden platform bidding or auction inflation.</li>
            <li>Sellers commit to meeting stated delivery turnaround times and updating clients promptly if scope adjustments are necessary.</li>
            <li>Buyers must provide clear, concise project instructions and reference materials upon order initiation.</li>
          </ul>

          <h4 class="h4 mb-2" style="color: var(--accent);">3. Respectful Communication & Professional Conduct</h4>
          <ul class="body-text small text-muted mb-3 ps-3">
            <li>Maintain courteous, respectful, and constructive peer-to-peer communication at all times.</li>
            <li>Harassment, discriminatory remarks, spamming, or fraudulent behavior will result in immediate suspension by Hiroba Administrators.</li>
          </ul>

          <h4 class="h4 mb-2" style="color: var(--accent);">4. Student Privacy & PII Confidentiality</h4>
          <ul class="body-text small text-muted mb-3 ps-3">
            <li>Student IDs and verification documents submitted during seller onboarding are confidential and strictly reviewed by Hiroba Administrators. They are never rendered publicly on service pages, order logs, or peer profiles.</li>
            <li>Never disclose student portal passwords, university authentication keys, or financial credentials in order discussions.</li>
          </ul>

          <h4 class="h4 mb-2" style="color: var(--accent);">5. Platform Governance by Hiroba Administrators</h4>
          <ul class="body-text small text-muted mb-0 ps-3">
            <li>Hiroba is administered by independent Hiroba Administrators. Campus or college faculty are not administrators of this marketplace.</li>
            <li>If you discover any listing or deliverable violating this Code of Conduct, submit a report via the listing page for prompt administrative review.</li>
          </ul>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn-tactile btn-accent" data-bs-dismiss="modal">
            <?= renderIcon('check', 'icon') ?>
            <span>I Understand & Agree</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap 5.3 JS Bundle CDN -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

  <!-- Custom Client JavaScript -->
  <script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}
