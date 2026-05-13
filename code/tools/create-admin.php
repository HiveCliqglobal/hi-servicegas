<?php
/**
 * tools/create-admin.php — CLI tool to create or reset an admin user.
 *
 * Usage:
 *   php tools/create-admin.php <username> <password> ["Display Name"]
 *
 * Example:
 *   php tools/create-admin.php shawn S0meStr0ngPass "Shawn Lochner"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;
$display  = $argv[3] ?? null;

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php tools/create-admin.php <username> <password> [\"Display Name\"]\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$exists = db()->prepare('SELECT id FROM users WHERE username = :u');
$exists->execute([':u' => $username]);
$row = $exists->fetch();

if ($row) {
    db()->prepare('UPDATE users SET password_hash = :h, display_name = COALESCE(:d, display_name), role = "admin" WHERE id = :id')
        ->execute([':h' => $hash, ':d' => $display, ':id' => $row['id']]);
    echo "Updated existing user '{$username}' (id={$row['id']}).\n";
} else {
    db()->prepare('INSERT INTO users (username, password_hash, display_name, role) VALUES (:u, :h, :d, "admin")')
        ->execute([':u' => $username, ':h' => $hash, ':d' => $display ?? $username]);
    echo "Created admin user '{$username}'.\n";
}
