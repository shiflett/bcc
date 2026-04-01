<?php
/**
 * Trip page — /trips/{year}/{slug}/ or /{year}/{slug}/
 *
 * Left panel:  trip header + day cards (index, designed to be clicked)
 * Right panel: ambient map — full trip tracks dim white, current day animates
 *              red from scratch; resets to white when next day starts.
 *              No coupling between left and right.
 *
 * PHP also fetches the first photo per day for the hero thumbnail on each card.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';

$is_admin = is_admin();

$year = isset($year) ? (int)$year : (int)($_GET['year'] ?? 0);
$slug = isset($slug) ? $slug      : ($_GET['slug'] ?? '');

if (!$year || !$slug) { http_response_code(404); exit('Not found'); }

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM trips WHERE year = :year AND slug = :slug LIMIT 1');
$stmt->execute([':year' => $year, ':slug' => $slug]);
$trip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$trip) { http_response_code(404); exit('Trip not found'); }

$stmt = $pdo->prepare('
    SELECT td.*, COUNT(m.id) AS photo_count
    FROM trip_days td
    LEFT JOIN media m ON m.trip_id = td.trip_id AND m.day_number = td.day_number
    WHERE td.trip_id = :trip_id
    GROUP BY td.id
    ORDER BY td.day_number
');
$stmt->execute([':trip_id' => $trip['id']]);
$days = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($days)) { http_response_code(404); exit('No days found'); }

// Fetch trackpoints per day for Mapbox Static Images API
// We simplify the track to stay within URL length limits (~8000 chars)
$track_stmt = $pdo->prepare('
    SELECT lat, lon FROM trackpoints
    WHERE trip_id = :trip_id
      AND (recorded_at AT TIME ZONE \'America/Denver\')::date
        = (:date::date)
    ORDER BY recorded_at ASC
');
$mapbox_token_raw = getenv('BCC_MAPBOX') ?: '';  // raw string for PHP URL construction
$mapbox_token     = json_encode($mapbox_token_raw); // JSON-encoded for JS constant

function simplify_track(array $pts, int $max): array {
    if (count($pts) <= $max) return $pts;
    $step = (count($pts) - 1) / ($max - 1);
    $out  = [];
    for ($i = 0; $i < $max; $i++) $out[] = $pts[(int)round($i * $step)];
    return $out;
}

function static_map_url(array $pts, string $token, int $w = 560, int $h = 280, string $bbox = ''): string {
    if (empty($pts)) return '';
    $coords = array_map(fn($p) => [(float)$p['lon'], (float)$p['lat']], $pts);
    $geojson = json_encode([
        'type'       => 'Feature',
        'properties' => [
            'stroke'         => '#E4572E',
            'stroke-width'   => 3,
            'stroke-opacity' => 0.9,
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
    ]);
    $overlay = 'geojson(' . rawurlencode($geojson) . ')';

    if (!$bbox) {
        $lats = array_column($pts, 'lat');
        $lons = array_column($pts, 'lon');
        $bbox = '[' . min($lons) . ',' . min($lats) . ',' . max($lons) . ',' . max($lats) . ']';
    }

    return "https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/{$overlay}/{$bbox}/{$w}x{$h}@2x?padding=20&access_token={$token}";
}

// Collect all trip trackpoints once, compute shared bbox
$all_trip_pts = [];
foreach ($days as $day) {
    $track_stmt->execute([':trip_id' => $trip['id'], ':date' => $day['date']]);
    $all_trip_pts = array_merge($all_trip_pts, $track_stmt->fetchAll(PDO::FETCH_ASSOC));
}

$trip_bbox = '';
if (!empty($all_trip_pts)) {
    $lats = array_column($all_trip_pts, 'lat');
    $lons = array_column($all_trip_pts, 'lon');
    $trip_bbox = '[' . min($lons) . ',' . min($lats) . ',' . max($lons) . ',' . max($lats) . ']';
}

// Overview: full trip at 2:1 (560×280), shared bbox
$overview_map_url = $trip_bbox
    ? static_map_url(simplify_track($all_trip_pts, 150), $mapbox_token_raw, 560, 280, $trip_bbox)
    : '';

// Day cards: each day's segment, framed in the full trip bbox, 2:1 (360×180)
// Also track whether each day has any trackpoints (drives CTA logic)
$day_static_maps = [];
$day_has_track   = [];
foreach ($days as $day) {
    $track_stmt->execute([':trip_id' => $trip['id'], ':date' => $day['date']]);
    $all_pts = $track_stmt->fetchAll(PDO::FETCH_ASSOC);
    $dn = (int)$day['day_number'];
    $day_has_track[$dn] = !empty($all_pts);
    $pts = simplify_track($all_pts, 60);
    $day_static_maps[$dn] = $pts
        ? static_map_url($pts, $mapbox_token_raw, 360, 180, $trip_bbox)
        : '';
}

$total_gain_m = array_sum(array_column($days, 'gain_m'));
$total_loss_m = array_sum(array_column($days, 'loss_m'));
$total_dist_m = array_sum(array_column($days, 'distance_m'));

// Fetch evenly-sampled photo thumbnails per day for the strip
// Only fetched for days with >= PHOTO_THRESHOLD photos
$photo_threshold  = 8;
$photo_strip_count = 15; // shown before "+N more"

$strip_stmt = $pdo->prepare('
    SELECT filename FROM media
    WHERE trip_id = :trip_id AND day_number = :day_number AND placement_tier <= 2
    ORDER BY taken_at ASC
');
$day_strip_photos = [];
foreach ($days as $day) {
    $dn = (int)$day['day_number'];
    if ((int)$day['photo_count'] < $photo_threshold) {
        $day_strip_photos[$dn] = [];
        continue;
    }
    $strip_stmt->execute([':trip_id' => $trip['id'], ':day_number' => $dn]);
    $all = $strip_stmt->fetchAll(PDO::FETCH_COLUMN);
    // Evenly sample $photo_strip_count from the full set
    $max  = $photo_strip_count;
    $n    = count($all);
    $step = ($n - 1) / ($max - 1);
    $sampled = [];
    for ($i = 0; $i < $max && $i < $n; $i++) $sampled[] = $all[(int)round($i * $step)];
    $day_strip_photos[$dn] = $sampled;
}

function format_date_range(string $start, string $end): string {
    $s = new DateTime($start); $e = new DateTime($end);
    if ($s->format('MY') === $e->format('MY'))
        return $s->format('M j') . '–' . $e->format('j, Y');
    return $s->format('M j') . '–' . $e->format('M j, Y');
}
$date_range = format_date_range($trip['started_at'], $trip['ended_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($trip['name']) ?> — Backcountry Club</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
:root {
    --day-bg:         #f5f3ee;
    --day-bg-col:     #efece6;
    --day-border:     #ddd9cf;
    --day-text:       #1a1916;
    --day-text-muted: #6b6860;
    --day-text-faint: #a8a499;
    --day-red:        #E4572E;
    --day-topbar-h:   52px;
    --panel-w:        50vw;
    --story-pad:      36px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    height: 100%;
    background: var(--day-bg);
    color: var(--day-text);
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    line-height: 1.5;
    overflow: hidden;
}

/* ── Topbar — exact match of day.php ── */
#topbar {
    position: fixed; top: 0; left: 0; right: 0;
    z-index: 200;
    background: var(--day-bg);
    border-bottom: 1px solid var(--day-border);
    display: flex;
    align-items: center;
    padding: 0 20px 0 24px;
    height: var(--day-topbar-h);
}
.topbar__nav {
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: 'JetBrains Mono', 'Courier New', monospace;
    font-size: 1rem;
    white-space: nowrap;
    flex-shrink: 0;
}
.topbar__trip {
    color: var(--day-text-faint);
    text-decoration: none;
    transition: color 0.15s;
    display: flex; align-items: center;
}
.topbar__trip:hover { color: var(--day-red); }
.topbar__sep { color: var(--day-border); }
.topbar__current {
    color: var(--day-text);
    font-weight: 600;
}

/* ── Layout ── */
#layout {
    position: fixed; top: var(--day-topbar-h); left: 0; right: 0; bottom: 0;
    display: flex;
}

/* ── Story panel (left) ── */
#story {
    width: var(--panel-w); height: 100%;
    background: var(--day-bg-col); border-right: 1px solid var(--day-border);
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--day-border) transparent;
}

/* Trip header */
#trip-header {
    padding: 28px var(--story-pad) 20px;
    border-bottom: 1px solid var(--day-border);
}
#trip-title {
    font-size: 1.5rem; font-weight: 700;
    letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 4px;
}
#trip-subtitle {
    color: var(--day-text-muted); font-size: 12px; margin-bottom: 12px;
}
.meta-sep { color: var(--day-border); }
#trip-stats {
    display: flex; gap: 0;
}
.trip-stat {
    display: flex; flex-direction: column; gap: 2px;
    padding-right: 18px; margin-right: 18px;
    border-right: 1px solid var(--day-border);
}
.trip-stat:last-child { border-right: none; padding-right: 0; margin-right: 0; }
.trip-stat-label {
    font-size: 9px; font-weight: 600; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--day-text-faint);
}
.trip-stat-value { font-size: 1rem; font-weight: 700; }

/* ── Trip overview map ── */
#trip-overview-map {
    margin: 0 0 20px;
    border-radius: 3px;
    overflow: hidden;
    border: 1px solid var(--day-border);
}
#trip-overview-map-label {
    background: #1a1916;
    padding: 9px 14px;
    display: flex; align-items: baseline; gap: 10px;
}
#trip-overview-map-label .oml-title {
    font-size: 11px; font-weight: 700; letter-spacing: 0.06em;
    text-transform: uppercase; color: #fff;
}
#trip-overview-map-label .oml-meta {
    font-size: 11px; font-weight: 500;
    color: rgba(255,255,255,0.45);
}
#trip-overview-map img {
    width: 100%; height: 280px;
    object-fit: cover; display: block;
}

/* ── Day cards list ── */
#days-list {
    padding: 28px var(--story-pad) 28px;
    display: flex; flex-direction: column; gap: 20px;
}

/* ── Track-only day text row (no photos, no card) ── */
.day-text-row {
    padding: 14px 0;
    border-bottom: 1px solid var(--day-border);
}
.day-text-row:last-child { border-bottom: none; }
.day-text-row-header {
    display: flex; align-items: baseline; gap: 10px;
    margin-bottom: 5px;
}
.day-text-row-name {
    font-size: 13px; font-weight: 700; color: var(--day-text);
}
.day-text-row-sep { color: var(--day-border); }
.day-text-row-date {
    font-size: 12px; font-weight: 600; color: var(--day-text-muted);
}
.day-text-row-stats {
    font-size: 11px; font-weight: 500; color: var(--day-text-faint);
    display: flex; gap: 16px;
}

/* ── Day card ── */
.day-card {
    display: block; text-decoration: none; color: inherit;
    border: 1px solid var(--day-border);
    border-radius: 3px;
    overflow: hidden;
    flex-shrink: 0;
    background: var(--day-bg);
}
/* Clickable variant — trips with photos */
.day-card--link {
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.day-card--link:hover {
    border-color: var(--day-text-faint);
    box-shadow: 0 3px 14px rgba(26,25,22,0.09);
}

/* Static map image area — flex row: dark label left, map image right */
.day-card-map {
    width: 100%; height: 180px;
    display: flex; overflow: hidden;
    background: var(--day-bg-col);
}
.day-card-label {
    width: 140px; flex-shrink: 0;
    background: #1a1916;
    display: flex; flex-direction: column; justify-content: center;
    padding: 16px 18px;
    border-right: 1px solid rgba(255,255,255,0.06);
}
.day-card-n-day {
    font-size: 2rem; font-weight: 700; line-height: 1;
    letter-spacing: -0.04em; color: #fff;
}
.day-card-n-date {
    font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.5);
    margin-top: 6px;
}
.day-card-map-img {
    flex: 1; overflow: hidden;
}
.day-card-map-img img {
    width: 100%; height: 100%;
    object-fit: cover; display: block;
    transition: transform 0.4s ease;
}
.day-card--link:hover .day-card-map-img img { transform: scale(1.02); }

/* No-map placeholder */
.day-card-nomap {
    width: 100%; height: 180px;
    background: #1a1916;
    display: flex; align-items: center;
    padding: 0 22px;
}

/* Card footer — stats + CTA — this is the action row */
/* ── Card footer — two modes ── */
/* Shared: no border-top — map/photos flow directly into footer */
.day-card-footer {
    background: var(--day-bg);
}

/* Stats mode (few photos) */
.day-card-footer-stats {
    display: flex; align-items: stretch;
    height: 40px;
}
.day-card-stats {
    display: flex; align-items: center; gap: 14px;
    flex: 1; padding: 0 0 0 18px;
    font-size: 11px; font-weight: 500; color: var(--day-text-muted);
}
.cs-arrow { color: var(--day-text-faint); margin-right: 1px; }

/* Photo strip mode (many photos) */
.day-card-footer-strip {
    display: flex;
    align-items: stretch;
    height: 40px;
}
.day-card-strip-photos {
    display: flex;
    flex: 1;
    overflow: hidden;
    position: relative;
    min-width: 0;
}
.day-card-strip-photos img {
    width: 40px; height: 40px;
    object-fit: cover; display: block;
    flex-shrink: 0;
}
.day-card-more {
    flex-shrink: 0;
    display: flex; align-items: center;
    padding: 0 12px;
    font-size: 11px; font-weight: 700;
    color: var(--day-text-muted);
    letter-spacing: 0.04em;
    white-space: nowrap;
    background: var(--day-bg);
    border-left: 1px solid var(--day-border);
}
.day-card-cta {
    flex-shrink: 0;
    display: flex; align-items: center;
    padding: 0 18px;
    border-left: 1px solid var(--day-border);
    font-size: 11px; font-weight: 700; letter-spacing: 0.06em;
    color: var(--day-red); text-transform: uppercase;
    text-decoration: none; white-space: nowrap;
    transition: opacity 0.2s;
}
.day-card:hover .day-card-cta { opacity: 0.75; }

/* ── Map panel (right) ── */
#map-panel { flex: 1; position: relative; }
#map { position: absolute; inset: 0; }

/* ── Play/pause control ── */
.leaflet-control-playpause {
    box-shadow: 0 1px 5px rgba(0,0,0,0.4);
}
.leaflet-control-playpause a,
.leaflet-touch .leaflet-control-playpause a {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 30px !important;
    height: 30px !important;
    line-height: 1 !important;
    text-decoration: none;
    background: #fff !important;
    color: #444;
    font-size: 15px;
    border-radius: 4px !important;
    border: 2px solid rgba(0,0,0,0.2) !important;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;
    box-sizing: border-box !important;
    padding: 0 !important;
}
.leaflet-control-playpause a:hover,
.leaflet-touch .leaflet-control-playpause a:hover { background: #f4f4f4 !important; }
.leaflet-control-playpause a.paused,
.leaflet-touch .leaflet-control-playpause a.paused {
    color: var(--day-red);
    border-color: rgba(228,87,46,0.4) !important;
}

/* ── Position marker ── */
.pos-marker {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--day-red);
    border: 2.5px solid white;
    box-shadow: 0 0 0 1.5px var(--day-red), 0 1px 4px rgba(0,0,0,0.3);
}

.leaflet-container { background: #e8e4db; }

/* ── Photo upload zone (admin only) ── */
#upload-zone {
    margin: 0 var(--story-pad) 28px;
    border: 1.5px dashed var(--day-border);
    border-radius: 3px;
    padding: 16px 18px;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    display: flex; align-items: center; gap: 10px;
}
#upload-zone:hover,
#upload-zone.drag-over {
    border-color: var(--day-text-faint);
    background: rgba(26,25,22,0.02);
}
#upload-zone-label {
    font-size: 11px; font-weight: 600; letter-spacing: 0.06em;
    text-transform: uppercase; color: var(--day-text-faint);
}
#upload-input { display: none; }

.upload-queue {
    margin: 0 var(--story-pad) 8px;
    display: flex; flex-direction: column; gap: 3px;
}
.upload-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 11px; color: var(--day-text-muted);
    padding: 4px 0;
}
.upload-item__name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.upload-item__status { flex-shrink: 0; color: var(--day-text-faint); }
.upload-item--done .upload-item__status { color: #5a8a5a; }
.upload-item--error .upload-item__status { color: var(--day-red); }
</style>
</head>
<body>

<div id="topbar">
    <nav class="topbar__nav">
        <a href="/" class="topbar__trip">
            <svg width="14" height="10" viewBox="0 0 14 10" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:1px;margin-right:6px;flex-shrink:0"><path d="M13 5H1M1 5L5 1M1 5L5 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>All Trips
        </a>
        <span class="topbar__sep">·</span>
        <span class="topbar__current"><?= htmlspecialchars($trip['name']) ?></span>
    </nav>
</div>

<div id="layout">

    <!-- Left: index -->
    <div id="story">

        <div id="trip-header">
            <h1 id="trip-title"><?= htmlspecialchars($trip['name']) ?></h1>
            <?php if (!empty($trip['subtitle'])): ?>
            <p id="trip-subtitle"><?= htmlspecialchars($trip['subtitle']) ?></p>
            <?php endif; ?>

            <?php if ($overview_map_url): ?>
            <div id="trip-overview-map">
                <div id="trip-overview-map-label">
                    <span class="oml-title">Trip Overview</span>
                    <span class="oml-meta"><?= $date_range ?> · <?= count($days) ?> day<?= count($days) !== 1 ? 's' : '' ?></span>
                </div>
                <img src="<?= htmlspecialchars($overview_map_url) ?>"
                     alt="<?= htmlspecialchars($trip['name']) ?> overview"
                     loading="eager" decoding="async">
            </div>
            <?php endif; ?>

            <div id="trip-stats">
                <div class="trip-stat">
                    <span class="trip-stat-label">Gain</span>
                    <span class="trip-stat-value">↑ <?= fmt_ele($total_gain_m) ?></span>
                </div>
                <div class="trip-stat">
                    <span class="trip-stat-label">Loss</span>
                    <span class="trip-stat-value">↓ <?= fmt_ele($total_loss_m) ?></span>
                </div>
                <div class="trip-stat">
                    <span class="trip-stat-label">Distance</span>
                    <span class="trip-stat-value"><?= fmt_dist($total_dist_m) ?></span>
                </div>
            </div>
        </div>

        <div id="days-list">
<?php foreach ($days as $i => $day):
    $dn         = (int)$day['day_number'];
    $day_date   = (new DateTime($day['date']))->format('D, M j');
    $day_url    = "/{$year}/{$slug}/day/{$dn}";
    $map_url    = $day_static_maps[$dn] ?? '';
    $has_track  = $day_has_track[$dn] ?? false;
    $has_photos = (int)$day['photo_count'] > 0;
    $cta_label  = $has_track ? "Experience Day {$dn} →" : "View Day {$dn} Photos →";

    if (!$has_photos):
        // Track-only day — plain text row, no card
?>
            <div class="day-text-row">
                <div class="day-text-row-header">
                    <span class="day-text-row-name">Day <?= $dn ?></span>
                    <span class="day-text-row-sep">·</span>
                    <span class="day-text-row-date"><?= $day_date ?></span>
                </div>
                <div class="day-text-row-stats">
                    <?php if ($day['gain_m'] !== null): ?><span>↑ <?= fmt_ele($day['gain_m']) ?></span><?php endif; ?>
                    <?php if ($day['loss_m'] !== null): ?><span>↓ <?= fmt_ele($day['loss_m']) ?></span><?php endif; ?>
                    <?php if ($day['distance_m'] !== null): ?><span><?= fmt_dist($day['distance_m']) ?></span><?php endif; ?>
                </div>
            </div>

<?php else:
        // Day with photos — full card, always clickable
?>
            <div class="day-card day-card--link" data-href="<?= $day_url ?>" role="link" tabindex="0">
                <?php if ($map_url): ?>
                <div class="day-card-map">
                    <div class="day-card-label">
                        <span class="day-card-n-day">Day <?= $dn ?></span>
                        <span class="day-card-n-date"><?= $day_date ?></span>
                    </div>
                    <div class="day-card-map-img">
                        <img src="<?= htmlspecialchars($map_url) ?>"
                             alt="Day <?= $dn ?> track"
                             loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
                             decoding="async">
                    </div>
                </div>
                <?php else: ?>
                <div class="day-card-nomap">
                    <div>
                        <span class="day-card-n-day">Day <?= $dn ?></span>
                        <div class="day-card-n-date" style="margin-top:5px"><?= $day_date ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="day-card-footer">
<?php
    $strip_photos = $day_strip_photos[$dn] ?? [];
    $photo_count  = (int)$day['photo_count'];
    $remaining    = $photo_count - count($strip_photos);
    if ($strip_photos):
?>
                    <!-- Photo strip mode -->
                    <div class="day-card-footer-strip">
                        <div class="day-card-strip-photos">
                            <?php foreach ($strip_photos as $filename): ?>
                            <img src="/uploads/<?= (int)$trip['id'] ?>/thumbs/<?= htmlspecialchars($filename) ?>"
                                 alt="" loading="lazy" decoding="async">
                            <?php endforeach; ?>
                        </div>
                        <?php if ($remaining > 0): ?>
                        <div class="day-card-more">+<?= $remaining ?></div>
                        <?php endif; ?>
                        <a class="day-card-cta" href="<?= $day_url ?>"><?= $cta_label ?></a>
                    </div>
<?php else: ?>
                    <!-- Stats mode -->
                    <div class="day-card-footer-stats">
                        <div class="day-card-stats">
                            <span><span class="cs-arrow">↑</span><?= $day['gain_m'] !== null ? fmt_ele($day['gain_m']) : '—' ?></span>
                            <span><span class="cs-arrow">↓</span><?= $day['loss_m'] !== null ? fmt_ele($day['loss_m']) : '—' ?></span>
                            <span><?= $day['distance_m'] !== null ? fmt_dist($day['distance_m']) : '—' ?></span>
                        </div>
                        <a class="day-card-cta" href="<?= $day_url ?>"><?= $cta_label ?></a>
                    </div>
<?php endif; ?>
                </div><!-- /day-card-footer -->
            </div><!-- /day-card -->
<?php endif; ?>
<?php endforeach; ?>
        </div>

<?php if ($is_admin): ?>
        <!-- Photo upload zone — visible to admin only -->
        <div id="upload-zone" title="Drop photos or click to upload">
            <span style="font-size:1rem; opacity:0.4">⛰</span>
            <span id="upload-zone-label">Drop photos or click to upload</span>
            <input type="file" id="upload-input" multiple accept="image/jpeg">
        </div>
        <div class="upload-queue" id="upload-queue"></div>
        <?php endif; ?>

    </div><!-- /story -->

    <!-- Right: ambient map -->
    <div id="map-panel">
        <div id="map"></div>
    </div><!-- /layout -->

<script>
const TRIP_YEAR = <?= (int)$year ?>;
const TRIP_SLUG = <?= json_encode($slug) ?>;
const DAYS_META = <?= json_encode(array_map(fn($d) => [
    'day_number' => (int)$d['day_number'],
], $days)) ?>;

// Speed-based timing
const ANIM_SPEED_MPS = 270;
const LOOP_MS        = 3500;

// ── Map ───────────────────────────────────────────────────────────────────

const map = L.map('map', { zoomControl: false, attributionControl: false, scrollWheelZoom: true });

// Token matches day.php exactly
const MAPBOX_TOKEN = <?= json_encode(getenv('BCC_MAPBOX') ?: '') ?>;

L.tileLayer(
    `https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/256/{z}/{x}/{y}@2x?access_token=${MAPBOX_TOKEN}`,
    { attribution: '© Mapbox © OpenStreetMap', maxZoom: 18, tileSize: 256 }
).addTo(map);
map.setView([39.9, -105.65], 12);

let animLine        = null;
let posMarker       = null;
let mapFitted       = false;
let dayTracks  = [];
let activeDayIndex  = 0;
let animHandle      = null;
let animStartTs     = null;
let distanceCovered = 0;   // metres covered in current day — persists across pause/resume
let isPlaying       = true;

// ── Fetch all tracks ────────────────────────────────────────────────────
async function fetchTracks() {
    dayTracks = await Promise.all(
        DAYS_META.map(d =>
            fetch(`/api/points/${TRIP_YEAR}/${TRIP_SLUG}/${d.day_number}`)
                .then(r => r.ok ? r.json() : [])
                .then(pts => ({ dayIndex: d.day_number - 1, points: pts }))
        )
    );
    initMapLayers();
    animHandle = requestAnimationFrame(animTick);
}

// ── Map layers ──────────────────────────────────────────────────────────
function initMapLayers() {
    let allLL = [];
    dayTracks.forEach(dt => {
        if (!dt.points.length) return;
        const lls = dt.points.map(p => [p.lat, p.lon]);
        allLL = allLL.concat(lls);
    });
    if (!allLL.length) return;
    map.fitBounds(L.latLngBounds(allLL), { animate: false, padding: [32, 32] });
    map.setZoom(15, { animate: false });

    // Red animated line
    animLine = L.polyline([], {
        color: '#E4572E', weight: 4.5, opacity: 0.9,
        lineCap: 'round', lineJoin: 'round', interactive: false,
    }).addTo(map);

    // Position marker — matches day.php exactly, sits on top of everything
    const posIcon = L.divIcon({
        className: '',
        html: '<div class="pos-marker"></div>',
        iconSize: [12, 12],
        iconAnchor: [6, 6],
    });
    posMarker = L.marker([allLL[0][0], allLL[0][1]], {
        icon: posIcon, interactive: false, zIndexOffset: 1000,
    }).addTo(map);

    mapFitted = true;

    // Precompute cumulative distances for each day — used by distance-based animation
    dayTracks.forEach(dt => {
        const dists = [0];
        for (let i = 1; i < dt.points.length; i++) {
            const p  = dt.points[i], pp = dt.points[i - 1];
            const dlat = (p.lat - pp.lat) * 111139;
            const dlon = (p.lon - pp.lon) * 111139 * Math.cos(pp.lat * Math.PI / 180);
            dists.push(dists[i - 1] + Math.sqrt(dlat * dlat + dlon * dlon));
        }
        dt.dists     = dists;
        dt.totalDist = dists[dists.length - 1] || 1;
    });
}

// ── Animation loop ───────────────────────────────────────────────────────
// Speed-based: the line draws at ANIM_SPEED_MPS metres per second regardless
// of how long a day is. Longer days take more time — that's intentional.
// distanceCovered persists across pause/resume so playback continues from
// exactly where it stopped.

function animTick(ts) {
    if (!isPlaying) return;

    const dt = dayTracks[activeDayIndex];
    if (!dt || !dt.totalDist) { animHandle = requestAnimationFrame(animTick); return; }

    if (animStartTs === null) {
        // Set start time such that we'd already be at distanceCovered
        animStartTs = ts - (distanceCovered / ANIM_SPEED_MPS) * 1000;
    }

    const elapsed     = ts - animStartTs;
    const targetDist  = Math.min((elapsed / 1000) * ANIM_SPEED_MPS, dt.totalDist);
    distanceCovered   = targetDist;
    const done        = targetDist >= dt.totalDist;

    renderDay(activeDayIndex, targetDist);

    if (!done) {
        animHandle = requestAnimationFrame(animTick);
    } else {
        const next = activeDayIndex + 1;
        if (next < dayTracks.length) {
            // No pause between days — immediately start next
            animLine.setLatLngs([]);
            activeDayIndex  = next;
            animStartTs     = null;
            distanceCovered = 0;
            // Move marker to start of next day
            const nextPts = dayTracks[next]?.points;
            if (posMarker && nextPts?.length) posMarker.setLatLng([nextPts[0].lat, nextPts[0].lon]);
            animHandle      = requestAnimationFrame(animTick);
        } else {
            // All days done — brief pause then loop
            animHandle = setTimeout(() => {
                animLine.setLatLngs([]);
                activeDayIndex  = 0;
                animStartTs     = null;
                distanceCovered = 0;
                // Move marker back to start of day 1
                const firstPts = dayTracks[0]?.points;
                if (posMarker && firstPts?.length) posMarker.setLatLng([firstPts[0].lat, firstPts[0].lon]);
                animHandle      = requestAnimationFrame(animTick);
            }, LOOP_MS);
        }
    }
}

// targetDist: metres along this day's track to draw to
function renderDay(dayIndex, targetDist) {
    const dt = dayTracks[dayIndex];
    if (!dt || !dt.points.length) return;

    const pts   = dt.points;
    const dists = dt.dists;

    // Binary search for the last point at or before targetDist
    let lo = 0, hi = pts.length - 1;
    while (lo < hi) {
        const mid = (lo + hi + 1) >> 1;
        if (dists[mid] <= targetDist) lo = mid; else hi = mid - 1;
    }
    const endI = lo;

    const redPts = [];
    for (let i = 0; i <= endI; i++) {
        redPts.push([pts[i].lat, pts[i].lon]);
    }

    // Interpolated tip for smooth sub-segment motion
    let tip = null;
    if (endI < pts.length - 1) {
        const segStart = dists[endI];
        const segEnd   = dists[endI + 1];
        const frac     = segEnd > segStart ? (targetDist - segStart) / (segEnd - segStart) : 0;
        const p0 = pts[endI], p1 = pts[endI + 1];
        const lat = p0.lat + frac * (p1.lat - p0.lat);
        const lon = p0.lon + frac * (p1.lon - p0.lon);
        redPts.push([lat, lon]);
        tip = [lat, lon];
    } else {
        tip = [pts[endI].lat, pts[endI].lon];
    }

    animLine.setLatLngs(redPts);

    if (tip) {
        if (posMarker) posMarker.setLatLng(tip);
        if (mapFitted) map.panTo(tip, { animate: true, duration: 1.0, easeLinearity: 0.4 });
    }
}

let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (mapFitted) map.invalidateSize(); }, 150);
});

// ── Play/pause Leaflet control ────────────────────────────────────────────
// Styled to match Leaflet's zoom buttons — sits in top-left corner
const PlayPauseControl = L.Control.extend({
    options: { position: 'topleft' },
    onAdd() {
        const container = L.DomUtil.create('div', 'leaflet-control-playpause leaflet-bar');
        const btn       = L.DomUtil.create('a', '', container);
        btn.href        = '#';
        btn.title       = 'Play / Pause';
        btn.innerHTML   = '⏸';
        btn.setAttribute('role', 'button');
        L.DomEvent.on(btn, 'click', e => {
            L.DomEvent.stop(e);
            isPlaying = !isPlaying;
            if (isPlaying) {
                btn.innerHTML = '⏸';
                btn.classList.remove('paused');
                animStartTs = null; // will recalculate from distanceCovered on next tick
                animHandle  = requestAnimationFrame(animTick);
            } else {
                btn.innerHTML = '⏵';
                btn.classList.add('paused');
                cancelAnimationFrame(animHandle);
                clearTimeout(animHandle);
                animHandle = null;
            }
        });
        return container;
    },
});
new PlayPauseControl().addTo(map);

// ── Boot ────────────────────────────────────────────────────────────────
// Make whole day card clickable for days with photos (day-card--link)
document.querySelectorAll('.day-card--link[data-href]').forEach(card => {
    card.addEventListener('click', e => {
        if (!e.target.closest('a')) window.location = card.dataset.href;
    });
    card.addEventListener('keydown', e => {
        if (e.key === 'Enter') window.location = card.dataset.href;
    });
});

fetchTracks();

<?php if ($is_admin): ?>
// ── Photo upload ──────────────────────────────────────────────────────────
const uploadZone  = document.getElementById('upload-zone');
const uploadInput = document.getElementById('upload-input');
const uploadQueue = document.getElementById('upload-queue');

uploadZone.addEventListener('click', () => uploadInput.click());
uploadInput.addEventListener('change', e => handleUpload([...e.target.files]));
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
uploadZone.addEventListener('drop', e => {
    e.preventDefault();
    uploadZone.classList.remove('drag-over');
    handleUpload([...e.dataTransfer.files].filter(f => f.type === 'image/jpeg'));
});

async function handleUpload(files) {
    if (!files.length) return;

    const items = files.map(file => {
        const el = document.createElement('div');
        el.className = 'upload-item';
        el.innerHTML = `<span class="upload-item__name">${file.name}</span><span class="upload-item__status">waiting</span>`;
        uploadQueue.appendChild(el);
        return { file, el };
    });

    let anyDone = false;
    for (const { file, el } of items) {
        const statusEl = el.querySelector('.upload-item__status');
        statusEl.textContent = 'uploading…';

        try {
            const fd = new FormData();
            fd.append('photo', file);
            fd.append('year', TRIP_YEAR);
            fd.append('slug', TRIP_SLUG);

            const res = await fetch('/api/upload-photo', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.ok) {
                el.classList.add('upload-item--done');
                const tierLabels = ['', 'GPS', 'GPX', 'manual', '—'];
                statusEl.textContent = `✓ day ${json.day ?? '?'} · ${tierLabels[json.tier] ?? '?'}`;
                anyDone = true;
            } else {
                throw new Error(json.error || 'Upload failed');
            }
        } catch (err) {
            el.classList.add('upload-item--error');
            el.querySelector('.upload-item__status').textContent = `✗ ${err.message}`;
        }
    }

    // Reload to update day cards if any photos landed
    if (anyDone) setTimeout(() => location.reload(), 1200);
}
<?php endif; ?>
</script>
</body>
</html>