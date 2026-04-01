<?php
// public/api/photo-action.php
// Admin-only actions on a single photo.
//
// DELETE /api/photo-action?id=N        — delete photo + files
// POST   /api/photo-action?id=N&action=snap — snap to nearest trackpoint by timestamp

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

function fail(string $msg, int $code = 400): never {
	http_response_code($code);
	echo json_encode(['ok' => false, 'error' => $msg]);
	exit;
}

if (!is_admin()) fail('Unauthorized', 401);

$id = (int)($_GET['id'] ?? 0);
if (!$id) fail('Missing id');

$pdo = db();
$stmt = $pdo->prepare("SELECT m.*, t.id AS trip_id FROM media m JOIN trips t ON t.id = m.trip_id WHERE m.id = ?");
$stmt->execute([$id]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$photo) fail('Photo not found', 404);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ── DELETE ────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
	// Remove files from disk
	$uploadDir = __DIR__ . '/../uploads/' . $photo['trip_id'];
	$fullPath  = $uploadDir . '/' . $photo['filename'];
	$thumbPath = $uploadDir . '/thumbs/' . $photo['filename'];
	if (file_exists($fullPath))  unlink($fullPath);
	if (file_exists($thumbPath)) unlink($thumbPath);

	// Remove DB row
	$pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);

	echo json_encode(['ok' => true]);
	exit;
}

// ── SNAP TO TRACK ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'snap') {
	if (!$photo['taken_at']) fail('Photo has no timestamp — cannot snap to track');

	// Find nearest trackpoint by timestamp, scoped to same day in Mountain Time
	$snap = $pdo->prepare("
		SELECT lat, lon
		FROM trackpoints
		WHERE trip_id = :trip_id
		  AND (recorded_at AT TIME ZONE 'America/Denver')::date
			  = (:ts::timestamptz AT TIME ZONE 'America/Denver')::date
		  AND recorded_at BETWEEN (:ts2::timestamptz - interval '30 minutes')
							   AND (:ts3::timestamptz + interval '30 minutes')
		ORDER BY ABS(EXTRACT(EPOCH FROM (recorded_at - :ts4::timestamptz)))
		LIMIT 1
	");
	$snap->execute([
		'trip_id' => $photo['trip_id'],
		'ts'  => $photo['taken_at'],
		'ts2' => $photo['taken_at'],
		'ts3' => $photo['taken_at'],
		'ts4' => $photo['taken_at'],
	]);
	$tp = $snap->fetch(PDO::FETCH_ASSOC);
	if (!$tp) fail('No trackpoint found near this photo\'s timestamp');

	// Update media row
	$pdo->prepare("UPDATE media SET lat = ?, lon = ?, placement_tier = 2 WHERE id = ?")
		->execute([$tp['lat'], $tp['lon'], $id]);

	echo json_encode(['ok' => true, 'lat' => (float)$tp['lat'], 'lon' => (float)$tp['lon']]);
	exit;
}

fail('Invalid request');