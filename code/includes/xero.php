<?php
/**
 * xero.php — Xero OAuth 2.0 + API client for Hi-Service.
 *
 * Pattern ported from HiveCliq Command Center (Next.js → PHP):
 *   1. Admin clicks "Connect Xero" → we redirect to Xero authorize URL
 *      with state cookie for CSRF
 *   2. Xero redirects back to /api/xero/callback.php with code + state
 *   3. We exchange code for access_token + refresh_token, fetch tenant
 *      list, store everything in oauth_tokens (provider='xero')
 *   4. Every API call auto-refreshes if expires_at < now + 60s
 *
 * Tokens persist in MySQL — single row per provider.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

final class Xero
{
    public const AUTH_BASE       = 'https://login.xero.com/identity/connect/authorize';
    public const TOKEN_URL       = 'https://identity.xero.com/connect/token';
    public const CONNECTIONS_URL = 'https://api.xero.com/connections';
    public const API_BASE        = 'https://api.xero.com/api.xro/2.0';

    /**
     * Minimal working scope set for invoice creation.
     *
     * Two hard-won lessons from Command Center, both must be applied together:
     *
     *   1. DROP openid/profile/email — they require the Xero app to be set up
     *      as an OpenID Connect provider (separate config, not the default for
     *      a basic Web App). Xero returns "invalid_scope 500" if requested
     *      without that config. We don't need user identity, just data access.
     *      (Command Center commit 868976e)
     *
     *   2. USE GRANULAR scopes for apps created after 2 March 2026. The old
     *      broad accounting.transactions / accounting.reports.read are
     *      rejected with "invalid_scope 500".
     *      (Command Center commit ed1e30e)
     *
     * So: only granular accounting scopes + offline_access. Nothing else.
     */
    public const SCOPES = [
        'offline_access',                    // refresh tokens (REQUIRED — otherwise re-auth every 30 min)
        'accounting.invoices',               // create + read invoices (granular WRITE)
        'accounting.contacts',               // create + read customers (granular WRITE)
    ];

    // ============= OAuth flow =============

    public static function isConfigured(): bool
    {
        return env('XERO_CLIENT_ID') && env('XERO_CLIENT_SECRET');
    }

    public static function isConnected(): bool
    {
        $row = self::tokenRow();
        return $row && !empty($row['access_token']) && !empty($row['refresh_token']);
    }

    public static function redirectUri(): string
    {
        return rtrim((string) env('APP_URL', 'https://hiservice.store'), '/') . '/api/xero/callback.php';
    }

    /** Returns the authorize URL the admin should be redirected to. */
    public static function authorizeUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id'     => (string) env('XERO_CLIENT_ID'),
            'redirect_uri'  => self::redirectUri(),
            'scope'         => implode(' ', self::SCOPES),
            'state'         => $state,
        ];
        // Manually encode so spaces in scope become %20 (not +). Xero is strict.
        $qs = [];
        foreach ($params as $k => $v) $qs[] = $k . '=' . rawurlencode($v);
        return self::AUTH_BASE . '?' . implode('&', $qs);
    }

    /**
     * Exchange an authorization code for tokens + tenant info.
     * Persists into oauth_tokens. Returns the tenant name.
     */
    public static function handleCallback(string $code): string
    {
        $resp = self::http('POST', self::TOKEN_URL, [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri(),
        ], false, true);  // formEncoded=true, basicAuth=true

        if (empty($resp['access_token'])) {
            throw new RuntimeException('Xero token exchange returned no access_token: ' . json_encode($resp));
        }
        $accessToken  = (string) $resp['access_token'];
        $refreshToken = (string) ($resp['refresh_token'] ?? '');
        $expiresIn    = (int)    ($resp['expires_in']    ?? 1800);
        $scope        = (string) ($resp['scope']         ?? '');

        // Fetch tenant list
        $tenants = self::fetchTenants($accessToken);
        if (empty($tenants)) {
            throw new RuntimeException('Xero authorised but no tenant returned. Open Xero, create an org, retry.');
        }
        $tenant = $tenants[0];

        // Persist
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $meta = [
            'tenant_id'   => $tenant['tenantId'],
            'tenant_name' => $tenant['tenantName'],
            'tenant_type' => $tenant['tenantType'] ?? null,
            'scopes'      => explode(' ', $scope),
            'connected_at'=> date('c'),
        ];

        db()->prepare(
            "INSERT INTO oauth_tokens (provider, access_token, refresh_token, expires_at, meta)
             VALUES ('xero', :a, :r, :e, :m)
             ON DUPLICATE KEY UPDATE
               access_token  = VALUES(access_token),
               refresh_token = VALUES(refresh_token),
               expires_at    = VALUES(expires_at),
               meta          = VALUES(meta)"
        )->execute([
            ':a' => $accessToken,
            ':r' => $refreshToken,
            ':e' => $expiresAt,
            ':m' => json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);

        log_to_file('xero', 'connected', ['tenant' => $tenant['tenantName']]);
        return $tenant['tenantName'];
    }

    /** Drop the stored tokens. */
    public static function disconnect(): void
    {
        db()->prepare("DELETE FROM oauth_tokens WHERE provider = 'xero'")->execute();
        log_to_file('xero', 'disconnected');
    }

    /** Return the connection summary for the admin UI. */
    public static function connectionInfo(): ?array
    {
        $row = self::tokenRow();
        if (!$row) return null;
        $meta = $row['meta'] ? json_decode($row['meta'], true) : [];
        return [
            'tenant_id'    => $meta['tenant_id']    ?? null,
            'tenant_name'  => $meta['tenant_name']  ?? '(unknown)',
            'connected_at' => $meta['connected_at'] ?? null,
            'scopes'       => $meta['scopes']       ?? [],
            'expires_at'   => $row['expires_at'],
        ];
    }

    // ============= API client =============

    /** Get a valid access token. Refreshes if <60s remain. */
    public static function getAccessToken(): string
    {
        $row = self::tokenRow();
        if (!$row) throw new RuntimeException('Xero is not connected. Visit /admin/connections.php');

        if (strtotime($row['expires_at']) - time() > 60) {
            return (string) $row['access_token'];
        }

        // Refresh
        $resp = self::http('POST', self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string) $row['refresh_token'],
        ], false, true);
        if (empty($resp['access_token'])) {
            throw new RuntimeException('Xero refresh failed: ' . json_encode($resp));
        }
        $accessToken  = (string) $resp['access_token'];
        $refreshToken = (string) ($resp['refresh_token'] ?? $row['refresh_token']);
        $expiresAt    = date('Y-m-d H:i:s', time() + (int) ($resp['expires_in'] ?? 1800));

        db()->prepare(
            "UPDATE oauth_tokens
                SET access_token = :a, refresh_token = :r, expires_at = :e
              WHERE provider = 'xero'"
        )->execute([':a' => $accessToken, ':r' => $refreshToken, ':e' => $expiresAt]);

        return $accessToken;
    }

    public static function getTenantId(): string
    {
        $info = self::connectionInfo();
        if (!$info || empty($info['tenant_id'])) {
            throw new RuntimeException('Xero tenant ID missing. Reconnect Xero.');
        }
        return $info['tenant_id'];
    }

    /** Authenticated GET on the Xero accounting API. Path begins with /. */
    public static function get(string $path): array
    {
        $token  = self::getAccessToken();
        $tenant = self::getTenantId();
        return self::http('GET', self::API_BASE . $path, null, [
            'Authorization: Bearer ' . $token,
            'Xero-tenant-id: ' . $tenant,
            'Accept: application/json',
        ]);
    }

    /** Authenticated POST/PUT with JSON body. */
    public static function post(string $path, array $body, string $method = 'POST'): array
    {
        $token  = self::getAccessToken();
        $tenant = self::getTenantId();
        return self::http($method, self::API_BASE . $path, $body, [
            'Authorization: Bearer ' . $token,
            'Xero-tenant-id: ' . $tenant,
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
    }

    // ============= Internals =============

    private static function tokenRow(): ?array
    {
        $stmt = db()->prepare("SELECT * FROM oauth_tokens WHERE provider = 'xero'");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fetchTenants(string $accessToken): array
    {
        $resp = self::http('GET', self::CONNECTIONS_URL, null, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
        return is_array($resp) ? $resp : [];
    }

    /**
     * Generic cURL wrapper.
     *
     * @param mixed $bodyOrHeaders  When $formEncoded=true OR $basicAuth=true, this is
     *                              the form-encoded POST body. Otherwise treated as
     *                              JSON body for POST/PUT, or as headers (array of strings)
     *                              if it's a list of "Header: Value" strings.
     */
    private static function http(string $method, string $url, $bodyOrHeaders = null, $headersOrFormBody = false, bool $basicAuth = false): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 25,
        ];

        $headers = [];

        // Calling pattern A: http('POST', $url, $formData, false, true) — token exchange / refresh
        if ($basicAuth) {
            $headers[] = 'Authorization: Basic ' .
                base64_encode(env('XERO_CLIENT_ID') . ':' . env('XERO_CLIENT_SECRET'));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Accept: application/json';
            $opts[CURLOPT_POSTFIELDS] = http_build_query((array) $bodyOrHeaders);
        }
        // Calling pattern B: http('GET'/'POST', $url, $body, ['Header: ...'], false) — authenticated API
        elseif (is_array($headersOrFormBody)) {
            $headers = $headersOrFormBody;
            if ($bodyOrHeaders !== null) {
                $opts[CURLOPT_POSTFIELDS] = is_array($bodyOrHeaders) ? json_encode($bodyOrHeaders) : (string) $bodyOrHeaders;
            }
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("Xero curl error: {$err}");
        }
        $data = json_decode((string) $resp, true);
        if ($code >= 400) {
            log_to_file('xero', "$method $url → $code", ['body' => substr((string) $resp, 0, 600)]);
            $msg = is_array($data) ? json_encode($data) : substr((string) $resp, 0, 300);
            throw new RuntimeException("Xero HTTP {$code}: {$msg}");
        }
        return is_array($data) ? $data : [];
    }
}
