<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';

// Authentication required
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('dashboard.php?tab=listings');
}

$submittedToken = (string)($_POST['csrf_token'] ?? '');
if (!verifyCsrfToken($submittedToken)) {
  setFlash('danger', 'Security validation failed (CSRF).');
  redirect('dashboard.php?tab=listings');
}

$serviceId = max(0, (int)($_POST['service_id'] ?? 0));
if ($serviceId === 0) {
  setFlash('danger', 'Invalid service listing.');
  redirect('dashboard.php?tab=listings');
}

$pdo = getDbConnection();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];

$stmt = $pdo->prepare('SELECT id, seller_id, status, title FROM services WHERE id = :id');
$stmt->execute([':id' => $serviceId]);
$service = $stmt->fetch();

if (!$service || ((int)$service['seller_id'] !== $userId && !isAdmin())) {
  setFlash('danger', 'You do not have permission to modify this service.');
  redirect('dashboard.php?tab=listings');
}

$newStatus = ($service['status'] === 'active') ? 'hidden' : 'active';

$upd = $pdo->prepare('UPDATE services SET status = :status, updated_at = NOW() WHERE id = :id');
$upd->execute([':status' => $newStatus, ':id' => $serviceId]);

$actionWord = ($newStatus === 'active') ? 'activated' : 'hidden';
setFlash('info', 'Service listing "' . $service['title'] . '" has been ' . $actionWord . '.');
redirect('dashboard.php?tab=listings');
