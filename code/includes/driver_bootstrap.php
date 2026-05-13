<?php
/**
 * driver_bootstrap.php — loaded by every /driver/ page.
 *
 * Critical: does NOT include the admin auth.php (which would start a session
 * under the 'hs_session' cookie name, breaking the driver's 'hs_driver' session).
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/driver_auth.php';
