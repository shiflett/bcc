<?php
// public/api/upload-photo.php
// Receives a single photo upload, extracts EXIF, resizes, inserts into media.

require_once __DIR__ . '/../../includes/db.php';

// Increase memory limit for image processing
ini_set('memory_limit', '256M');

// Buffer all output so stray PHP warnings don't corrupt the JSON response
ob_start();
header('Content-Type: application/json');

function fail(string $msg, int $code = 400): never {
	ob_end_clean();
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode(['ok' => false, 'error' => $msg]);
	exit;
}

// ----------------------------------------------------------------
// Validate request
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (empty($_FILES['photo'])) fail('No file uploaded');
if (empty($_POST['year']) || empty($_POST['slug'])) fail('Missing year/slug');

$year = (int)$_POST['year'];
$slug = preg_replace('/[^a-z0-9-]/', '', $_POST['slug']);

$file = $_FILES['photo'];
if ($file['error'] !== UPLOAD_ERR_OK) fail('Upload error: ' . $file['error']);

// ----------------------------------------------------------------
// Look up trip
// ----------------------------------------------------------------
$stmt = db()->prepare("SELECT * FROM trips WHERE year = ? AND slug = ?");
$stmt->execute([$year, $slug]);
$trip = $stmt->fetch();
if (!$trip) fail('Trip not found', 404);

// ----------------------------------------------------------------
// Validate file type
// ----------------------------------------------------------------
if (!function_exists('exif_read_data')) fail('EXIF extension not available');
if (!function_exists('imagecreatefromjpeg')) fail('GD extension not available');

$mime = mime_content_type($file['tmp_name']);
if (!in_array($mime, ['image/jpeg', 'image/jpg', 'image/heic', 'image/heif'])) fail('Only JPEG and HEIC supported');

// ----------------------------------------------------------------
// Convert HEIC to JPEG if needed
// HEIC files must be converted before EXIF reading (exif_read_data
// doesn't support HEIC) and before GD processing.
// Imagick preserves EXIF through the conversion.
// ----------------------------------------------------------------
$workingFile = $file['tmp_name'];
$heicTempFile = null;
$isHeic = in_array($mime, ['image/heic', 'image/heif']);

if ($isHeic) {
	if (!class_exists('Imagick')) fail('HEIC support requires Imagick extension');
	try {
		$im = new Imagick($file['tmp_name']);
		$im->setImageFormat('jpeg');
		$heicTempFile = tempnam(sys_get_temp_dir(), 'bcc_heic_') . '.jpg';
		$im->writeImage($heicTempFile);
		$im->destroy();
		$workingFile = $heicTempFile;
	} catch (Exception $e) {
		fail('Could not convert HEIC: ' . $e->getMessage());
	}
}

// ----------------------------------------------------------------
// Read EXIF
// ----------------------------------------------------------------
$exif = @exif_read_data($workingFile, null, true);

$takenAt = null;
$lat     = null;
$lon     = null;
$tier    = 4;

// Timestamp
if (!empty($exif['EXIF']['DateTimeOriginal'])) {
	$raw    = $exif['EXIF']['DateTimeOriginal'];
	// OffsetTimeOriginal is often absent from iPhone photos.
	// Fall back to a POST-supplied offset (e.g. '-06:00' for MDT, '-07:00' for MST),
	// then to '-06:00' as the default (Mountain Daylight Time, summer trips).
	$offset = $exif['EXIF']['OffsetTimeOriginal']
		   ?? ($_POST['tz_offset'] ?? '-06:00');
	try {
		$dt      = new DateTimeImmutable(
			str_replace(':', '-', substr($raw, 0, 10)) . ' ' . substr($raw, 11) . $offset
		);
		$takenAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
	} catch (Exception $e) { /* leave null */ }
}

// GPS
if (!empty($exif['GPS'])) {
	$gps = $exif['GPS'];

	function gps_to_decimal(array $parts, string $hem): ?float {
		if (count($parts) < 3) return null;
		$val = gps_frac($parts[0]) + gps_frac($parts[1]) / 60 + gps_frac($parts[2]) / 3600;
		return ($hem === 'S' || $hem === 'W') ? -$val : $val;
	}

	function gps_frac(string $frac): float {
		if (str_contains($frac, '/')) {
			[$n, $d] = explode('/', $frac);
			return $d ? (float)$n / (float)$d : 0.0;
		}
		return (float)$frac;
	}

	if (!empty($gps['GPSLatitude']) && !empty($gps['GPSLongitude'])) {
		$lat = gps_to_decimal($gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N');
		$lon = gps_to_decimal($gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E');
		if ($lat && $lon) $tier = 1;
	}
}

// ----------------------------------------------------------------
// GPX interpolation (tier 2) — no GPS but have a timestamp
// ----------------------------------------------------------------
if ($tier === 4 && $takenAt) {
	$interp = db()->prepare("
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
	$interp->execute([
		'trip_id' => $trip['id'],
		'ts'  => $takenAt,
		'ts2' => $takenAt,
		'ts3' => $takenAt,
		'ts4' => $takenAt,
	]);
	$nearby = $interp->fetch();
	if ($nearby) {
		$lat  = (float)$nearby['lat'];
		$lon  = (float)$nearby['lon'];
		$tier = 2;
	}
}

// ----------------------------------------------------------------
// Load image and apply EXIF orientation
// ----------------------------------------------------------------
$src = @imagecreatefromjpeg($workingFile);
if (!$src) fail('Could not decode image — file may be corrupt or not a supported JPEG');

[$origW, $origH] = getimagesize($workingFile);

// Rotate to match EXIF orientation so pixels match display intent
$orientation = (int)(is_array($exif) ? ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1) : 1);
if ($orientation !== 1) {
	$rotated = match($orientation) {
		3 => imagerotate($src, 180, 0),
		6 => imagerotate($src, -90, 0),
		8 => imagerotate($src,  90, 0),
		default => null,
	};
	if ($rotated) {
		imagedestroy($src);
		$src = $rotated;
		// Swap stored dimensions for 90/270 rotations
		if (in_array($orientation, [6, 8])) [$origW, $origH] = [$origH, $origW];
	}
}

// Clean up HEIC temp file — no longer needed
if ($heicTempFile && file_exists($heicTempFile)) unlink($heicTempFile);

// ----------------------------------------------------------------
// Resize and save
// ----------------------------------------------------------------
$uploadDir = __DIR__ . '/../uploads/' . $trip['id'];
$thumbDir  = $uploadDir . '/thumbs';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
if (!is_dir($thumbDir))  mkdir($thumbDir,  0755, true);

$baseName = $takenAt
	? preg_replace('/[^0-9]/', '', $takenAt) . '_' . substr(md5($file['name']), 0, 6)
	: bin2hex(random_bytes(8));
$filename = $baseName . '.jpg';

function save_resized(\GdImage $src, int $w, int $h, int $maxPx, string $dest): void {
	$ratio = min($maxPx / $w, $maxPx / $h, 1.0);
	$newW  = (int)round($w * $ratio);
	$newH  = (int)round($h * $ratio);
	$dst   = imagecreatetruecolor($newW, $newH);
	imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
	imagejpeg($dst, $dest, 85);
	imagedestroy($dst);
}

save_resized($src, $origW, $origH, 1800, $uploadDir . '/' . $filename);
save_resized($src, $origW, $origH,  900, $thumbDir  . '/' . $filename);
imagedestroy($src);

// ----------------------------------------------------------------
// Resolve day number — create trip_day if none exists for this date
// This handles photos-only trips where trip_days are derived from photos,
// not from a GPX track.
// ----------------------------------------------------------------
$dayNum = null;
if ($takenAt) {
	$localDate = (new DateTimeImmutable($takenAt))
		->setTimezone(new DateTimeZone('America/Denver'))
		->format('Y-m-d');

	// Look for an existing trip_day for this date
	$dayStmt = db()->prepare("
		SELECT day_number FROM trip_days
		WHERE trip_id = ? AND date = ?
		LIMIT 1
	");
	$dayStmt->execute([$trip['id'], $localDate]);
	$dayRow = $dayStmt->fetch();

	if ($dayRow) {
		$dayNum = (int)$dayRow['day_number'];
		// Extend ended_at if this photo is later than the current day end
		db()->prepare("
			UPDATE trip_days
			SET ended_at = GREATEST(ended_at::timestamptz, ?::timestamptz)
			WHERE trip_id = ? AND day_number = ?
		")->execute([$takenAt, $trip['id'], $dayNum]);
	} else {
		// No day for this date yet — create one
		$maxStmt = db()->prepare("
			SELECT COALESCE(MAX(day_number), 0) FROM trip_days WHERE trip_id = ?
		");
		$maxStmt->execute([$trip['id']]);
		$dayNum = (int)$maxStmt->fetchColumn() + 1;

		db()->prepare("
			INSERT INTO trip_days (trip_id, day_number, date, point_count, started_at, ended_at)
			VALUES (?, ?, ?, 0, ?, ?)
		")->execute([$trip['id'], $dayNum, $localDate, $takenAt, $takenAt]);

		// Expand trip started_at/ended_at to cover this date
		db()->prepare("
			UPDATE trips SET
				started_at = LEAST(COALESCE(started_at::date, ?::date), ?::date),
				ended_at   = GREATEST(COALESCE(ended_at::date, ?::date), ?::date)
			WHERE id = ?
		")->execute([$localDate, $localDate, $localDate, $localDate, $trip['id']]);
	}
}

// ----------------------------------------------------------------
// Insert into media table
// ----------------------------------------------------------------
$insertStmt = db()->prepare("
	INSERT INTO media (trip_id, day_number, kind, filename, taken_at, lat, lon, placement_tier, width, height)
	VALUES (:trip_id, :day_number, 'photo', :filename, :taken_at, :lat, :lon, :tier, :width, :height)
	RETURNING id
");
$insertStmt->execute([
	'trip_id'    => $trip['id'],
	'day_number' => $dayNum,
	'filename'   => $filename,
	'taken_at'   => $takenAt,
	'lat'        => $lat,
	'lon'        => $lon,
	'tier'       => $tier,
	'width'      => $origW,
	'height'     => $origH,
]);
$mediaId = $insertStmt->fetchColumn();

ob_end_clean();
echo json_encode([
	'ok'       => true,
	'id'       => $mediaId,
	'filename' => $filename,
	'tier'     => $tier,
	'lat'      => $lat,
	'lon'      => $lon,
	'taken_at' => $takenAt,
	'day'      => $dayNum,
]);