<?php
/**
 * auth.php — session-based admin authentication.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Attempt to log a user in. Returns the user row on success, null on failure.
 */
function attempt_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !is_array($user)) {
        return null;
    }
    if (!password_verify($password, (string) $user['password_hash'])) {
        return null;
    }

    // Rotate session ID after login
    session_regenerate_id(true);

    $_SESSION['user_id']      = (int) $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['login_at']     = time();

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);

    unset($user['password_hash']);
    return $user;
}

/**
 * Get the current logged-in user (associative array) or null.
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'           => (int) $_SESSION['user_id'],
        'username'     => $_SESSION['username']     ?? '',
        'display_name' => $_SESSION['display_name'] ?? '',
        'role'         => $_SESSION['role']         ?? 'viewer',
    ];
}

/**
 * Require a logged-in user, else redirect to /login.php.
 */
function require_login(): void
{
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require admin role specifically.
 */
function require_admin(): void
{
    $u = current_user();
    if (!$u || ($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo '<h1>Forbidden</h1>';
        exit;
    }
}

/**
 * Log the user out.
 */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/**
 * CSRF token generation + verification.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}
