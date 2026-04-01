#!/usr/bin/env php
<?php
/**
 * Import trips from backcountry.club into local DB
 * Run: docker exec local-bcc-1 php /app/bin/import-trips.php
 * Safe to re-run — existing trips are updated, trackpoints re-imported.
 */

require_once __DIR__ . '/../includes/db.php';
$pdo = db();

$pdo->exec('ALTER TABLE trips ADD COLUMN IF NOT EXISTS is_bcc BOOLEAN DEFAULT FALSE');

$bcc_slugs = [
	'2015/indian-peaks',
	'2016/indian-peaks',
	'2017/indian-peaks',
	'2017/maroon-bells',
	'2018/maroon-bells',
	'2018/indian-peaks',
	'2019/indian-peaks',
	'2019/rocky-mountain',
];

$all_trips = [
	[2025,'conundrum'],[2025,'flat-tops'],
	[2024,'james-peak'],[2024,'snowshoe'],[2022,'james-peak-again'],
	[2022,'james-peak'],[2021,'james-peak'],[2020,'rocky-mountain'],
	[2020,'james-peak'],[2019,'rocky-mountain'],[2019,'indian-peaks'],
	[2019,'james-peak'],[2018,'james-peak'],[2018,'indian-peaks'],
	[2018,'maroon-bells'],[2018,'faculty-retreat'],[2017,'maroon-bells'],
	[2017,'indian-peaks'],[2017,'seven-summits'],[2016,'indian-peaks'],
	[2015,'maroon-bells'],[2015,'indian-peaks'],
];

function fetch_url(string $url): ?string {
	$ctx = stream_context_create(['http'=>['timeout'=>30,'user_agent'=>'BCC-Import/1.0']]);
	$r = @file_get_contents($url, false, $ctx);
	return $r !== false ? $r : null;
}

function parse_trip_page(string $html): array {
	$result = ['name'=>null,'subtitle'=>null,'started_at'=>null,'ended_at'=>null,
			   'map_lat'=>null,'map_lon'=>null,'map_zoom'=>null];
	if (!preg_match('/<script id="__NEXT_DATA__"[^>]*>([\s\S]*?)<\/script>/', $html, $m)) return $result;
	$next = json_decode($m[1], true);
	$state = $next['props']['pageProps']['initialApolloState'] ?? [];
	$trip = null;
	foreach ($state as $val) {
		if (($val['__typename'] ?? '') === 'Trip') { $trip = $val; break; }
	}
	if (!$trip) return $result;
	$result['name']     = $trip['name'] ?? null;
	$result['subtitle'] = $trip['locationName'] ?? null;
	$result['map_lat']  = $trip['center']['location']['lat'] ?? null;
	$result['map_lon']  = $trip['center']['location']['lng'] ?? null;
	$result['map_zoom'] = isset($trip['startZoom']) ? (int)round((float)$trip['startZoom']) : null;
	$tz = $trip['timeZone'] ?? 'America/Denver';
	foreach (['startDate'=>'started_at','endDate'=>'ended_at'] as $src=>$dst) {
		if (!empty($trip[$src])) {
			$dt = new DateTime('@'.(int)($trip[$src]/1000));
			$dt->setTimezone(new DateTimeZone($tz));
			$result[$dst] = $dt->format('Y-m-d');
		}
	}
	return $result;
}

function import_gpx(PDO $pdo, int $trip_id, string $xml_str): int {
	$pdo->prepare('DELETE FROM trackpoints WHERE trip_id=? AND source=?')->execute([$trip_id,'gpx']);
	$xml = @simplexml_load_string($xml_str);
	if (!$xml) return 0;
	$xml->registerXPathNamespace('g','http://www.topografix.com/GPX/1/1');
	$pts = $xml->xpath('//g:trkpt') ?: $xml->xpath('//trkpt') ?: [];
	$ins = $pdo->prepare('INSERT INTO trackpoints (trip_id,lat,lon,ele,recorded_at,source)
		VALUES (:trip_id,:lat,:lon,:ele,:recorded_at,:source) ON CONFLICT DO NOTHING');
	$n = 0;
	foreach ($pts as $pt) {
		$lat=(float)($pt['lat']??0); $lon=(float)($pt['lon']??0);
		$time=isset($pt->time)?(string)$pt->time:null;
		if (!$lat||!$lon||!$time) continue;
		$ins->execute([':trip_id'=>$trip_id,':lat'=>$lat,':lon'=>$lon,
			':ele'=>isset($pt->ele)?(float)$pt->ele:null,':recorded_at'=>$time,':source'=>'gpx']);
		$n++;
	}
	return $n;
}

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
	$R = 6371000;
	$phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
	$dphi = deg2rad($lat2 - $lat1);
	$dlam = deg2rad($lon2 - $lon1);
	$a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlam/2)**2;
	return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function compute_days(PDO $pdo, int $trip_id): int {
	$pdo->prepare('DELETE FROM trip_days WHERE trip_id=?')->execute([$trip_id]);

	// Fetch all points grouped by local date (Mountain Time)
	$stmt = $pdo->prepare("
		SELECT lat, lon, ele, recorded_at,
			   (recorded_at AT TIME ZONE 'America/Denver')::date AS local_date
		FROM trackpoints
		WHERE trip_id = :id AND source = 'gpx'
		ORDER BY recorded_at
	");
	$stmt->execute([':id' => $trip_id]);
	$allPoints = $stmt->fetchAll();

	// Group by local date
	$byDate = [];
	foreach ($allPoints as $p) {
		$byDate[$p['local_date']][] = $p;
	}
	ksort($byDate);

	$ins = $pdo->prepare('
		INSERT INTO trip_days (trip_id, day_number, date, point_count, gain_m, loss_m, distance_m, started_at, ended_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
	');

	$dayNumber = 1;
	foreach ($byDate as $date => $pts) {
		$gain = 0.0; $loss = 0.0; $dist = 0.0;
		$prevEle = null; $prevLat = null; $prevLon = null;

		foreach ($pts as $p) {
			$ele = $p['ele'] !== null ? (float)$p['ele'] : null;
			if ($ele !== null && $prevEle !== null) {
				$diff = $ele - $prevEle;
				if ($diff > 0) $gain += $diff;
				else           $loss += abs($diff);
			}
			$prevEle = $ele;
			if ($prevLat !== null) {
				$dist += haversine($prevLat, $prevLon, (float)$p['lat'], (float)$p['lon']);
			}
			$prevLat = (float)$p['lat'];
			$prevLon = (float)$p['lon'];
		}

		$ins->execute([
			$trip_id, $dayNumber, $date, count($pts),
			round($gain, 1), round($loss, 1), round($dist, 1),
			$pts[0]['recorded_at'], end($pts)['recorded_at'],
		]);

		$gainFt = round($gain * 3.28084);
		$lossFt = round($loss * 3.28084);
		$distMi = round($dist / 1609.34, 1);
		echo "  Day {$dayNumber} ({$date}): " . count($pts) . " pts, +{$gainFt}ft/-{$lossFt}ft, {$distMi}mi\n";
		$dayNumber++;
	}

	return count($byDate);
}

function generate_token(PDO $pdo): string {
	do {
		$t = substr(bin2hex(random_bytes(8)),0,12);
		$c = $pdo->prepare('SELECT 1 FROM trips WHERE token=?');
		$c->execute([$t]);
	} while ($c->fetchColumn());
	return $t;
}

$check  = $pdo->prepare('SELECT id FROM trips WHERE year=:year AND slug=:slug');
$insert = $pdo->prepare('INSERT INTO trips
	(slug,year,name,subtitle,started_at,ended_at,token,is_bcc,map_lat,map_lon,map_zoom)
	VALUES (:slug,:year,:name,:subtitle,:started_at,:ended_at,:token,:is_bcc,:map_lat,:map_lon,:map_zoom)');
$update = $pdo->prepare('UPDATE trips SET name=:name,subtitle=:subtitle,
	started_at=:started_at,ended_at=:ended_at,is_bcc=:is_bcc,
	map_lat=:map_lat,map_lon=:map_lon,map_zoom=:map_zoom WHERE id=:id');

foreach ($all_trips as [$year,$slug]) {
	$key = "{$year}/{$slug}";

	// Never touch the canonical scouts trip — it has manual corrections and photos
	if ($key === '2024/james-peak-scouts') {
		echo "\n── {$key} [SKIPPED — protected trip] ──\n";
		continue;
	}

	$is_bcc = in_array($key,$bcc_slugs);
	echo "\n── {$key} ".($is_bcc?'[BCC]':'[personal]')." ──\n";

	$html = fetch_url("https://backcountry.club/{$key}");
	if (!$html) { echo "  ✗ fetch failed\n"; continue; }

	$d = parse_trip_page($html);
	$name = $d['name'] ?: ucwords(str_replace('-',' ',$slug));
	echo "  Name:  {$name}\n";
	echo "  Sub:   ".($d['subtitle']??'—')."\n";
	echo "  Dates: ".($d['started_at']??'?')." → ".($d['ended_at']??'?')."\n";
	echo "  Map:   ".($d['map_lat']??'?').", ".($d['map_lon']??'?')." z".($d['map_zoom']??'?')."\n";

	$p = [':name'=>$name,':subtitle'=>$d['subtitle'],':started_at'=>$d['started_at'],
		  ':ended_at'=>$d['ended_at'],':is_bcc'=>$is_bcc?'t':'f',
		  ':map_lat'=>$d['map_lat'],':map_lon'=>$d['map_lon'],':map_zoom'=>$d['map_zoom']];

	$check->execute([':year'=>$year,':slug'=>$slug]);
	$existing = $check->fetchColumn();

	if ($existing) {
		$trip_id = (int)$existing;
		echo "  → Updating #{$trip_id}\n";
		$update->execute(array_merge($p,[':id'=>$trip_id]));
	} else {
		$token = generate_token($pdo);
		$insert->execute(array_merge($p,[':slug'=>$slug,':year'=>$year,':token'=>$token]));
		$trip_id = (int)$pdo->lastInsertId('trips_id_seq');
		echo "  → Created #{$trip_id} (token: {$token})\n";
	}

	$gpx = fetch_url("https://backcountry.club/api/gpx/{$key}");
	if ($gpx && str_contains($gpx,'<trkpt')) {
		$pts = import_gpx($pdo,$trip_id,$gpx);
		$days = compute_days($pdo,$trip_id);
		echo "  → {$pts} trackpoints, {$days} days\n";
	} else {
		echo "  → No GPX\n";
	}
}

// BCC 1 — manual, no GPX
echo "\n── 2016/indian-peaks-1 [BCC 1 — no GPX] ──\n";
$check->execute([':year'=>2016,':slug'=>'indian-peaks-1']);
if (!$check->fetchColumn()) {
	$token = generate_token($pdo);
	$insert->execute([':slug'=>'indian-peaks-1',':year'=>2016,':name'=>'Indian Peaks',
		':subtitle'=>'Indian Peaks Wilderness',':started_at'=>null,':ended_at'=>null,
		':token'=>$token,':is_bcc'=>'t',':map_lat'=>null,':map_lon'=>null,':map_zoom'=>null]);
	$id = (int)$pdo->lastInsertId('trips_id_seq');
	$pdo->prepare('UPDATE trips SET description=? WHERE id=?')->execute([
		'BCC 1. Jeff, Jace, and Chris. Missed a turn early on the first day and ended up doing an unplanned route. No GPX was recorded.',
		$id]);
	echo "  → Created #{$id} (token: {$token})\n";
} else {
	echo "  → Already exists\n";
}

echo "\n✓ Done\n";