<?php
/**
 * driver_auth.php — separate session for the driver PWA.
 *
 * Drivers and admins share the same MySQL but not the same PHP session.
 * Driver sessions live in their own cookie (hs_driver) so an admin clicking
 * a /driver link doesn't auto-impersonate, and vice versa.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Use a separate cookie name so it doesn't clobber the admin session.
if (session_status() === PHP_SESSION_NONE) {
    session_name('hs_driver');
    session_set_cookie_params([
        'lifetime' => 30 * 86400,  // 30 days "remember me"
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function driver_attempt_login(int $driverId, string $pin): ?array
{
    $stmt = db()->prepare('SELECT * FROM drivers WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $driverId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($pin, (string) $row['pin_hash'])) {
        return null;
    }
    session_regenerate_id(true);
    $_SESSION['driver_id'] = (int) $row['id'];
    $_SESSION['driver_name'] = $row['name'];
    $_SESSION['driver_phone'] = $row['phone'];
    $_SESSION['driver_avatar'] = $row['avatar_color'];
    $_SESSION['driver_login_at'] = time();

    db()->prepare('UPDATE drivers SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);
    return $row;
}

function current_driver(): ?array
{
    if (empty($_SESSION['driver_id'])) return null;
    return [
        'id'     => (int) $_SESSION['driver_id'],
        'name'   => $_SESSION['driver_name']   ?? '',
        'phone'  => $_SESSION['driver_phone']  ?? '',
        'avatar' => $_SESSION['driver_avatar'] ?? '#d62828',
    ];
}

function driver_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0] ?? '';
    $last  = end($parts) ?: '';
    return strtoupper(mb_substr($first, 0, 1) . ($last !== $first ? mb_substr($last, 0, 1) : ''));
}

function driver_require_login(): void
{
    if (!current_driver()) {
        header('Location: /driver/login.php');
        exit;
    }
}

function driver_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.cookie_params')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'] ?? '', (bool)($p['secure'] ?? true), (bool)($p['httponly'] ?? true));
    }
    session_destroy();
}

function driver_csrf_token(): string
{
    if (empty($_SESSION['driver_csrf'])) {
        $_SESSION['driver_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['driver_csrf'];
}

function driver_csrf_verify(?string $token): bool
{
    return !empty($_SESSION['driver_csrf']) && is_string($token) && hash_equals($_SESSION['driver_csrf'], $token);
}
