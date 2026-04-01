<?php
// public/router.php
require_once __DIR__ . '/../includes/db.php';

$uri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri   = rtrim($uri, '/') ?: '/';
$parts = explode('/', ltrim($uri, '/'));

// GET /
if ($uri === '/') {
    require __DIR__ . '/pages/home.php'; exit;
}

// POST /api/upload-photo
if ($uri === '/api/upload-photo') {
    require __DIR__ . '/api/upload-photo.php'; exit;
}

// DELETE /api/photo-action?id=N
// POST   /api/photo-action?id=N&action=snap
if ($uri === '/api/photo-action') {
    require __DIR__ . '/api/photo-action.php'; exit;
}

// GET /api/points/{year}/{slug}
// GET /api/points/{year}/{slug}/{day}
if ($parts[0] === 'api' && $parts[1] === 'points') {
    $_GET['year'] = $parts[2] ?? null;
    $_GET['slug'] = $parts[3] ?? null;
    $_GET['day']  = $parts[4] ?? null;
    require __DIR__ . '/api/points.php'; exit;
}

// GET /api/photos/{year}/{slug}/{day}
if ($parts[0] === 'api' && $parts[1] === 'photos') {
    $_GET['year'] = $parts[2] ?? null;
    $_GET['slug'] = $parts[3] ?? null;
    $_GET['day']  = $parts[4] ?? null;
    require __DIR__ . '/api/photos.php'; exit;
}

// GET /api/gpx/{year}/{slug}
if ($parts[0] === 'api' && $parts[1] === 'gpx') {
    $_GET['year'] = $parts[2] ?? null;
    $_GET['slug'] = $parts[3] ?? null;
    require __DIR__ . '/api/gpx.php'; exit;
}

// GET /admin — trips index
if ($uri === '/admin') {
    require_once __DIR__ . '/../includes/auth.php';
    require_admin_auth();
    require __DIR__ . '/admin/index.php'; exit;
}

// GET|POST /login
if ($uri === '/login') {
    require_once __DIR__ . '/../includes/auth.php';
    require_admin_auth();
    // If already authenticated, redirect to admin
    header('Location: /admin');
    exit;
}

// GET /admin/photos/{year}/{slug}
if ($parts[0] === 'admin' && $parts[1] === 'photos' && isset($parts[2], $parts[3])) {
    $_GET['year'] = $parts[2];
    $_GET['slug'] = $parts[3];
    require __DIR__ . '/admin/photos.php'; exit;
}

// GET|POST /admin/new — create a new trip
if ($parts[0] === 'admin' && ($parts[1] ?? '') === 'new') {
    require_once __DIR__ . '/../includes/auth.php';
    require_admin_auth();
    require __DIR__ . '/admin/trip-edit.php'; exit;
}

// GET|POST /admin/{token} — edit an existing trip
if ($parts[0] === 'admin' && isset($parts[1]) && !isset($parts[2])) {
    $_GET['token'] = $parts[1];
    require_once __DIR__ . '/../includes/auth.php';
    require_admin_auth();
    require __DIR__ . '/admin/trip-edit.php'; exit;
}

// GET /{year}/{slug}/day/{n}
if (count($parts) === 4 && $parts[2] === 'day' && is_numeric($parts[0])) {
    $_GET['year'] = $parts[0];
    $_GET['slug'] = $parts[1];
    $_GET['day']  = (int)$parts[3];
    require __DIR__ . '/pages/day.php'; exit;
}

// GET /{year}/{slug}
if (count($parts) === 2 && is_numeric($parts[0])) {
    $_GET['year'] = $parts[0];
    $_GET['slug'] = $parts[1];
    require __DIR__ . '/pages/trip.php'; exit;
}

// 404
http_response_code(404);
require __DIR__ . '/pages/404.php';