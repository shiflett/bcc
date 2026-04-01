<?php
/**
 * Admin login — /admin/login
 * The actual logic lives in admin_auth.php.
 * This file exists so the router can point /admin/login here explicitly.
 */

require_once __DIR__ . '/../../includes/auth.php';

// If we reach here the user is already authenticated — redirect to admin home
header('Location: /admin');
exit;