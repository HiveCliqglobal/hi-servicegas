<?php
/**
 * env_writer.php — read & update keys in the production .env file.
 *
 * Used by /admin/connections.php to update integration credentials
 * (PayFast, Meta, Xero client_id, etc) without an SSH round-trip.
 *
 * The .env file lives ONE level above docroot:
 *   /home/hiserviceshopz/.env  (chmod 600)
 *
 * Preserves comments + ordering. Updates existing keys in-place,
 * appends new ones to the end.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function env_file_path(): string
{
    // Same logic as config.php — look one level above docroot first.
    $candidates = [
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__) . '/.env',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) return $p;
    }
    return $candidates[0];   // best-guess for write
}

/**
 * Replace (or append) one or more keys in the .env file.
 *
 * @param array<string,string> $kv  Key → value map. Values are written as-is —
 *                                  the caller is responsible for escaping.
 * @return bool  true on success
 */
function env_set(array $kv): bool
{
    $path = env_file_path();
    $contents = is_readable($path) ? (string) file_get_contents($path) : '';
    $lines = $contents === '' ? [] : preg_split("/\r?\n/", $contents);
    if ($lines === false) $lines = [];

    foreach ($kv as $key => $value) {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) continue;

        $newLine = $key . '=' . _env_escape((string) $value);
        $found = false;
        foreach ($lines as $i => $line) {
            // Match  KEY=anything  (with optional whitespace at start)
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
                $lines[$i] = $newLine;
                $found = true;
                break;
            }
        }
        if (!$found) $lines[] = $newLine;
    }

    $final = implode("\n", $lines);
    if (substr($final, -1) !== "\n") $final .= "\n";

    $ok = @file_put_contents($path, $final, LOCK_EX) !== false;
    if ($ok) @chmod($path, 0600);
    return $ok;
}

/** Lightweight escape for .env values — quote if it has spaces, hashes, or quotes. */
function _env_escape(string $v): string
{
    if ($v === '') return '';
    if (preg_match('/[\s#"\']/', $v)) {
        // Strip embedded double quotes, wrap in quotes
        return '"' . str_replace('"', '\"', $v) . '"';
    }
    return $v;
}

/** Mask a credential for display: keep first 4 + last 2 visible, dots in between. */
function env_mask(string $v): string
{
    $v = (string) $v;
    if ($v === '') return '';
    if (strlen($v) <= 8) return str_repeat('•', strlen($v));
    return substr($v, 0, 4) . str_repeat('•', max(4, strlen($v) - 6)) . substr($v, -2);
}
