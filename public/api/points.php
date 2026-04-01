<?php
// public/api/points.php
// Returns trackpoints as JSON for a trip or a single day.
// GET /api/points/{year}/{slug}
// GET /api/points/{year}/{slug}/{day}

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$year = (int)($_GET['year'] ?? 0);
$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');
$day  = isset($_GET['day']) ? (int)$_GET['day'] : null;

$tripStmt = db()->prepare("SELECT id FROM trips WHERE year = ? AND slug = ?");
$tripStmt->execute([$year, $slug]);
$trip = $tripStmt->fetch();

if (!$trip) {
    http_response_code(404);
    echo json_encode(['error' => 'Trip not found']);
    exit;
}

if ($day !== null) {
    // Single day — join with trip_days to filter by date
    $stmt = db()->prepare("
        SELECT t.lat, t.lon, t.ele, t.recorded_at
        FROM trackpoints t
        JOIN trip_days d ON d.trip_id = t.trip_id
            AND (t.recorded_at AT TIME ZONE 'America/Denver')::date = d.date
        WHERE t.trip_id = :trip_id AND d.day_number = :day
        ORDER BY t.recorded_at
    ");
    $stmt->execute(['trip_id' => $trip['id'], 'day' => $day]);
} else {
    $stmt = db()->prepare("
        SELECT lat, lon, ele, recorded_at
        FROM trackpoints
        WHERE trip_id = ?
        ORDER BY recorded_at
    ");
    $stmt->execute([$trip['id']]);
}

$points = $stmt->fetchAll();

// Downsample for large tracks on full-trip view (max 2000 pts)
if ($day === null && count($points) > 2000) {
    $step = ceil(count($points) / 2000);
    $points = array_values(array_filter($points, fn($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY));
}

echo json_encode(array_map(fn($p) => [
    'lat' => (float)$p['lat'],
    'lon' => (float)$p['lon'],
    'ele' => $p['ele'] !== null ? (float)$p['ele'] : null,
    'ts'  => $p['recorded_at'],
], $points));
