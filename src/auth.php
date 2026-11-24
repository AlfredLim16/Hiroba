<?php
declare(strict_types=1);

/**
 * Hiroba Authentication and Session Security Module
 * Manages user authentication, registration, rate limiting, and role-based guards.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Initialize hardened session environment and enforce inactivity timeout.
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (!headers_sent()) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');

        if (COOKIE_SECURE) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
    }

    // Check inactivity timeout (30 minutes)
    if (isset($_SESSION['user_id'])) {
        $lastActivity = (int)($_SESSION['last_activity'] ?? time());
        if ((time() - $lastActivity) > SESSION_LIFETIME) {
            logoutUser();
            setFlash('warning', 'Your session expired due to 30 minutes of inactivity. Please sign in again.');
            redirect('login.php');
        }
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Check whether a user is currently authenticated.
 */
function isLoggedIn(): bool {
    initSession();
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

/**
 * Retrieve active user ID.
 */
function getCurrentUserId(): ?int {
    initSession();
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Fetch full profile of currently authenticated user.
 *
 * @return array<string, mixed>|null
 */
function getCurrentUser(): ?array {
    $userId = getCurrentUserId();
    if ($userId === null) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null && (int)$cachedUser['id'] === $userId) {
        return $cachedUser;
    }

    $stmt = dbQuery(
        'SELECT id, name, email, is_admin, is_active, created_at FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        [':id' => $userId]
    );

    $user = $stmt->fetch();
    if (!$user) {
        logoutUser();
        return null;
    }

    $cachedUser = $user;
    return $user;
}

/**
 * Convenience alias for getCurrentUser.
 *
 * @return array<string, mixed>|null
 */
function currentUser(): ?array {
    return getCurrentUser();
}

/**
 * Check whether authenticated user possesses administrator privileges.
 */
function isAdmin(): bool {
    $user = getCurrentUser();
    return $user !== null && (int)$user['is_admin'] === 1;
}

/**
 * Check whether authenticated user possesses an approved seller profile.
 */
function isVerifiedSeller(): bool {
    $profile = getSellerProfile();
    return $profile !== null && (string)$profile['status'] === 'approved';
}

/**
 * Fetch seller profile record for given user ID or active session.
 *
 * @param int|null $userId
 * @return array<string, mixed>|null
 */
function getSellerProfile(?int $userId = null): ?array {
    $targetId = $userId ?? getCurrentUserId();
    if ($targetId === null) {
        return null;
    }

    static $profileCache = [];
    if (isset($profileCache[$targetId])) {
        return $profileCache[$targetId];
    }

    $stmt = dbQuery(
        'SELECT id, user_id, student_id, id_card_path, bio, photo_path, portfolio_url, contact_handle, status, admin_feedback, verified_at, created_at ' .
        'FROM seller_profiles WHERE user_id = :user_id LIMIT 1',
        [':user_id' => $targetId]
    );

    $profile = $stmt->fetch();
    $profileCache[$targetId] = $profile ?: null;

    return $profileCache[$targetId];
}

/**
 * Guard route: Require authenticated user session.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('warning', 'Please sign in to access that section.');
        redirect('login.php');
    }
}

/**
 * Guard route: Require unauthenticated guest state.
 */
function requireGuest(): void {
    if (isLoggedIn()) {
        redirect('dashboard.php');
    }
}

/**
 * Guard route: Require administrator privileges.
 */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        setFlash('danger', 'Access denied. Administrative privileges are required.');
        redirect('index.php');
    }
}

/**
 * Guard route: Require verified seller status.
 */
function requireSeller(): void {
    requireLogin();
    if (!isVerifiedSeller()) {
        setFlash('warning', 'You must be a verified student seller to access that feature. Please submit your application in Settings.');
        redirect('settings.php');
    }
}

/**
 * Attempt student user authentication with brute-force rate-limiting checks.
 *
 * @param string $email
 * @param string $password
 * @param bool $rememberEmail
 * @return array{success: bool, message: string}
 */
function attemptLogin(string $email, string $password, bool $rememberEmail = false): array {
    initSession();

    $cleanEmail = mb_strtolower(trim($email));
    $ipAddress = getClientIp();
    $userAgent = getUserAgent();

    if ($cleanEmail === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'Please provide both your campus email and password.'
        ];
    }

    // Check account status and lockout state
    $stmt = dbQuery(
        'SELECT id, name, email, password_hash, is_admin, is_active, failed_attempts, locked_until, ' .
        'TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS seconds_remaining ' .
        'FROM users WHERE email = :email LIMIT 1',
        [':email' => $cleanEmail]
    );

    $user = $stmt->fetch();

    if (!$user) {
        // Record failed attempt for non-existent account
        dbQuery(
            'CALL sp_record_login_attempt(:email, :ip_address, :user_agent, :status, :failure_reason)',
            [
                ':email' => $cleanEmail,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':status' => 'failed',
                ':failure_reason' => 'user_not_found'
            ]
        );

        return [
            'success' => false,
            'message' => 'Invalid email address or password.'
        ];
    }

    // Check brute-force lockout
    $secondsRemaining = (int)($user['seconds_remaining'] ?? 0);
    if ($secondsRemaining > 0) {
        $minutesLeft = (int)ceil($secondsRemaining / 60);

        dbQuery(
            'CALL sp_record_login_attempt(:email, :ip_address, :user_agent, :status, :failure_reason)',
            [
                ':email' => $cleanEmail,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':status' => 'locked',
                ':failure_reason' => 'account_locked'
            ]
        );

        return [
            'success' => false,
            'message' => "This account is temporarily locked due to multiple failed sign-in attempts. Please try again in {$minutesLeft} minutes."
        ];
    }

    // Verify account active status
    if ((int)$user['is_active'] !== 1) {
        return [
            'success' => false,
            'message' => 'Your student account is currently deactivated. Please contact the administrator desk.'
        ];
    }

    // Verify password hash
    if (!password_verify($password, (string)$user['password_hash'])) {
        dbQuery(
            'CALL sp_record_login_attempt(:email, :ip_address, :user_agent, :status, :failure_reason)',
            [
                ':email' => $cleanEmail,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':status' => 'failed',
                ':failure_reason' => 'invalid_password'
            ]
        );

        $currentFailed = (int)$user['failed_attempts'] + 1;
        $remainingTries = max(0, 5 - $currentFailed);

        if ($remainingTries === 0) {
            $msg = 'Maximum failed attempts reached. Your account is now locked for 15 minutes.';
        } else {
            $msg = "Invalid email address or password. You have {$remainingTries} attempt(s) remaining before a 15-minute lockout.";
        }

        return [
            'success' => false,
            'message' => $msg
        ];
    }

    // Record successful login via stored procedure (resets failed_attempts and clears locked_until)
    dbQuery(
        'CALL sp_record_login_attempt(:email, :ip_address, :user_agent, :status, :failure_reason)',
        [
            ':email' => $cleanEmail,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':status' => 'success',
            ':failure_reason' => null
        ]
    );

    // Regenerate session ID to mitigate session fixation attacks
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = (string)$user['name'];
    $_SESSION['user_email'] = (string)$user['email'];
    $_SESSION['is_admin'] = (int)$user['is_admin'] === 1;
    $_SESSION['last_activity'] = time();

    // Manage 'Remember Email' cookie
    if ($rememberEmail) {
        setcookie('hiroba_saved_email', $cleanEmail, [
            'expires' => time() + (14 * 86400),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => COOKIE_SECURE
        ]);
    } else {
        setcookie('hiroba_saved_email', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => COOKIE_SECURE
        ]);
    }

    return [
        'success' => true,
        'message' => 'Sign-in successful. Welcome back!'
    ];
}

/**
 * Register a new student account.
 *
 * @param string $name
 * @param string $email
 * @param string $password
 * @return array{success: bool, message: string, user_id?: int}
 */
function registerStudent(string $name, string $email, string $password): array {
    $cleanName = trim($name);
    $cleanEmail = mb_strtolower(trim($email));

    if (mb_strlen($cleanName) < 2 || mb_strlen($cleanName) > 100) {
        return [
            'success' => false,
            'message' => 'Full name must be between 2 and 100 characters in length.'
        ];
    }

    if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Please provide a valid email address.'
        ];
    }

    if (mb_strlen($password) < 8) {
        return [
            'success' => false,
            'message' => 'Password must contain at least 8 characters.'
        ];
    }

    // Verify email uniqueness
    $stmt = dbQuery('SELECT id FROM users WHERE email = :email LIMIT 1', [':email' => $cleanEmail]);
    if ($stmt->fetch()) {
        return [
            'success' => false,
            'message' => 'An account with that email address is already registered.'
        ];
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    dbQuery(
        'INSERT INTO users (name, email, password_hash, is_admin, is_active, failed_attempts, locked_until) ' .
        'VALUES (:name, :email, :password_hash, 0, 1, 0, NULL)',
        [
            ':name' => $cleanName,
            ':email' => $cleanEmail,
            ':password_hash' => $passwordHash
        ]
    );

    $pdo = getDbConnection();
    $newId = (int)$pdo->lastInsertId();

    return [
        'success' => true,
        'message' => 'Account registered successfully. You may now sign in.',
        'user_id' => $newId
    ];
}

/**
 * Destroy active user session and purge auth cookies.
 */
function logoutUser(): void {
    initSession();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax'
        ]);
    }

    session_destroy();
}
