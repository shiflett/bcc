#!/usr/bin/env php
<?php
// bin/import-gpx.php
// Import a GPX file into the BCC database.
//
// Usage:
//   php bin/import-gpx.php <year> <slug> <name> <subtitle> <file.gpx> [--day=N]
//
// Without --day: replaces ALL trackpoints and trip_days (full re-import)
// With --day=N:  replaces only that day's data, leaving other days untouched.
//               Use this when importing per-day Strava tracks.
//
// Examples:
//   php bin/import-gpx.php 2024 james-peak-scouts "James Peak" "James Peak Wilderness" track.gpx
//   php bin/import-gpx.php 2025 flat-tops "Flat Tops" "Flat Tops Wilderness" day1.gpx --day=1

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

if ($argc < 6) {
    fwrite(STDERR, "Usage: php bin/import-gpx.php <year> <slug> <name> <subtitle> <file.gpx> [--day=N]\n");
    exit(1);
}

[, $year, $slug, $name, $subtitle, $file] = $argv;

// Parse optional --day=N flag
$dayFilter = null;
foreach ($argv as $arg) {
    if (preg_match('/^--day=(\d+)$/', $arg, $m)) {
        $dayFilter = (int)$m[1];
    }
}

if (!file_exists($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
$db = db();

echo "Importing {$file} → {$year}/{$slug}" . ($dayFilter !== null ? " (day {$dayFilter} only)" : " (full)") . "\n";

// ----------------------------------------------------------------
// Parse GPX
// ----------------------------------------------------------------
$xml = simplexml_load_file($file);
if (!$xml) {
    fwrite(STDERR, "Failed to parse GPX file.\n");
    exit(1);
}

$points = [];
foreach ($xml->trk as $trk) {
    foreach ($trk->trkseg as $seg) {
        foreach ($seg->trkpt as $pt) {
            $lat  = (float)(string)$pt['lat'];
            $lon  = (float)(string)$pt['lon'];
            $ele  = isset($pt->ele)  ? (float)(string)$pt->ele  : null;
            $time = isset($pt->time) ? (string)$pt->time        : null;
            if ($lat && $lon && $time) {
                $points[] = compact('lat', 'lon', 'ele', 'time');
            }
        }
    }
}

if (empty($points)) {
    fwrite(STDERR, "No trackpoints with timestamps found in GPX file.\n");
    exit(1);
}
echo "  Found " . count($points) . " trackpoints\n";

// ----------------------------------------------------------------
// Upsert trip — in --day mode, don't overwrite started_at/ended_at
// ----------------------------------------------------------------
if ($dayFilter !== null) {
    // Just ensure trip exists; don't touch dates
    $stmt = $db->prepare("
        INSERT INTO trips (year, slug, name, subtitle)
        VALUES (:year, :slug, :name, :subtitle)
        ON CONFLICT (year, slug) DO UPDATE SET
            name     = EXCLUDED.name,
            subtitle = EXCLUDED.subtitle
        RETURNING id
    ");
    $stmt->execute([
        'year'     => (int)$year,
        'slug'     => $slug,
        'name'     => $name,
        'subtitle' => $subtitle,
    ]);
} else {
    $stmt = $db->prepare("
        INSERT INTO trips (year, slug, name, subtitle, started_at, ended_at)
        VALUES (:year, :slug, :name, :subtitle, :started_at, :ended_at)
        ON CONFLICT (year, slug) DO UPDATE SET
            name       = EXCLUDED.name,
            subtitle   = EXCLUDED.subtitle,
            started_at = EXCLUDED.started_at,
            ended_at   = EXCLUDED.ended_at
        RETURNING id
    ");
    $stmt->execute([
        'year'       => (int)$year,
        'slug'       => $slug,
        'name'       => $name,
        'subtitle'   => $subtitle,
        'started_at' => $points[0]['time'],
        'ended_at'   => end($points)['time'],
    ]);
}
$tripId = $stmt->fetchColumn();
echo "  Trip ID: {$tripId}\n";

// ----------------------------------------------------------------
// Delete existing trackpoints — scoped to day when --day is set
// ----------------------------------------------------------------
if ($dayFilter !== null) {
    // Derive the expected date for this day from trip started_at + offset
    $tripStmt = $db->prepare("SELECT started_at FROM trips WHERE id = ?");
    $tripStmt->execute([$tripId]);
    $tripStart = $tripStmt->fetchColumn();
    $expectedDate = date('Y-m-d', strtotime($tripStart . ' + ' . ($dayFilter - 1) . ' days'));
    echo "  Expected date for day {$dayFilter}: {$expectedDate}\n";

    $db->prepare("
        DELETE FROM trackpoints
        WHERE trip_id = ?
          AND (recorded_at AT TIME ZONE 'America/Denver')::date = ?
    ")->execute([$tripId, $expectedDate]);
} else {
    $db->prepare("DELETE FROM trackpoints WHERE trip_id = ?")->execute([$tripId]);
}

// ----------------------------------------------------------------
// Insert trackpoints in batches
// ----------------------------------------------------------------
$batchSize = 500;
$total     = count($points);
$inserted  = 0;

for ($i = 0; $i < $total; $i += $batchSize) {
    $batch        = array_slice($points, $i, $batchSize);
    $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));
    $values       = [];
    foreach ($batch as $p) {
        $values[] = $tripId;
        $values[] = $p['lat'];
        $values[] = $p['lon'];
        $values[] = $p['ele'];
        $values[] = $p['time'];
    }
    $db->prepare("INSERT INTO trackpoints (trip_id, lat, lon, ele, recorded_at) VALUES {$placeholders}")->execute($values);
    $inserted += count($batch);
    echo "  Inserted {$inserted}/{$total} trackpoints\r";
}
echo "\n";

// ----------------------------------------------------------------
// Compute per-day stats — scoped to expected date in --day mode
// ----------------------------------------------------------------
if ($dayFilter !== null) {
    $db->prepare("DELETE FROM trip_days WHERE trip_id = ? AND day_number = ?")->execute([$tripId, $dayFilter]);
} else {
    $db->prepare("DELETE FROM trip_days WHERE trip_id = ?")->execute([$tripId]);
}

// In --day mode, only load trackpoints on the expected date
$dateClause = $dayFilter !== null ? "AND (recorded_at AT TIME ZONE 'America/Denver')::date = :date_filter" : "";
$stmt2 = $db->prepare("
    SELECT lat, lon, ele, recorded_at,
           (recorded_at AT TIME ZONE 'America/Denver')::date AS local_date
    FROM trackpoints
    WHERE trip_id = :trip_id {$dateClause}
    ORDER BY recorded_at
");
$params2 = ['trip_id' => $tripId];
if ($dayFilter !== null) $params2['date_filter'] = $expectedDate;
$stmt2->execute($params2);
$allPoints = $stmt2->fetchAll();

// Group by date
$byDate = [];
foreach ($allPoints as $p) {
    $byDate[$p['local_date']][] = $p;
}
ksort($byDate);

$dayNumber = $dayFilter ?? 1;
foreach ($byDate as $date => $pts) {
    $gain = 0.0; $loss = 0.0; $dist = 0.0;
    $prevEle = null; $prevLat = null; $prevLon = null;

    foreach ($pts as $p) {
        $ele = $p['ele'] !== null ? (float)$p['ele'] : null;
        if ($ele !== null && $prevEle !== null) {
            $diff = $ele - $prevEle;
            if ($diff > 0) $gain += $diff; else $loss += abs($diff);
        }
        $prevEle = $ele;
        if ($prevLat !== null) {
            $dist += haversine($prevLat, $prevLon, (float)$p['lat'], (float)$p['lon']);
        }
        $prevLat = (float)$p['lat'];
        $prevLon = (float)$p['lon'];
    }

    $dayName = date('l', strtotime($date));
    $db->prepare("
        INSERT INTO trip_days (trip_id, day_number, date, point_count, gain_m, loss_m, distance_m, started_at, ended_at)
        VALUES (:trip_id, :day_number, :date, :point_count, :gain_m, :loss_m, :distance_m, :started_at, :ended_at)
        ON CONFLICT (trip_id, day_number) DO UPDATE SET
            date        = EXCLUDED.date,
            point_count = EXCLUDED.point_count,
            gain_m      = EXCLUDED.gain_m,
            loss_m      = EXCLUDED.loss_m,
            distance_m  = EXCLUDED.distance_m,
            started_at  = EXCLUDED.started_at,
            ended_at    = EXCLUDED.ended_at
    ")->execute([
        'trip_id'     => $tripId,
        'day_number'  => $dayNumber,
        'date'        => $date,
        'point_count' => count($pts),
        'gain_m'      => round($gain, 1),
        'loss_m'      => round($loss, 1),
        'distance_m'  => round($dist, 1),
        'started_at'  => $pts[0]['recorded_at'],
        'ended_at'    => end($pts)['recorded_at'],
    ]);

    $gainFt = round($gain * 3.28084);
    $lossFt = round($loss * 3.28084);
    $distMi = round($dist / 1609.34, 1);
    echo "  Day {$dayNumber} ({$dayName} {$date}): " . count($pts) . " pts, +{$gainFt}ft/-{$lossFt}ft, {$distMi}mi\n";
    if ($dayFilter === null) $dayNumber++;
}

// Recompute trip started_at/ended_at from trip_days
$db->prepare("
    UPDATE trips SET
        started_at = (SELECT MIN(date) FROM trip_days WHERE trip_id = :id),
        ended_at   = (SELECT MAX(date) FROM trip_days WHERE trip_id = :id2)
    WHERE id = :id3
")->execute([':id' => $tripId, ':id2' => $tripId, ':id3' => $tripId]);

// ----------------------------------------------------------------
// Re-interpolate tier-2 and tier-4 photos against new track
// ----------------------------------------------------------------
$photoStmt = $db->prepare("
    SELECT id, taken_at FROM media
    WHERE trip_id = ? AND taken_at IS NOT NULL
      AND placement_tier IN (2, 4)
      " . ($dayFilter !== null ? "AND day_number = ?" : "") . "
");
$photoParams = $dayFilter !== null ? [$tripId, $dayFilter] : [$tripId];
$photoStmt->execute($photoParams);
$photosToUpdate = $photoStmt->fetchAll();

if (!empty($photosToUpdate)) {
    $interp = $db->prepare("
        SELECT lat, lon FROM trackpoints
        WHERE trip_id = :trip_id
          AND (recorded_at AT TIME ZONE 'America/Denver')::date
              = (:ts::timestamptz AT TIME ZONE 'America/Denver')::date
          AND recorded_at BETWEEN (:ts2::timestamptz - interval '30 minutes')
                               AND (:ts3::timestamptz + interval '30 minutes')
        ORDER BY ABS(EXTRACT(EPOCH FROM (recorded_at - :ts4::timestamptz)))
        LIMIT 1
    ");
    $update  = $db->prepare("UPDATE media SET lat = ?, lon = ?, placement_tier = 2 WHERE id = ?");
    $updated = 0;
    foreach ($photosToUpdate as $photo) {
        $interp->execute([
            'trip_id' => $tripId,
            'ts'  => $photo['taken_at'], 'ts2' => $photo['taken_at'],
            'ts3' => $photo['taken_at'], 'ts4' => $photo['taken_at'],
        ]);
        $tp = $interp->fetch();
        if ($tp) { $update->execute([$tp['lat'], $tp['lon'], $photo['id']]); $updated++; }
    }
    echo "  Re-interpolated {$updated}/" . count($photosToUpdate) . " photos\n";
}

echo "Done.\n";

// ----------------------------------------------------------------
// Haversine distance in metres
// ----------------------------------------------------------------
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371000;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lon2 - $lon1);
    $a    = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlam/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}