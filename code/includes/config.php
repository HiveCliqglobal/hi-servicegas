<?php
/**
 * config.php
 *
 * Loads .env file (one level above docroot) into $_ENV / getenv().
 * Provides env() helper.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

if (!defined('HS_ENV_LOADED')) {
    define('HS_ENV_LOADED', true);

    $envCandidates = [
        dirname(__DIR__, 2) . '/.env',        // one level above public_html
        dirname(__DIR__) . '/.env',           // same dir as docroot (less safe)
    ];

    foreach ($envCandidates as $envPath) {
        if (is_readable($envPath)) {
            $vars = parse_ini_file($envPath, false, INI_SCANNER_RAW);
            if (is_array($vars)) {
                foreach ($vars as $k => $v) {
                    $_ENV[$k] = $v;
                    putenv("$k=$v");
                }
            }
            break;
        }
    }
}

/**
 * Retrieve an environment variable.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function env(string $key, $default = null)
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    if (is_string($v)) {
        $lower = strtolower(trim($v));
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;
        if ($lower === 'null')  return null;
    }
    return $v;
}

// PHP runtime config
date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
ini_set('display_errors', env('APP_DEBUG', '0') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php-error.log');

// Session config
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   env('APP_ENV', 'production') === 'production' ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime',  (string) env('SESSION_LIFETIME', 7200));
session_name((string) env('SESSION_NAME', 'hs_session'));
