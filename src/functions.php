<?php
declare(strict_types=1);

/**
 * Hiroba Core Utility Functions
 * Input sanitization, CSRF token generation/verification, flash messaging,
 * SVG icon rendering, and URL helpers.
 */

require_once __DIR__ . '/config.php';

/**
 * Escape dynamic content for safe HTML output.
 */
function e(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Ensure active PHP session is initialized if headers not sent.
 */
function ensureSessionStarted(): void {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
}

/**
 * Generate a cryptographically secure CSRF token and store it in session.
 */
function generateCsrfToken(): string {
    ensureSessionStarted();

    if (
        !empty($_SESSION['csrf_token']) &&
        !empty($_SESSION['csrf_token_time']) &&
        (time() - (int)$_SESSION['csrf_token_time']) <= CSRF_EXPIRY
    ) {
        return (string)$_SESSION['csrf_token'];
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();

    return $token;
}

/**
 * Verify submitted CSRF token against active session token.
 */
function verifyCsrfToken(?string $token, bool $singleUse = true): bool {
    ensureSessionStarted();

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    $sessionToken = (string)$_SESSION['csrf_token'];
    $tokenTime = (int)($_SESSION['csrf_token_time'] ?? 0);

    // Check expiration
    if ((time() - $tokenTime) > CSRF_EXPIRY) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }

    $isValid = hash_equals($sessionToken, $token);

    if ($singleUse) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }

    return $isValid;
}

/**
 * Generate a hidden CSRF token form input field.
 */
function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Set a transient flash message for the subsequent page request.
 */
function setFlash(string $type, string $message): void {
    ensureSessionStarted();

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and clear the active flash message from session.
 *
 * @return array{type: string, message: string}|null
 */
function getFlash(): ?array {
    ensureSessionStarted();

    if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return [
            'type' => (string)($flash['type'] ?? 'info'),
            'message' => (string)($flash['message'] ?? '')
        ];
    }

    return null;
}

/**
 * Check whether a flash message is currently queued.
 */
function hasFlash(): bool {
    ensureSessionStarted();

    return isset($_SESSION['flash']) && is_array($_SESSION['flash']);
}

/**
 * Build an absolute application URL for a relative route.
 */
function url(string $path = ''): string {
    $cleanPath = ltrim($path, '/');
    if ($cleanPath === '') {
        return APP_URL;
    }
    return APP_URL . '/' . $cleanPath;
}

/**
 * Redirect client to an application route and terminate execution.
 */
function redirect(string $path): void {
    $targetUrl = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
        ? $path
        : url($path);

    header('Location: ' . $targetUrl, true, 303);
    exit;
}

/**
 * Render a vector SVG icon from the centralized sprite sheet.
 */
function renderIcon(string $name, string $customClass = ''): string {
    $cleanName = str_starts_with($name, 'icon-') ? substr($name, 5) : $name;
    $safeName = e($cleanName);
    $safeClass = e($customClass);
    $classAttr = 'icon' . ($safeClass !== '' ? ' ' . $safeClass : '');
    $spriteHref = url('assets/icons/sprite.svg#icon-' . $safeName);

    return '<svg class="' . $classAttr . '" aria-hidden="true"><use href="' . $spriteHref . '"></use></svg>';
}

/**
 * Format a numeric amount as Philippine Peso currency.
 */
function formatPrice(float|int|string $amount): string {
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Format a MySQL timestamp into a clean presentation string.
 */
function formatDate(string $datetime, string $format = 'M j, Y'): string {
    $time = strtotime($datetime);
    if ($time === false) {
        return $datetime;
    }
    return date($format, $time);
}

/**
 * Format timestamp into human-readable relative duration.
 */
function formatRelativeTime(string $datetime): string {
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = max(1, (int)floor($diff / 60));
        return $minutes === 1 ? '1 minute ago' : "{$minutes} minutes ago";
    } elseif ($diff < 86400) {
        $hours = max(1, (int)floor($diff / 3600));
        return $hours === 1 ? '1 hour ago' : "{$hours} hours ago";
    } elseif ($diff < 604800) {
        $days = max(1, (int)floor($diff / 86400));
        return $days === 1 ? 'Yesterday' : "{$days} days ago";
    }

    return date('M j, Y', $timestamp);
}

/**
 * Transform text string into lowercase URL-safe slug.
 */
function slugify(string $text): string {
    $clean = preg_replace('~[^\pL\d]+~u', '-', $text);
    $clean = iconv('utf-8', 'us-ascii//TRANSLIT', (string)$clean);
    $clean = preg_replace('~[^-\w]+~', '', (string)$clean);
    $clean = trim((string)$clean, '-');
    $clean = preg_replace('~-+~', '-', (string)$clean);
    $clean = strtolower((string)$clean);

    return $clean !== '' ? $clean : 'service-' . bin2hex(random_bytes(4));
}

/**
 * Truncate long paragraph strings gracefully at word boundaries.
 */
function truncateText(string $text, int $limit = 120, string $append = '...'): string {
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    $truncated = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($truncated, ' ');

    if ($lastSpace !== false) {
        $truncated = mb_substr($truncated, 0, $lastSpace);
    }

    return $truncated . $append;
}

/**
 * Retrieve sanitized client IP address.
 */
function getClientIp(): string {
    $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ipList = explode(',', (string)$rawIp);
    $cleanIp = trim($ipList[0]);

    return filter_var($cleanIp, FILTER_VALIDATE_IP) ? $cleanIp : '127.0.0.1';
}

/**
 * Retrieve sanitized browser user agent.
 */
function getUserAgent(): string {
    $rawAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent');
    return mb_substr(trim($rawAgent), 0, 250);
}

/**
 * Generate a unique URL-safe category slug (max 50 chars).
 */
function generateUniqueCategorySlug(PDO $db, string $name, ?int $excludeId = null): string {
    $baseSlug = substr(slugify($name), 0, 50);
    if ($baseSlug === '') {
        $baseSlug = 'cat-' . bin2hex(random_bytes(4));
    }

    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        if ($excludeId !== null) {
            $chk = $db->prepare('SELECT id FROM categories WHERE slug = :slug AND id != :exclude_id LIMIT 1');
            $chk->execute([':slug' => $slug, ':exclude_id' => $excludeId]);
        } else {
            $chk = $db->prepare('SELECT id FROM categories WHERE slug = :slug LIMIT 1');
            $chk->execute([':slug' => $slug]);
        }

        if (!$chk->fetch()) {
            return $slug;
        }

        $suffix = '-' . $counter;
        $slug = substr($baseSlug, 0, 50 - strlen($suffix)) . $suffix;
        $counter++;
    }
}

/**
 * Find an existing category by name (case-insensitive, reactivating if hidden)
 * or create a new active category with a unique slug.
 *
 * @return array{id: int, name: string, created: bool, reactivated: bool}
 */
function findOrCreateCategory(PDO $db, string $name, ?string $description, int $actorId): array {
    $cleanName = trim($name);
    $cleanDesc = $description !== null ? trim($description) : null;
    if ($cleanDesc === '') {
        $cleanDesc = null;
    }

    $existing = $db->prepare('SELECT id, name, status FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $existing->execute([':name' => $cleanName]);
    $row = $existing->fetch();

    if ($row) {
        $catId = (int)$row['id'];
        $reactivated = false;
        if (($row['status'] ?? 'active') === 'hidden') {
            $upd = $db->prepare('UPDATE categories SET status = :status WHERE id = :id');
            $upd->execute([':status' => 'active', ':id' => $catId]);
            $reactivated = true;

            $audit = $db->prepare('
                INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
                VALUES (:entity_type, :entity_id, :actor_id, :action, :details, :ip)
            ');
            $audit->execute([
                ':entity_type' => 'categories',
                ':entity_id' => $catId,
                ':actor_id' => $actorId,
                ':action' => 'category_unhidden',
                ':details' => json_encode(['name' => (string)$row['name']]),
                ':ip' => getClientIp(),
            ]);
        }

        return [
            'id' => $catId,
            'name' => (string)$row['name'],
            'created' => false,
            'reactivated' => $reactivated,
        ];
    }

    $slug = generateUniqueCategorySlug($db, $cleanName);

    $ins = $db->prepare('
        INSERT INTO categories (name, slug, description, status, created_at)
        VALUES (:name, :slug, :description, :status, NOW())
    ');
    $ins->execute([
        ':name' => $cleanName,
        ':slug' => $slug,
        ':description' => $cleanDesc,
        ':status' => 'active',
    ]);
    $newId = (int)$db->lastInsertId();

    $audit = $db->prepare('
        INSERT INTO audit_logs (entity_type, entity_id, actor_id, action, details, ip_address)
        VALUES (:entity_type, :entity_id, :actor_id, :action, :details, :ip)
    ');
    $audit->execute([
        ':entity_type' => 'categories',
        ':entity_id' => $newId,
        ':actor_id' => $actorId,
        ':action' => 'category_created',
        ':details' => json_encode(['name' => $cleanName, 'slug' => $slug]),
        ':ip' => getClientIp(),
    ]);

    return [
        'id' => $newId,
        'name' => $cleanName,
        'created' => true,
        'reactivated' => false,
    ];
}
