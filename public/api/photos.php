<?php
// public/api/photos.php
// Returns photos for a trip day as JSON.
// GET /api/photos/{year}/{slug}/{day}

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$year = (int)($_GET['year'] ?? 0);
$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');
$day  = (int)($_GET['day'] ?? 0);

$tripStmt = db()->prepare("SELECT id FROM trips WHERE year = ? AND slug = ?");
$tripStmt->execute([$year, $slug]);
$trip = $tripStmt->fetch();

if (!$trip) {
	http_response_code(404);
	echo json_encode(['error' => 'Trip not found']);
	exit;
}

$stmt = db()->prepare("
	SELECT id, filename, taken_at, lat, lon, placement_tier, width, height, body
	FROM media
	WHERE trip_id = ? AND day_number = ? AND kind = 'photo'
	ORDER BY taken_at, display_order, id
");
$stmt->execute([$trip['id'], $day]);
$photos = $stmt->fetchAll();

echo json_encode(array_map(fn($p) => [
	'id'       => (int)$p['id'],
	'filename' => $p['filename'],
	'taken_at' => $p['taken_at'],
	'lat'      => $p['lat'] !== null ? (float)$p['lat'] : null,
	'lon'      => $p['lon'] !== null ? (float)$p['lon'] : null,
	'tier'     => (int)$p['placement_tier'],
	'width'    => (int)$p['width'],
	'height'   => (int)$p['height'],
	'caption'  => $p['body'],
], $photos));