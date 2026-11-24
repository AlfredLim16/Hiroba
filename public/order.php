<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

// Authentication required
requireLogin();

$orderId = max(0, (int)($_GET['id'] ?? 0));
if ($orderId === 0) {
  http_response_code(404);
  setFlash('danger', 'Order not found.');
  redirect('dashboard.php');
}

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];
$isUserAdmin = isAdmin();

// 1. Fetch Order Details (Query 4.1)
$orderStmt = $pdo->prepare('
  SELECT 
    o.id AS order_id,
    o.order_number,
    o.service_id,
    o.title_at_order AS service_title,
    o.price_at_order AS price_snapshot,
    o.delivery_days_at_order,
    o.status,
    o.revision_count,
    o.created_at,
    o.updated_at,
    o.buyer_id,
    buyer.name AS buyer_name,
    buyer.email AS buyer_email,
    o.seller_id,
    seller.name AS seller_name,
    seller.email AS seller_email,
    sp.student_id AS seller_student_id
  FROM orders o
  JOIN users buyer ON o.buyer_id = buyer.id
  JOIN users seller ON o.seller_id = seller.id
  LEFT JOIN seller_profiles sp ON o.seller_id = sp.user_id
  WHERE o.id = :order_id
');
$orderStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
$orderStmt->execute();
$order = $orderStmt->fetch();

// Security rule: 404 instead of 403 on Orders for unauthorized users
if (!$order) {
  http_response_code(404);
  setFlash('danger', 'Order record not found.');
  redirect('dashboard.php');
}

// Fetch Initial Brief from order_messages
$briefStmt = $pdo->prepare('
  SELECT content 
  FROM order_messages 
  WHERE order_id = :order_id AND message_type = \'initial_brief\' 
  ORDER BY id ASC 
  LIMIT 1
');
$briefStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
$briefStmt->execute();
$order['initial_brief'] = (string)($briefStmt->fetchColumn() ?: 'No brief recorded.');

$isBuyer = ($userId === (int)$order['buyer_id']);
$isSeller = ($userId === (int)$order['seller_id']);

if (!$isBuyer && !$isSeller && !$isUserAdmin) {
  http_response_code(404);
  setFlash('danger', 'Order record not found.');
  redirect('dashboard.php');
}

$orderError = '';

// 2. Handle State Machine Action Transitions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = (string)($_POST['csrf_token'] ?? '');
  if (!verifyCsrfToken($submittedToken)) {
    $orderError = 'Security validation failed (CSRF). Please refresh and try again.';
  } else {
    $action = trim((string)($_POST['order_action'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $deliverableUrl = trim((string)($_POST['deliverable_url'] ?? ''));
    $currentStatus = (string)$order['status'];

    try {
      // Step 1: Seller Approves Order (pending -> in_progress)
      if ($action === 'approve_order' && $isSeller && $currentStatus === 'pending') {
        dbTransaction(function (PDO $db) use ($orderId, $userId) {
          $update = $db->prepare('UPDATE orders SET status = \'in_progress\', updated_at = NOW() WHERE id = :id');
          $update->execute([':id' => $orderId]);

          $audit = $db->prepare('
            INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, reason)
            VALUES (:order_id, \'pending\', \'in_progress\', :user_id, \'Order approved and accepted by seller.\')
          ');
          $audit->execute([':order_id' => $orderId, ':user_id' => $userId]);
        });

        setFlash('success', 'Order has been approved! You may now begin work.');
        redirect('order.php?id=' . $orderId);
      }

      // Step 2 & 3: Cancel or Reject Order (pending/in_progress -> cancelled)
      elseif (($action === 'reject_order' || $action === 'cancel_order') && ($isBuyer || $isSeller)) {
        if ($reason === '') {
          $orderError = 'A written explanation is mandatory when rejecting or cancelling an order.';
        } elseif (mb_strlen($reason) < 5) {
          $orderError = 'Cancellation explanation must be at least 5 characters.';
        } else {
          $canCancel = false;
          if ($currentStatus === 'pending') {
            $canCancel = true; // Either buyer or seller can cancel/reject
          } elseif ($currentStatus === 'in_progress' && $isBuyer) {
            $canCancel = true; // Buyer can cancel during in_progress
          }

          if ($canCancel) {
            dbTransaction(function (PDO $db) use ($orderId, $currentStatus, $userId, $reason) {
              $update = $db->prepare('UPDATE orders SET status = \'cancelled\', updated_at = NOW() WHERE id = :id');
              $update->execute([':id' => $orderId]);

              $audit = $db->prepare('
                INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, reason)
                VALUES (:order_id, :from_status, \'cancelled\', :user_id, :reason)
              ');
              $audit->execute([
                ':order_id' => $orderId,
                ':from_status' => $currentStatus,
                ':user_id' => $userId,
                ':reason' => $reason
              ]);
            });

            setFlash('info', 'The order has been cancelled.');
            redirect('order.php?id=' . $orderId);
          } else {
            $orderError = 'Cancellation is not permitted in the current order state.';
          }
        }
      }

      // Step 4: Seller Submits Work Deliverable (in_progress -> submitted)
      elseif ($action === 'submit_deliverable' && $isSeller && $currentStatus === 'in_progress') {
        if ($deliverableUrl === '' || !filter_var($deliverableUrl, FILTER_VALIDATE_URL)) {
          $orderError = 'Please provide a valid cloud deliverable URL (e.g. Google Drive, Figma, GitHub).';
        } else {
          // Execute sp_submit_order_deliverable
          $subStmt = $pdo->prepare('
            CALL sp_submit_order_deliverable(:order_id, :seller_id, :url, :notes)
          ');
          $subStmt->execute([
            ':order_id' => $orderId,
            ':seller_id' => $userId,
            ':url' => $deliverableUrl,
            ':notes' => $reason !== '' ? $reason : 'Deliverable submitted for buyer review.'
          ]);
          $subStmt->closeCursor();

          setFlash('success', 'Deliverable link submitted successfully! Waiting for buyer review.');
          redirect('order.php?id=' . $orderId);
        }
      }

      // Step 6: Buyer Accepts Work (submitted -> completed)
      elseif ($action === 'accept_work' && $isBuyer && $currentStatus === 'submitted') {
        dbTransaction(function (PDO $db) use ($orderId, $userId) {
          $update = $db->prepare('UPDATE orders SET status = \'completed\', updated_at = NOW() WHERE id = :id');
          $update->execute([':id' => $orderId]);

          $audit = $db->prepare('
            INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, reason)
            VALUES (:order_id, \'submitted\', \'completed\', :user_id, \'Deliverable approved and accepted by buyer.\')
          ');
          $audit->execute([':order_id' => $orderId, ':user_id' => $userId]);
        });

        setFlash('success', 'Congratulations! You have accepted the deliverable and marked the order as completed.');
        redirect('order.php?id=' . $orderId);
      }

      // Step 7: Buyer Requests Changes (submitted -> in_progress)
      elseif ($action === 'request_revision' && $isBuyer && $currentStatus === 'submitted') {
        if ($reason === '') {
          $orderError = 'Please provide specific revision instructions for the seller.';
        } else {
          dbTransaction(function (PDO $db) use ($orderId, $userId, $reason) {
            $update = $db->prepare('
              UPDATE orders 
              SET status = \'in_progress\', 
                  revision_count = revision_count + 1, 
                  updated_at = NOW() 
              WHERE id = :id
            ');
            $update->execute([':id' => $orderId]);

            $audit = $db->prepare('
              INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, reason)
              VALUES (:order_id, \'submitted\', \'in_progress\', :user_id, :reason)
            ');
            $audit->execute([
              ':order_id' => $orderId,
              ':user_id' => $userId,
              ':reason' => 'Revision requested: ' . $reason
            ]);
          });

          setFlash('warn', 'Change request submitted. The order is now back In Progress.');
          redirect('order.php?id=' . $orderId);
        }
      } else {
        $orderError = 'Invalid action or permission denied for this state transition.';
      }
    } catch (PDOException $e) {
      $orderError = 'Database operation error: ' . $e->getMessage();
    }
  }
}

// 3. Fetch Deliverables
$delivStmt = $pdo->prepare('
  SELECT id, version AS version_number, deliverable_url, notes AS seller_notes, created_at AS submitted_at
  FROM order_deliverables
  WHERE order_id = :order_id
  ORDER BY version DESC
');
$delivStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
$delivStmt->execute();
$deliverables = $delivStmt->fetchAll();

// 4. Fetch Audit Timeline
$auditStmt = $pdo->prepare('
  SELECT 
    h.id,
    h.from_status,
    h.to_status,
    h.changed_by,
    u.name AS actor_name,
    h.reason AS change_reason,
    h.created_at
  FROM order_status_history h
  JOIN users u ON h.changed_by = u.id
  WHERE h.order_id = :order_id
  ORDER BY h.created_at DESC
');
$auditStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
$auditStmt->execute();
$history = $auditStmt->fetchAll();

renderHeader('Order #' . $order['order_number'] . ' — Hiroba', ['current_page' => 'orders']);
?>

<div class="container py-4">
  <!-- Header Bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-3 border-bottom">
    <div>
      <span class="eyebrow">Academic Contract Console</span>
      <h1 class="h2 mt-1 mb-0">Order #<?= e($order['order_number']) ?></h1>
      <small class="text-muted">Service: <a href="<?= e(url('service.php?id=' . (int)$order['service_id'])) ?>" class="text-decoration-none text-muted font-monospace"><?= e($order['service_title']) ?></a></small>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="pill pill--<?= e($order['status']) ?>">
        <?= e(ucwords(str_replace('_', ' ', (string)$order['status']))) ?>
      </span>
      <?php if ((int)$order['revision_count'] > 0): ?>
        <span class="badge bg-secondary-subtle text-muted border font-monospace">
          Revision #<?= (int)$order['revision_count'] ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($orderError !== ''): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert" style="background-color: var(--danger-soft); border-color: transparent; color: var(--danger);">
      <?= renderIcon('alert', 'icon flex-shrink-0') ?>
      <div><?= e($orderError) ?></div>
    </div>
  <?php endif; ?>

  <!-- 4-Step Progress Tracker Stepper -->
  <?php if ($order['status'] !== 'cancelled'): ?>
    <?php
      $steps = ['pending' => 1, 'in_progress' => 2, 'submitted' => 3, 'completed' => 4];
      $currentStepNum = $steps[$order['status']] ?? 1;
    ?>
    <div class="stepper mb-5">
      <div class="stepper__step <?= $currentStepNum > 1 ? 'done' : ($currentStepNum === 1 ? 'current' : '') ?>">
        <div class="stepper__dot">1</div>
        <span class="stepper__label">Pending</span>
      </div>
      <div class="stepper__step <?= $currentStepNum > 2 ? 'done' : ($currentStepNum === 2 ? 'current' : '') ?>">
        <div class="stepper__dot">2</div>
        <span class="stepper__label">In Progress</span>
      </div>
      <div class="stepper__step <?= $currentStepNum > 3 ? 'done' : ($currentStepNum === 3 ? 'current' : '') ?>">
        <div class="stepper__dot">3</div>
        <span class="stepper__label">Under Review</span>
      </div>
      <div class="stepper__step <?= $currentStepNum === 4 ? 'done' : '' ?>">
        <div class="stepper__dot">4</div>
        <span class="stepper__label">Completed</span>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-danger mb-4" style="background-color: var(--danger-soft); border: 1px solid var(--danger); border-radius: var(--radius);">
      <strong>Order Cancelled.</strong> This project contract has been terminated.
    </div>
  <?php endif; ?>

  <!-- Golden Ratio 62/38 Split Layout -->
  <div class="split-layout">
    <!-- Left Workspace (61.8%) -->
    <div class="split-primary">
      <!-- Order Brief & Requirements -->
      <section class="p-4 rounded mb-4" style="background-color: var(--surface); border: 1px solid var(--line);">
        <h3 class="h3 mb-2">Project Brief</h3>
        <p class="body-text mb-0" style="white-space: pre-line; line-height: 1.65;">
          <?= e($order['initial_brief']) ?>
        </p>
      </section>

      <!-- Deliverables Section -->
      <section class="p-4 rounded mb-4" style="background-color: var(--surface); border: 1px solid var(--line);">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h3 class="h3 mb-0">Deliverable Work Versions</h3>
          <span class="badge bg-secondary-subtle text-muted font-monospace"><?= count($deliverables) ?> Version(s)</span>
        </div>

        <?php if (empty($deliverables)): ?>
          <p class="text-muted small mb-0">No deliverable links submitted yet. Seller will share work once ready for review.</p>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($deliverables as $deliv): ?>
              <div class="p-3 rounded border" style="background-color: var(--surface-raised); border-color: var(--line) !important;">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <span class="badge bg-primary-subtle text-primary font-monospace">Version <?= (int)$deliv['version_number'] ?></span>
                  <small class="text-muted font-monospace"><?= e(formatDate($deliv['submitted_at'], 'M d, Y h:i A')) ?></small>
                </div>
                <div class="my-2">
                  <a href="<?= e($deliv['deliverable_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-tactile btn-outline btn-sm text-decoration-none">
                    <?= renderIcon('link', 'icon--sm') ?>
                    <span>Open Deliverable URL</span>
                  </a>
                </div>
                <?php if (!empty($deliv['seller_notes'])): ?>
                  <p class="small text-muted mb-0 mt-2">
                    <strong>Notes:</strong> <?= e($deliv['seller_notes']) ?>
                  </p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Order Status Audit Timeline -->
      <section class="p-4 rounded" style="background-color: var(--surface); border: 1px solid var(--line);">
        <h3 class="h3 mb-3">Order History and Audit Trail</h3>
        <div class="timeline">
          <?php foreach ($history as $h): ?>
            <div class="timeline-item">
              <div class="timeline-marker"></div>
              <div class="timeline-content">
                <div class="timeline-header">
                  <strong><?= e($h['actor_name']) ?></strong>
                  <span class="text-muted">&bull;</span>
                  <span class="timeline-time"><?= e(formatDate($h['created_at'], 'M d, Y h:i A')) ?></span>
                </div>
                <div class="text-muted small">
                  Transitioned from <span class="font-monospace"><?= e($h['from_status']) ?></span> to <span class="font-monospace text-dark fw-bold"><?= e($h['to_status']) ?></span>
                </div>
                <?php if (!empty($h['change_reason'])): ?>
                  <div class="timeline-reason rounded">
                    <?= e($h['change_reason']) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <!-- Right Sidebar (38.2% - Action Console) -->
    <div class="split-sidebar">
      <aside class="sticky-sidebar">
        <!-- Order Financial Summary -->
        <div class="card p-4 shadow-sm mb-4" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
          <span class="eyebrow">Transaction Details</span>
          <div class="d-flex align-items-baseline justify-content-between my-2">
            <span class="price-lg"><?= e(formatPrice((float)$order['price_snapshot'])) ?></span>
            <span class="pill pill--<?= e($order['status']) ?>"><?= e(ucwords(str_replace('_', ' ', (string)$order['status']))) ?></span>
          </div>

          <div class="border-top pt-3 mt-2 small">
            <div class="d-flex justify-content-between mb-2">
              <span class="text-muted">Buyer:</span>
              <strong><?= e($order['buyer_name']) ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span class="text-muted">Seller:</span>
              <strong><?= e($order['seller_name']) ?></strong>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-muted">Initiated:</span>
              <span><?= e(formatDate($order['created_at'])) ?></span>
            </div>
          </div>
        </div>

        <!-- Workflow Action Dispatcher -->
        <?php if ($order['status'] !== 'completed' && $order['status'] !== 'cancelled'): ?>
          <div class="card p-4 shadow-sm" style="background-color: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);">
            <h3 class="h3 mb-3" style="font-size: 1.15rem;">Action Console</h3>

            <!-- 1. Seller Actions -->
            <?php if ($isSeller): ?>
              <?php if ($order['status'] === 'pending'): ?>
                <!-- Approve or Reject -->
                <form action="<?= e(url('order.php?id=' . $orderId)) ?>" method="POST" class="mb-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="order_action" value="approve_order">
                  <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center">
                    <?= renderIcon('check', 'icon') ?>
                    <span>Accept & Begin Order</span>
                  </button>
                </form>

                <button type="button" class="btn-tactile btn-outline w-100 justify-content-center text-danger" data-bs-toggle="collapse" data-bs-target="#rejectCollapse">
                  <?= renderIcon('close', 'icon') ?>
                  <span>Decline Order</span>
                </button>

                <div class="collapse mt-3" id="rejectCollapse">
                  <form action="<?= e(url('order.php?id=' . $orderId)) ?>" method="POST" class="p-3 border rounded bg-white">
                    <?= csrfField() ?>
                    <input type="hidden" name="order_action" value="reject_order">
                    <label class="form-label" for="reject-reason">Mandatory Decline Reason</label>
                    <textarea name="reason" id="reject-reason" class="form-control mb-2" rows="2" placeholder="Explain why you cannot accept this order..." required></textarea>
                    <button type="submit" class="btn-tactile btn-accent w-100 btn-sm justify-content-center">Confirm Decline</button>
                  </form>
                </div>

              <?php elseif ($order['status'] === 'in_progress'): ?>
                <!-- Submit Deliverable Work -->
                <form action="<?= e(url('order.php?id=' . $orderId)) ?>" method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="order_action" value="submit_deliverable">
                  <div class="mb-3">
                    <label for="deliverable-url" class="form-label">Cloud Deliverable URL</label>
                    <input type="url" name="deliverable_url" id="deliverable-url" class="form-control" placeholder="https://drive.google.com/..." required>
                  </div>
                  <div class="mb-3">
                    <label for="seller-notes" class="form-label">Submission Notes</label>
                    <textarea name="reason" id="seller-notes" class="form-control" rows="2" placeholder="Briefly describe the deliverables attached..."></textarea>
                  </div>
                  <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center">
                    <?= renderIcon('upload', 'icon') ?>
                    <span>Submit Work for Review</span>
                  </button>
                </form>
              <?php endif; ?>
            <?php endif; ?>

            <!-- 2. Buyer Actions -->
            <?php if ($isBuyer): ?>
              <?php if ($order['status'] === 'pending' || $order['status'] === 'in_progress'): ?>
                <!-- Buyer Cancel -->
                <button type="button" class="btn-tactile btn-outline w-100 justify-content-center text-danger mb-2" data-bs-toggle="collapse" data-bs-target="#buyerCancelCollapse">
                  <?= renderIcon('close', 'icon') ?>
                  <span>Cancel Order</span>
                </button>

                <div class="collapse mt-2" id="buyerCancelCollapse">
                  <form action="<?= e(url('order.php?id=' . $orderId)) ?>" method="POST" class="p-3 border rounded bg-white">
                    <?= csrfField() ?>
                    <input type="hidden" name="order_action" value="cancel_order">
                    <label class="form-label" for="cancel-reason">Cancellation Reason</label>
                    <textarea name="reason" id="cancel-reason" class="form-control mb-2" rows="2" placeholder="Explain why you are cancelling..." required></textarea>
                    <button type="submit" class="btn-tactile btn-accent w-100 btn-sm justify-content-center">Confirm Cancellation</button>
                  </form>
                </div>

              <?php elseif ($order['status'] === 'submitted'): ?>
                <!-- Buyer Accept or Request Revision -->
                <form action="<?= e(url('order.php?id=' . $orderId)) ?>" method="POST" class="mb-2">
                  <?= csrfField() ?>
                  <input type="hidden" name="order_action" value="accept_work">
                  <button type="submit" class="btn-tactile btn-accent w-100 justify-content-center">
                    <?= renderIcon('check', 'icon') ?>
                    <span>Approve & Complete Order</span>
                  </button>
                </form>

                <button type="button" class="btn-tactile btn-outline w-100 justify-content-center" data-bs-toggle="collapse" data-bs-target="#revisionCollapse">
                  <?= renderIcon('alert', 'icon') ?>
                  <span>Request Revisions</span>
                </button>

                <div class="collapse mt-3" id="revisionCollapse">
                  <form action="<?= e(url('order.php?id=' . $orderId)) ?>" method="POST" class="p-3 border rounded bg-white">
                    <?= csrfField() ?>
                    <input type="hidden" name="order_action" value="request_revision">
                    <label class="form-label" for="revision-notes">Specific Revision Instructions</label>
                    <textarea name="reason" id="revision-notes" class="form-control mb-2" rows="3" placeholder="Explain what changes are needed..." required></textarea>
                    <button type="submit" class="btn-tactile btn-outline w-100 btn-sm justify-content-center">Submit Revision Request</button>
                  </form>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</div>

<?php
renderFooter();
