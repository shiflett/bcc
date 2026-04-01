#!/usr/bin/env php
<?php
// bin/timestamp-track-from-photos.php
//
// Assigns timestamps to a Strava GPX (which has no timestamps) by using
// GPS photos AND the existing sparse track as temporal anchors.
//
// Anchor sources (all available are used):
//   1. GPS photos (tier 1) for the day — EXIF timestamp + coordinates
//   2. Existing trackpoints in DB for the day — sparse but timestamped
//
// Algorithm:
//   1. Collect all anchors from both sources
//   2. For each anchor, find the nearest point on the Strava track geographically
//   3. Sort anchors by track position
//   4. Interpolate timestamps between anchors by cumulative distance
//   5. Extrapolate before first / after last anchor at the same pace
//   6. Write a new timestamped GPX ready for import-gpx.php
//
// Usage (from project root):
//   docker compose exec bcc php bin/timestamp-track-from-photos.php \
//     <year> <slug> <day_number> <input.gpx> <output.gpx>
//
// Example:
//   docker compose exec bcc php bin/timestamp-track-from-photos.php \
//     2025 flat-tops 1 /app/gpx/strava/Mini_Day_1.gpx /app/gpx/flat-tops-day1.gpx

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

if ($argc < 6) {
	fwrite(STDERR, "Usage: php bin/timestamp-track-from-photos.php <year> <slug> <day> <input.gpx> <output.gpx>\n");
	exit(1);
}

[, $year, $slug, $dayNum, $inputFile, $outputFile] = $argv;
$dayNum = (int)$dayNum;

if (!file_exists($inputFile)) {
	fwrite(STDERR, "Input file not found: {$inputFile}\n");
	exit(1);
}

require_once __DIR__ . '/../includes/db.php';
$db = db();

// ----------------------------------------------------------------
// Load trip and day
// ----------------------------------------------------------------
$tripStmt = $db->prepare("SELECT * FROM trips WHERE year = ? AND slug = ?");
$tripStmt->execute([(int)$year, $slug]);
$trip = $tripStmt->fetch();
if (!$trip) {
	fwrite(STDERR, "Trip not found: {$year}/{$slug}\n");
	exit(1);
}

echo "Trip:    {$trip['name']} (ID {$trip['id']})\n";

$dayStmt = $db->prepare("SELECT date FROM trip_days WHERE trip_id = ? AND day_number = ?");
$dayStmt->execute([$trip['id'], $dayNum]);
$dayRow = $dayStmt->fetch();

if (!$dayRow) {
	// trip_days may not exist yet (e.g. after a reset) — derive date from
	// trip started_at + (day_number - 1) days
	$tripStart = $trip['started_at'] ?? null;
	if (!$tripStart) {
		fwrite(STDERR, "Day {$dayNum} not found and trip has no started_at — cannot determine date\n");
		exit(1);
	}
	$dayDate = date('Y-m-d', strtotime($tripStart . ' + ' . ($dayNum - 1) . ' days'));
	echo "Day:     {$dayNum} ({$dayDate}) [derived from trip start]\n";
} else {
	$dayDate = $dayRow['date'];
	echo "Day:     {$dayNum} ({$dayDate})\n";
}

// ----------------------------------------------------------------
// Parse input GPX (no timestamps)
// ----------------------------------------------------------------
$xml = simplexml_load_file($inputFile);
if (!$xml) {
	fwrite(STDERR, "Failed to parse GPX: {$inputFile}\n");
	exit(1);
}

$points = [];
foreach ($xml->trk as $trk) {
	foreach ($trk->trkseg as $seg) {
		foreach ($seg->trkpt as $pt) {
			$lat = (float)(string)$pt['lat'];
			$lon = (float)(string)$pt['lon'];
			$ele = isset($pt->ele) ? (float)(string)$pt->ele : null;
			if ($lat && $lon) {
				$points[] = ['lat' => $lat, 'lon' => $lon, 'ele' => $ele];
			}
		}
	}
}

if (empty($points)) {
	fwrite(STDERR, "No trackpoints found in GPX.\n");
	exit(1);
}
echo "Track:   " . count($points) . " points (raw)\n";

// ----------------------------------------------------------------
// Simplify track — remove GPS noise from stationary periods
//
// Strava records at ~1Hz continuously, so rest stops and camp time
// create thousands of near-stationary points that cause sawtooth
// elevation profiles and back-and-forth map animation.
//
// Step 1: Distance filter — skip points within $minMoveM of the
//         last kept point. Removes stationary noise.
// Step 2: RDP simplification — remove collinear points on straight
//         sections, keeping the track shape accurate.
// ----------------------------------------------------------------
$minMoveM  = 8;    // minimum movement to keep a point (metres)
$rdpEpsilon = 5;   // RDP tolerance in metres

// Step 1: distance filter
$filtered = [$points[0]];
foreach ($points as $pt) {
	$last = end($filtered);
	if (haversine($last['lat'], $last['lon'], $pt['lat'], $pt['lon']) >= $minMoveM) {
		$filtered[] = $pt;
	}
}
// Always keep the last point
if (end($filtered) !== end($points)) $filtered[] = end($points);

echo "         " . count($filtered) . " after distance filter (≥{$minMoveM}m)\n";

// Step 2: Ramer-Douglas-Peucker simplification
ini_set('xdebug.max_nesting_level', 10000);
ini_set('pcre.recursion_limit', 10000);
$points = rdp($filtered, $rdpEpsilon);
$points = array_values($points);
echo "         " . count($points) . " after RDP simplification (ε={$rdpEpsilon}m)\n";

// ----------------------------------------------------------------
// Build cumulative distance array along track
// ----------------------------------------------------------------
$cumDist = [0.0];
for ($i = 1; $i < count($points); $i++) {
	$cumDist[$i] = $cumDist[$i-1] + haversine(
		$points[$i-1]['lat'], $points[$i-1]['lon'],
		$points[$i]['lat'],   $points[$i]['lon']
	);
}
$totalDist = end($cumDist);
echo "         " . round($totalDist / 1000, 2) . " km total\n";

// ----------------------------------------------------------------
// Collect anchors from both sources
// ----------------------------------------------------------------
$rawAnchors = [];

// Source 1: GPS photos (tier 1) for this day
$photoStmt = $db->prepare("
	SELECT lat, lon, taken_at, 'photo' AS source
	FROM media
	WHERE trip_id = ? AND day_number = ?
	  AND placement_tier = 1
	  AND lat IS NOT NULL AND lon IS NOT NULL AND taken_at IS NOT NULL
	ORDER BY taken_at
");
$photoStmt->execute([$trip['id'], $dayNum]);
$photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

// Source 2: Existing sparse trackpoints for this day
$trackStmt = $db->prepare("
	SELECT lat, lon, recorded_at AS taken_at, 'track' AS source
	FROM trackpoints
	WHERE trip_id = ?
	  AND (recorded_at AT TIME ZONE 'America/Denver')::date = ?
	ORDER BY recorded_at
");
$trackStmt->execute([$trip['id'], $dayDate]);
$existingTrack = $trackStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Anchors: " . count($photos) . " GPS photos, " . count($existingTrack) . " existing trackpoints\n";

$rawAnchors = array_merge($photos, $existingTrack);

if (count($rawAnchors) < 2) {
	fwrite(STDERR, "Need at least 2 anchors total — found " . count($rawAnchors) . "\n");
	fwrite(STDERR, "Cannot timestamp this track without GPS photos or an existing track.\n");
	exit(1);
}

// ----------------------------------------------------------------
// Match each anchor to nearest point on the Strava track
// Two-pass: coarse scan then fine scan around best candidate
// ----------------------------------------------------------------
echo "\nMatching anchors to track...\n";
$anchors = [];
$n = count($points);
$step = max(1, (int)($n / 500));

foreach ($rawAnchors as $a) {
	// Coarse pass
	$bestIdx  = 0;
	$bestDist = PHP_FLOAT_MAX;
	for ($i = 0; $i < $n; $i += $step) {
		$d = haversine((float)$a['lat'], (float)$a['lon'], $points[$i]['lat'], $points[$i]['lon']);
		if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
	}
	// Fine pass ±500 points around coarse best
	$lo = max(0, $bestIdx - 500);
	$hi = min($n - 1, $bestIdx + 500);
	for ($i = $lo; $i <= $hi; $i++) {
		$d = haversine((float)$a['lat'], (float)$a['lon'], $points[$i]['lat'], $points[$i]['lon']);
		if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
	}

	$ts      = strtotime($a['taken_at']);
	$timeStr = date('H:i', $ts);
	$flag    = $bestDist > 200 ? ' ⚠  (>200m — possible GPS drift)' : '';
	echo "  [{$a['source']}] {$timeStr} → idx {$bestIdx} (" . round($bestDist) . "m){$flag}\n";

	$anchors[] = [
		'track_idx'  => $bestIdx,
		'track_dist' => $cumDist[$bestIdx],
		'timestamp'  => $ts,
		'source'     => $a['source'],
		'match_m'    => round($bestDist),
	];
}

// Sort by track position
usort($anchors, fn($a, $b) => $a['track_idx'] <=> $b['track_idx']);

// Deduplicate: same track index → keep anchor with smallest match distance
$deduped = [];
foreach ($anchors as $a) {
	$k = $a['track_idx'];
	if (!isset($deduped[$k]) || $a['match_m'] < $deduped[$k]['match_m']) {
		$deduped[$k] = $a;
	}
}
$anchors = array_values($deduped);

echo "\nAfter dedup: " . count($anchors) . " anchors\n";
echo "Time window: " . date('H:i', $anchors[0]['timestamp'])
   . " → " . date('H:i', end($anchors)['timestamp']) . "\n";

// Warn about out-of-order timestamps (anchor at later track position has earlier time)
for ($i = 1; $i < count($anchors); $i++) {
	if ($anchors[$i]['timestamp'] <= $anchors[$i-1]['timestamp']) {
		echo "  ⚠  Anchor {$i} timestamp not after anchor " . ($i-1)
		   . " (" . date('H:i', $anchors[$i-1]['timestamp'])
		   . " → " . date('H:i', $anchors[$i]['timestamp']) . ")"
		   . " — interpolation may be imprecise near here\n";
	}
}

// ----------------------------------------------------------------
// Interpolate timestamps for all track points
// ----------------------------------------------------------------
$timestamps = array_fill(0, $n, null);

// Set timestamps at anchor positions
foreach ($anchors as $a) {
	$timestamps[$a['track_idx']] = $a['timestamp'];
}

// Interpolate between consecutive anchors by distance fraction
for ($ai = 0; $ai < count($anchors) - 1; $ai++) {
	$a0 = $anchors[$ai];
	$a1 = $anchors[$ai + 1];
	$distSpan = $a1['track_dist'] - $a0['track_dist'];
	$timeSpan = $a1['timestamp']  - $a0['timestamp'];
	if ($a1['track_idx'] <= $a0['track_idx'] + 1 || $distSpan <= 0 || $timeSpan <= 0) continue;
	for ($i = $a0['track_idx'] + 1; $i < $a1['track_idx']; $i++) {
		$frac          = ($cumDist[$i] - $a0['track_dist']) / $distSpan;
		$timestamps[$i] = (int)round($a0['timestamp'] + $frac * $timeSpan);
	}
}

// Extrapolate before first anchor (same pace as first segment)
$first  = $anchors[0];
$second = $anchors[1] ?? null;
if ($second && $second['track_dist'] > $first['track_dist'] && $second['timestamp'] > $first['timestamp']) {
	$pace = ($second['timestamp'] - $first['timestamp'])
		  / ($second['track_dist'] - $first['track_dist']);
	for ($i = 0; $i < $first['track_idx']; $i++) {
		$timestamps[$i] = (int)round($first['timestamp'] - ($first['track_dist'] - $cumDist[$i]) * $pace);
	}
}

// Extrapolate after last anchor (same pace as last segment)
$last   = end($anchors);
$penult = count($anchors) >= 2 ? $anchors[count($anchors) - 2] : null;
if ($penult && $last['track_dist'] > $penult['track_dist'] && $last['timestamp'] > $penult['timestamp']) {
	$pace = ($last['timestamp'] - $penult['timestamp'])
		  / ($last['track_dist'] - $penult['track_dist']);
	for ($i = $last['track_idx'] + 1; $i < $n; $i++) {
		$timestamps[$i] = (int)round($last['timestamp'] + ($cumDist[$i] - $last['track_dist']) * $pace);
	}
}

// Fill any remaining nulls by propagating last known value
$lastGood = $timestamps[0] ?? time();
foreach ($timestamps as $i => $ts) {
	if ($ts === null) $timestamps[$i] = $lastGood;
	else $lastGood = $ts;
}

echo "Interpolated: " . count($points) . " timestamps\n";
echo "Result:  " . date('Y-m-d H:i', $timestamps[0])
   . " → " . date('Y-m-d H:i', end($timestamps)) . "\n";

// ----------------------------------------------------------------
// Write output GPX with timestamps
// ----------------------------------------------------------------
$out = fopen($outputFile, 'w');
if (!$out) {
	fwrite(STDERR, "Cannot write to: {$outputFile}\n");
	exit(1);
}

$trkName = isset($xml->trk->name) ? htmlspecialchars((string)$xml->trk->name) : "Day {$dayNum}";

fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
fwrite($out, '<gpx version="1.1" creator="bcc-timestamp-tool" xmlns="http://www.topografix.com/GPX/1/1">' . "\n");
fwrite($out, "<trk>\n  <name>{$trkName}</name>\n  <trkseg>\n");

foreach ($points as $i => $pt) {
	$time = gmdate('Y-m-d\TH:i:s\Z', $timestamps[$i]);
	$ele  = $pt['ele'] !== null ? "\n      <ele>{$pt['ele']}</ele>" : '';
	fwrite($out, "    <trkpt lat=\"{$pt['lat']}\" lon=\"{$pt['lon']}\">{$ele}\n");
	fwrite($out, "      <time>{$time}</time>\n    </trkpt>\n");
}

fwrite($out, "  </trkseg>\n</trk>\n</gpx>\n");
fclose($out);

echo "\nWritten: {$outputFile}\n";
echo "Next:    docker compose exec bcc php bin/import-gpx.php {$year} {$slug} \"{$trip['name']}\" \"{$trip['subtitle']}\" {$outputFile}\n";

// ----------------------------------------------------------------
// Haversine distance in metres
// ----------------------------------------------------------------
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
	$R    = 6371000;
	$phi1 = deg2rad($lat1);
	$phi2 = deg2rad($lat2);
	$dphi = deg2rad($lat2 - $lat1);
	$dlam = deg2rad($lon2 - $lon1);
	$a    = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlam/2)**2;
	return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// ----------------------------------------------------------------
// Ramer-Douglas-Peucker track simplification
// ----------------------------------------------------------------
function rdp(array $pts, float $epsilon): array {
	if (count($pts) < 3) return $pts;
	$start = $pts[0];
	$end   = $pts[count($pts) - 1];
	$maxD  = 0.0;
	$maxI  = 0;
	for ($i = 1; $i < count($pts) - 1; $i++) {
		$d = perp_distance($pts[$i], $start, $end);
		if ($d > $maxD) { $maxD = $d; $maxI = $i; }
	}
	if ($maxD > $epsilon) {
		$left  = rdp(array_slice($pts, 0, $maxI + 1), $epsilon);
		$right = rdp(array_slice($pts, $maxI), $epsilon);
		return array_merge(array_slice($left, 0, -1), $right);
	}
	return [$start, $end];
}

function perp_distance(array $pt, array $start, array $end): float {
	$scale  = 111320.0;
	$cosLat = cos(deg2rad($pt['lat']));
	$ax = $start['lon'] * $scale * $cosLat; $ay = $start['lat'] * $scale;
	$bx = $end['lon']   * $scale * $cosLat; $by = $end['lat']   * $scale;
	$px = $pt['lon']    * $scale * $cosLat; $py = $pt['lat']    * $scale;
	$dx = $bx - $ax; $dy = $by - $ay;
	$lenSq = $dx*$dx + $dy*$dy;
	if ($lenSq == 0) return sqrt(($px-$ax)**2 + ($py-$ay)**2);
	$t = max(0, min(1, (($px-$ax)*$dx + ($py-$ay)*$dy) / $lenSq));
	return sqrt(($px - ($ax + $t*$dx))**2 + ($py - ($ay + $t*$dy))**2);
}