<?php
// public/api/gpx.php
// Exports a trip's trackpoints as a GPX file.
// GET /api/gpx/{year}/{slug}

require_once __DIR__ . '/../../includes/db.php';

$year = (int)($_GET['year'] ?? 0);
$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');

$tripStmt = db()->prepare("SELECT * FROM trips WHERE year = ? AND slug = ?");
$tripStmt->execute([$year, $slug]);
$trip = $tripStmt->fetch();

if (!$trip) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare("SELECT lat, lon, ele, recorded_at FROM trackpoints WHERE trip_id = ? ORDER BY recorded_at");
$stmt->execute([$trip['id']]);
$points = $stmt->fetchAll();

$name = htmlspecialchars($trip['name'], ENT_XML1);
$time = $trip['started_at'] ? date('c', strtotime($trip['started_at'])) : date('c');

header('Content-Type: application/gpx+xml');
header('Content-Disposition: attachment; filename="' . $slug . '.gpx"');
header('Cache-Control: public, max-age=3600');

echo <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="Backcountry Club" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>{$name}</name><time>{$time}</time></metadata>
  <trk><name>{$name}</name><trkseg>
XML;

foreach ($points as $p) {
    $lat = number_format((float)$p['lat'], 7, '.', '');
    $lon = number_format((float)$p['lon'], 7, '.', '');
    $t   = date('Y-m-d\TH:i:s\Z', strtotime($p['recorded_at']));
    $ele = $p['ele'] !== null ? "\n    <ele>" . number_format((float)$p['ele'], 3, '.', '') . "</ele>" : '';
    echo "\n  <trkpt lat=\"{$lat}\" lon=\"{$lon}\">{$ele}\n    <time>{$t}</time>\n  </trkpt>";
}

echo "\n  </trkseg></trk>\n</gpx>\n";
