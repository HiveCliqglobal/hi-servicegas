<?php
/**
 * helpers.php — small utility functions.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

/**
 * HTML-escape for safe output.
 */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format money as "R 1 234.50".
 */
function money($v): string
{
    return 'R ' . number_format((float) $v, 2, '.', ' ');
}

/**
 * Generate a unique order reference (ORD-<unix>-<rand>).
 */
function gen_order_ref(): string
{
    return 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Normalize a phone number to E.164-ish (no leading +, country code prefixed).
 * Defaults SA (27) if input begins with 0.
 */
function normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') return '';
    if (str_starts_with($digits, '0')) {
        $digits = '27' . substr($digits, 1);
    }
    return $digits;
}

/**
 * Sleep-safe redirect.
 */
function redirect(string $to, int $code = 302): void
{
    header("Location: {$to}", true, $code);
    exit;
}

/**
 * Return JSON response and exit.
 */
function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
