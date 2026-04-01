<?php
// public/pages/day.php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';

$is_admin = is_admin();

$year   = (int)($_GET['year'] ?? 0);
$slug   = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');
$dayNum = (int)($_GET['day'] ?? 1);

$tripStmt = db()->prepare("SELECT * FROM trips WHERE year = ? AND slug = ?");
$tripStmt->execute([$year, $slug]);
$trip = $tripStmt->fetch();
if (!$trip) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$dayStmt = db()->prepare("SELECT * FROM trip_days WHERE trip_id = ? AND day_number = ?");
$dayStmt->execute([$trip['id'], $dayNum]);
$day = $dayStmt->fetch();
if (!$day) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$allDaysStmt = db()->prepare("SELECT day_number FROM trip_days WHERE trip_id = ? ORDER BY day_number");
$allDaysStmt->execute([$trip['id']]);
$allDayNums = array_column($allDaysStmt->fetchAll(), 'day_number');
$dayIdx  = array_search($dayNum, $allDayNums);
$prevDay = $dayIdx > 0 ? $allDayNums[$dayIdx - 1] : null;
$nextDay = $dayIdx < count($allDayNums) - 1 ? $allDayNums[$dayIdx + 1] : null;

// Is the track start the first thing in the stream, or are there pre-hike photos?
$firstPhotoStmt = db()->prepare("
    SELECT MIN(taken_at) AS first_taken
    FROM media
    WHERE trip_id = ? AND day_number = ? AND taken_at IS NOT NULL
");
$firstPhotoStmt->execute([$trip['id'], $dayNum]);
$firstPhotoRow  = $firstPhotoStmt->fetch();
$firstPhotoTime = $firstPhotoRow['first_taken'] ?? null;
$trackStartTime = $day['started_at'] ?? null;

// Track starts first if there are no photos, or track starts before/at first photo
$trackStartsFirst = !$firstPhotoTime || !$trackStartTime
    || strtotime($trackStartTime) <= strtotime($firstPhotoTime);

// Does this day have any trackpoints at all?
$trackCountStmt = db()->prepare("
    SELECT COUNT(*) FROM trackpoints
    WHERE trip_id = ?
    AND (recorded_at AT TIME ZONE 'UTC' AT TIME ZONE 'America/Denver')::date = ?
");
$trackCountStmt->execute([$trip['id'], $day['date']]);
$has_track = (int)$trackCountStmt->fetchColumn() > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($trip['name']) ?> · Day <?= $dayNum ?> — Backcountry Club</title>
<link rel="stylesheet" href="/css/app.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
/* ── Light mode tokens for day page ─────────────────────── */
:root {
    --day-bg:           #f5f3ee;
    --day-bg-col:       #efece6;
    --day-border:       #ddd9cf;
    --day-text:         #1a1916;
    --day-text-muted:   #4a4742;
    --day-text-faint:   #4a4742;
    --day-red:          #E4572E;
    --day-topbar-h:     52px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--day-bg-col);
    padding-right: 50vw;
    overscroll-behavior: none;
}

/* ── Fixed map panel — starts below topbar ───────────────── */
#map-panel {
    position: fixed;
    top: var(--day-topbar-h); right: 0;
    width: 50vw;
    height: calc(100vh - var(--day-topbar-h));
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--day-border);
    z-index: 100;
}
#map { flex: 1; min-height: 0; }

/* Elevation */
#elevation {
    height: 120px;
    background: var(--day-bg);
    border-top: 1px solid var(--day-border);
    position: relative;
    flex-shrink: 0;
}
#elev-canvas { display: block; width: 100%; height: 100%; }
#elev-cursor {
    position: absolute; top: 0; bottom: 26px; width: 2px;
    background: var(--day-red); pointer-events: none; left: 0;
    transition: left 0.25s ease;
    opacity: 0.8;
}
#elev-dot {
    position: absolute; width: 10px; height: 10px;
    border-radius: 50%; background: var(--day-red); border: 2.5px solid white;
    box-shadow: 0 0 0 1.5px var(--day-red);
    transform: translate(-50%, -50%); pointer-events: none;
    transition: left 0.25s ease, top 0.25s ease;
}

/* ── Topbar — spans photos column only ───────────────────── */
#topbar {
    position: sticky;
    top: 0;
    z-index: 200;
    background: var(--day-bg);
    border-bottom: 1px solid var(--day-border);
    display: flex;
    align-items: center;
    padding: 0 20px 0 24px;
    height: var(--day-topbar-h);
    /* Extend across both columns — map panel is fixed so this is purely visual */
    margin-right: calc(-50vw);
    width: calc(100% + 50vw);
}
.topbar__nav {
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: var(--font-mono);
    font-size: 1rem;
    padding-right: 20px;
    border-right: 1px solid var(--day-border);
    margin-right: 20px;
    white-space: nowrap;
    flex-shrink: 0;
}
.topbar__trip {
    color: var(--day-text-faint);
    text-decoration: none;
    transition: color 0.15s;
}
.topbar__trip:hover { color: var(--day-red); }
.topbar__sep { color: var(--day-border); }
.topbar__day {
    color: var(--day-text-faint);
    text-decoration: none;
    transition: color 0.15s;
}
.topbar__day:hover { color: var(--day-red); }
.topbar__day-current { color: var(--day-text); font-weight: 600; }
.topbar__title {
    font-family: var(--font-mono);
    font-size: 1rem;
    font-weight: 600;
    color: var(--day-text);
    flex: 1;
    white-space: nowrap;
}
.topbar__time {
    font-family: var(--font-mono);
    font-size: 1rem;
    font-weight: 600;
    color: var(--day-text-muted);
    transition: color 0.3s ease;
}
.topbar__time.is-tracking { color: var(--day-red); }
#topbar.is-tracking .topbar__stats { color: var(--day-red); }
#topbar.is-tracking .topbar__stats strong { color: var(--day-red); }

.topbar__stats {
    display: flex;
    gap: 16px;
    font-family: var(--font-mono);
    font-size: 1rem;
    color: var(--day-text-muted);
    margin-left: 20px;
    padding-left: 20px;
    border-left: 1px solid var(--day-border);
    flex-shrink: 0;
}


/* ── Photos column ────────────────────────────────────────── */
#photos-col {
    padding-top: var(--day-topbar-h);
    background: var(--day-bg-col);
}
.photo-item {
    padding: 18px 36px 0;
    cursor: pointer;
    cursor: pointer;
    position: relative;
    margin-bottom: 18px;
}
/* active state indicated by color — no left border needed */
.photo-item img {
    width: 100%; height: auto; display: block;
    border-radius: 3px;
    filter: grayscale(1);
    transition: filter 0.4s ease;
}
.photo-item.is-active img {
    filter: grayscale(0);
}
/* ── Photo overlay — time bottom-left, details bottom-right ── */
.photo-item__img-wrap {
    position: relative;
    display: block;
}
.photo-item__img-wrap img {
    display: block;
    width: 100%;
    height: auto;
}
.photo-item__img-scrim {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 56px;
    background: linear-gradient(to top, rgba(0,0,0,0.52) 0%, transparent 100%);
    pointer-events: none;
}
.photo-item__overlay {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    padding: 0 10px 8px;
    pointer-events: none;
}
.photo-item__overlay-time {
    font-family: var(--font-mono);
    font-size: 0.75rem;
    color: rgba(255,255,255,0.92);
    letter-spacing: 0.03em;
}
.photo-item__overlay-details {
    font-family: var(--font-mono);
    font-size: 0.75rem;
    color: rgba(255,255,255,0.92);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    cursor: pointer;
    pointer-events: all;
    user-select: none;
}
.photo-item__overlay-chevron {
    display: inline-block;
    transition: transform 0.2s ease;
    margin-left: 2px;
}
.photo-item.debug-open .photo-item__overlay-chevron {
    transform: rotate(90deg);
}

/* Track sentinels */
.track-sentinel {
    min-height: 25vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 12px 36px 12px;
}

/* ── Every stream element has enough height for smooth interpolation ── */
.photo-item { min-height: 50vh; }

.track-sentinel__card {
    width: 100%;
    background: rgba(228, 87, 46, 0.08);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px;
    gap: 4px;
}
.track-sentinel__label {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--day-red);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.7;
}
.track-sentinel__time {
    font-family: var(--font-mono);
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--day-red);
    letter-spacing: 0.02em;
}

#photos-col::after { content: ''; display: block; height: 50vh; }

/* ── Next day link ───────────────────────────────────────── */
.next-day {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 36px;
    font-family: var(--font-mono);
    font-size: 1rem;
    color: var(--day-text-muted);
    text-decoration: none;
    transition: color 0.15s;
}
.next-day:hover { color: var(--day-text); text-decoration: none; }
.next-day__arrow { display: inline-flex; vertical-align: middle; }

/* ── No-track mode ────────────────────────────────────────── */
body.no-track #elevation { display: none; }
body.no-track #map { flex: 1; }
.leaflet-container { background: #f4f1eb; }
.leaflet-control-attribution {
    background: rgba(245, 243, 238, 0.85) !important;
    color: rgba(80, 105, 80, 0.7) !important;
    font-size: 9px !important;
    backdrop-filter: blur(2px);
}
.leaflet-control-attribution a {
    color: rgba(80, 105, 80, 0.8) !important;
}

/* ── Position marker ──────────────────────────────────────── */
.pos-marker {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--day-red);
    border: 2.5px solid white;
    box-shadow: 0 0 0 1.5px var(--day-red), 0 1px 4px rgba(0,0,0,0.3);
}

/* ── Details panel ────────────────────────────────────────── */
.photo-details {
    display: none;
    padding: 12px 2px 10px;
    font-family: var(--font-mono);
    font-size: 0.78rem;
    color: var(--day-text-muted);
    line-height: 1.8;
}
.photo-item.debug-open .photo-details { display: block; }
.photo-details span { display: block; }
.dbg-num { color: var(--day-text-faint); font-weight: 600; margin-bottom: 2px; }
.dbg-source-1 { color: #4a7a4a; }
.dbg-source-2 { color: #9a6a1a; }
.dbg-source-4 { color: var(--day-text-faint); }

/* Admin action buttons in details panel */
.dbg-actions {
    display: flex; gap: 12px;
    margin-top: 8px; padding-top: 8px;
    border-top: 1px solid var(--day-border);
}
.dbg-btn {
    font-family: var(--font-mono);
    font-size: 0.72rem; font-weight: 600;
    letter-spacing: 0.05em; text-transform: uppercase;
    background: none; border: none; padding: 0;
    cursor: pointer; transition: opacity 0.15s;
}
.dbg-btn:hover { opacity: 0.6; }
.dbg-btn--delete { color: var(--day-red); }
.dbg-btn--snap   { color: #4a7a4a; }
.dbg-btn--confirm { color: var(--day-red); margin-left: 8px; }

/* ── Lightbox ─────────────────────────────────────────────── */
.lightbox {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.93); z-index: 1000;
    align-items: center; justify-content: center; flex-direction: column;
}
.lightbox.is-open { display: flex; }
.lightbox__img { max-width: 90vw; max-height: 85vh; object-fit: contain; display: block; }
.lightbox__close {
    position: fixed; top: 20px; right: 24px;
    font-family: var(--font-mono); font-size: 1rem;
    color: rgba(255,255,255,0.35); cursor: pointer;
    letter-spacing: 0.08em; text-transform: uppercase;
    background: none; border: none; padding: 8px;
}
.lightbox__close:hover { color: white; }
.lightbox__caption {
    font-family: var(--font-body); font-size: 1rem;
    color: rgba(255,255,255,0.45); margin-top: 12px;
    max-width: 600px; text-align: center; font-style: italic;
}
.lightbox__counter {
    font-family: var(--font-mono); font-size: 1rem;
    color: rgba(255,255,255,0.2); margin-top: 6px; letter-spacing: 0.08em;
}
.lightbox__nav {
    position: fixed; top: 50%; transform: translateY(-50%);
    font-size: 2rem; color: rgba(255,255,255,0.25); cursor: pointer;
    padding: 20px 16px; background: none; border: none; user-select: none;
    transition: color 0.15s;
}
.lightbox__nav:hover { color: white; }
.lightbox__nav--prev { left: 8px; }
.lightbox__nav--next { right: 8px; }
.no-photos {
    padding: 60px 24px; text-align: center;
    font-family: var(--font-mono); font-size: 1rem;
    color: var(--day-text-faint); letter-spacing: 0.06em; text-transform: uppercase;
}

/* ── Day header — always first in stream ─────────────────── */
.day-header {
    min-height: 70vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 18vh 36px 0;
    gap: 0;
}
.day-header__title {
    font-family: var(--font-mono);
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--day-text);
    letter-spacing: 0.02em;
    margin-bottom: 10px;
    text-align: center;
}
.day-header__stats {
    display: flex;
    gap: 20px;
    font-family: var(--font-mono);
    font-size: 1rem;
    color: var(--day-text-muted);
}
.day-header__stat strong {
    color: var(--day-text);
    font-weight: 600;
}
.day-header__track-start {
    width: 100%;
    background: rgba(228, 87, 46, 0.08);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 36px;
    gap: 4px;
    margin-top: auto;
    margin-bottom: 12px;
}
.day-header__label {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--day-red);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.7;
}
.day-header__scroll-hint {
    margin-top: 64px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--day-text-faint);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    user-select: none;
}
.day-header__track-time {
    font-family: var(--font-mono);
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--day-red);
    letter-spacing: 0.02em;
}
</style>
</head>
<body<?= $has_track ? '' : ' class="no-track"' ?>>

<div id="topbar">
    <nav class="topbar__nav">
        <a href="/<?= $year ?>/<?= $slug ?>" class="topbar__trip"><svg width="14" height="10" viewBox="0 0 14 10" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:1px;margin-right:6px;flex-shrink:0"><path d="M13 5H1M1 5L5 1M1 5L5 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg><?= htmlspecialchars($trip['name']) ?></a>
        <?php foreach ($allDayNums as $n): ?>
        <span class="topbar__sep">·</span>
        <?php if ($n === $dayNum): ?>
        <span class="topbar__day-current">Day <?= $n ?></span>
        <?php else: ?>
        <a href="/<?= $year ?>/<?= $slug ?>/day/<?= $n ?>" class="topbar__day">Day <?= $n ?></a>
        <?php endif ?>
        <?php endforeach ?>
    </nav>
    <div class="topbar__title">
        <span id="live-date"><?= date('D, j M Y', strtotime($day['date'])) ?></span><span class="topbar__time" id="live-time"></span>
    </div>
    <div class="topbar__stats">
        <?php if ($day['gain_m']): ?>
        <span>↑ <span id="stat-gain"><?= fmt_ele($day['gain_m']) ?></span></span>
        <?php endif ?>
        <?php if ($day['loss_m']): ?>
        <span>↓ <span id="stat-loss"><?= fmt_ele($day['loss_m']) ?></span></span>
        <?php endif ?>
        <?php if ($day['distance_m']): ?>
        <span id="stat-dist"><?= fmt_dist($day['distance_m']) ?></span>
        <?php endif ?>
    </div>
</div>

<div id="map-panel">
    <div id="map"></div>
    <div id="elevation">
        <canvas id="elev-canvas"></canvas>
        <div id="elev-cursor"></div>
        <div id="elev-dot"></div>
    </div>
</div>

<div id="photos-col">
    <div class="day-header<?= $trackStartsFirst ? ' day-header--with-track' : '' ?>"<?= $trackStartsFirst && $trackStartTime ? ' data-header-ts="' . (new DateTimeImmutable($trackStartTime))->setTimezone(new DateTimeZone('America/Denver'))->format('c') . '"' : '' ?>>
        <div class="day-header__title"><?= htmlspecialchars($trip['name']) ?> &bull; Day <?= $dayNum ?></div>
        <div class="day-header__stats">
            <?php if ($day['gain_m']): ?>
            <span class="day-header__stat">↑ <strong><?= fmt_ele($day['gain_m']) ?></strong> gain</span>
            <?php endif ?>
            <?php if ($day['loss_m']): ?>
            <span class="day-header__stat">↓ <strong><?= fmt_ele($day['loss_m']) ?></strong> loss</span>
            <?php endif ?>
            <?php if ($day['distance_m']): ?>
            <span class="day-header__stat"><strong><?= fmt_dist($day['distance_m']) ?></strong></span>
            <?php endif ?>
        </div>
        <div class="day-header__scroll-hint">
            <span>Scroll to experience</span>
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M7 2L7 12M7 12L3 8M7 12L11 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <?php if ($has_track && $trackStartsFirst && $trackStartTime): ?>
        <div class="day-header__track-start">
            <span class="day-header__label">Track begins</span>
            <span class="day-header__track-time"><?= (new DateTimeImmutable($trackStartTime))->setTimezone(new DateTimeZone('America/Denver'))->format('H:i') ?></span>
        </div>
        <?php endif ?>
    </div>
    <div class="no-photos" id="loading-msg">Loading…</div>
</div>

<div class="lightbox" id="lightbox">
    <button class="lightbox__close" id="lb-close">✕ close</button>
    <button class="lightbox__nav lightbox__nav--prev" id="lb-prev">‹</button>
    <img class="lightbox__img" id="lb-img" src="" alt="">
    <div class="lightbox__caption" id="lb-caption"></div>
    <div class="lightbox__counter" id="lb-counter"></div>
    <button class="lightbox__nav lightbox__nav--next" id="lb-next">›</button>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const YEAR    = <?= json_encode($year) ?>;
const SLUG    = <?= json_encode($slug) ?>;
const DAY     = <?= json_encode($dayNum) ?>;
const TRIP_ID = <?= json_encode($trip['id']) ?>;
const MAPBOX_TOKEN = 'pk.eyJ1Ijoic2hpZmxldHQiLCJhIjoiY21uN2V1bW4yMDA1NjJwcTU3dTc0dzR3ciJ9.Pc7hSZiIfW5VJ-ccZDnQgQ';

const TRACK_STARTS_FIRST = <?= json_encode($trackStartsFirst) ?>; // no pre-hike photos
const HAS_TRACK          = <?= json_encode($has_track) ?>;        // trip has GPS trackpoints
const IS_ADMIN           = <?= json_encode($is_admin) ?>;         // admin controls visible
const TRACK_COLOR_BASE    = '#FFFFFF';
const TRACK_COLOR_VISITED = '#E4572E';
const TRACK_OPACITY_BASE  = 0.6;

// ── Live time display ────────────────────────────────────
const liveTimeEl = document.getElementById('live-time');

function setLiveTimeText(str) {
    if (!liveTimeEl) return;
    liveTimeEl.textContent = str ? ' • ' + str : '';
}

function setLiveTime(ts) {
    if (!ts || !liveTimeEl) return;
    const d = new Date(ts);
    const hh = String(d.getUTCHours() - 6).padStart(2, '0'); // MDT = UTC-6
    // Use Intl for correct DST handling
    const str = d.toLocaleTimeString('en-GB', {
        hour: '2-digit', minute: '2-digit',
        timeZone: 'America/Denver',
        hour12: false,
    });
    setLiveTimeText(str);
}
const TRACK_WEIGHT = 4.5;
const GEO_THRESHOLD = 0.001; // ~100m — for loop disambiguation

// ── Map ──────────────────────────────────────────────────
const map = L.map('map', { zoomControl: true });
L.tileLayer(
    `https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/256/{z}/{x}/{y}@2x?access_token=${MAPBOX_TOKEN}`,
    { attribution: '© Mapbox © OpenStreetMap', maxZoom: 18, tileSize: 256 }
).addTo(map);

// ── State ────────────────────────────────────────────────
let trackPts     = [];
let progressLine = null;
let isTracking        = false; // true when between start/end sentinels
let trackBounds       = null;  // stored for fitBounds on virtual start
let wasInVirtualStart  = false; // prevents fitBounds firing on every scroll
let suppressPan       = false;  // prevents panTo conflicting with fitBounds

// ── Fetch coordination ───────────────────────────────────
let _trackReady  = false;
let _photosReady = false;
function _tryFillSentinels() {
    if (_trackReady && _photosReady && window.fillSentinels) window.fillSentinels();
}

// Single marker showing current photo location
const posIcon = L.divIcon({
    className: '',
    html: '<div class="pos-marker"></div>',
    iconSize: [12, 12],
    iconAnchor: [6, 6],
});
let posMarker = null;

// ── Nearest trackpoint by timestamp + geo disambiguation ─
function nearestTrackIdx(lat, lon, takenAt) {
    if (!trackPts.length) return 0;

    // Gather candidates within 100m
    const candidates = [];
    for (let i = 0; i < trackPts.length; i++) {
        const d = Math.hypot(trackPts[i].lat - lat, trackPts[i].lon - lon);
        if (d < GEO_THRESHOLD) candidates.push({ i, d });
    }

    // No nearby candidates — fall back to pure nearest
    if (!candidates.length) {
        let best = 0, bestD = Infinity;
        for (let i = 0; i < trackPts.length; i++) {
            const d = Math.hypot(trackPts[i].lat - lat, trackPts[i].lon - lon);
            if (d < bestD) { bestD = d; best = i; }
        }
        return best;
    }

    // Use timestamp to pick among nearby candidates
    if (takenAt) {
        const photoMs = new Date(takenAt).getTime();
        let best = candidates[0].i, bestDiff = Infinity;
        for (const { i } of candidates) {
            if (trackPts[i].ts) {
                const diff = Math.abs(new Date(trackPts[i].ts).getTime() - photoMs);
                if (diff < bestDiff) { bestDiff = diff; best = i; }
            }
        }
        return best;
    }

    return candidates.sort((a, b) => a.d - b.d)[0].i;
}

// ── Move marker to photo location ────────────────────────
let panTimer = null;

function showPhotoOnMap(photo) {
    // Used only by lightbox — scroll-driven pan handles normal scrolling
    if (!photo.lat || !photo.lon) return;
    const ll = [photo.lat, photo.lon];
    if (!posMarker) {
        posMarker = L.marker(ll, { icon: posIcon, interactive: false, zIndexOffset: 1000 }).addTo(map);
    } else {
        posMarker.setLatLng(ll);
    }
    // Dead zone: don't pan if photo timestamp is too far from any trackpoint
    if (!suppressPan && trackPts.length && photo.taken_at) {
        const photoMs = new Date(photo.taken_at).getTime();
        let bestDiff = Infinity;
        for (const tp of trackPts) {
            const d = Math.abs(new Date(tp.ts).getTime() - photoMs);
            if (d < bestDiff) bestDiff = d;
        }
        if (bestDiff <= TRACK_GAP_THRESHOLD_MS) {
            map.panTo(ll, { animate: true, duration: 0.5, easeLinearity: 0.5 });
        }
    }
    const tIdx = nearestTrackIdx(photo.lat, photo.lon, photo.taken_at);
    updateElevationCursor(tIdx);
}

// ── Move marker by timestamp (scroll-driven) ─────────────
const TRACK_GAP_THRESHOLD_MS = 5 * 60 * 1000; // 5 minutes

// ── Live stat elements ───────────────────────────────────
const statGainEl = document.getElementById('stat-gain');
const statLossEl = document.getElementById('stat-loss');
const statDistEl = document.getElementById('stat-dist');
const TOTAL_GAIN_M = <?= (float)($day['gain_m'] ?? 0) ?>;
const TOTAL_LOSS_M = <?= (float)($day['loss_m'] ?? 0) ?>;
const TOTAL_DIST_M = <?= (float)($day['distance_m'] ?? 0) ?>;

function fmtEle(m) {
    return Math.round(m * 3.28084).toLocaleString() + ' ft';
}
function fmtDist(m) {
    return (m / 1609.34).toFixed(1) + ' mi';
}

// ── Cumulative stats arrays — built once when track loads ─
// Index i = cumulative gain/loss/distance up to trackPts[i]
let cumGain = [], cumLoss = [], cumDist = [];

function buildCumulativeStats() {
    cumGain = [0]; cumLoss = [0]; cumDist = [0];
    for (let i = 1; i < trackPts.length; i++) {
        const prev = trackPts[i-1], curr = trackPts[i];
        // Elevation
        const dEle = (curr.ele && prev.ele) ? curr.ele - prev.ele : 0;
        cumGain.push(cumGain[i-1] + (dEle > 0 ? dEle : 0));
        cumLoss.push(cumLoss[i-1] + (dEle < 0 ? -dEle : 0));
        // Distance
        const dlat = (curr.lat - prev.lat) * 111320;
        const dlon = (curr.lon - prev.lon) * 111320 * Math.cos(prev.lat * Math.PI / 180);
        cumDist.push(cumDist[i-1] + Math.sqrt(dlat*dlat + dlon*dlon));
    }
}

function updateLiveStats(ms) {
    if (!trackPts.length || !cumGain.length) return;
    // Find tIdx — same binary search as updateMarkerForTime
    const trackStartMs = new Date(trackPts[0].ts).getTime();
    const trackEndMs   = new Date(trackPts[trackPts.length - 1].ts).getTime();
    let tIdx;
    if (ms <= trackStartMs) {
        tIdx = 0;
    } else if (ms >= trackEndMs) {
        tIdx = trackPts.length - 1;
    } else {
        let lo = 0, hi = trackPts.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (new Date(trackPts[mid].ts).getTime() <= ms) lo = mid;
            else hi = mid - 1;
        }
        tIdx = lo;
    }
    if (statGainEl) statGainEl.textContent = fmtEle(cumGain[tIdx]);
    if (statLossEl) statLossEl.textContent = fmtEle(cumLoss[tIdx]);
    if (statDistEl) statDistEl.textContent = fmtDist(cumDist[tIdx]);
}

function updateMarkerForTime(ms) {
    if (!trackPts.length) return;

    const trackStartMs = new Date(trackPts[0].ts).getTime();
    const trackEndMs   = new Date(trackPts[trackPts.length - 1].ts).getTime();

    // Find the trackpoint just before or at ms (lower bound)
    let tIdx = 0;
    if (ms <= trackStartMs) {
        tIdx = 0;
    } else if (ms >= trackEndMs) {
        tIdx = trackPts.length - 1;
    } else {
        // Binary-search for the last trackpoint with ts <= ms
        let lo = 0, hi = trackPts.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (new Date(trackPts[mid].ts).getTime() <= ms) lo = mid;
            else hi = mid - 1;
        }
        tIdx = lo;
    }

    // Dead zone — if we're more than 5 min from the nearest recorded point, don't move
    const nearestDiff = Math.min(
        Math.abs(new Date(trackPts[tIdx].ts).getTime() - ms),
        tIdx + 1 < trackPts.length ? Math.abs(new Date(trackPts[tIdx + 1].ts).getTime() - ms) : Infinity
    );
    if (ms > trackStartMs && ms < trackEndMs && nearestDiff > TRACK_GAP_THRESHOLD_MS) return;

    // Continuously interpolate lat/lon between tIdx and tIdx+1
    let lat, lon;
    const nextIdx = tIdx + 1;
    if (nextIdx < trackPts.length) {
        const t0  = new Date(trackPts[tIdx].ts).getTime();
        const t1  = new Date(trackPts[nextIdx].ts).getTime();
        const frac = t1 > t0 ? Math.max(0, Math.min(1, (ms - t0) / (t1 - t0))) : 0;
        lat = trackPts[tIdx].lat + frac * (trackPts[nextIdx].lat - trackPts[tIdx].lat);
        lon = trackPts[tIdx].lon + frac * (trackPts[nextIdx].lon - trackPts[tIdx].lon);
    } else {
        lat = trackPts[tIdx].lat;
        lon = trackPts[tIdx].lon;
    }

    const ll = [lat, lon];

    if (!posMarker) {
        posMarker = L.marker(ll, { icon: posIcon, interactive: false, zIndexOffset: 1000 }).addTo(map);
    } else {
        posMarker.setLatLng(ll);
    }

    // Grow the red visited line to the current interpolated position
    if (progressLine) {
        const pts = trackPts.slice(0, tIdx + 1).map(p => [p.lat, p.lon]);
        pts.push(ll); // append interpolated position so line always reaches the marker
        progressLine.setLatLngs(pts);
    }

    clearTimeout(panTimer);
    if (!suppressPan) {
        clearTimeout(panTimer);
        if (!suppressPan) {
            panTimer = setTimeout(() => {
                if (!suppressPan) map.panTo(ll, { animate: true, duration: 0.4, easeLinearity: 0.5 });
            }, 80);
        }
    }

    updateElevationCursor(tIdx);
}

// ── Load track ───────────────────────────────────────────
fetch(`/api/points/${YEAR}/${SLUG}/${DAY}`)
    .then(r => r.json())
    .then(pts => {
        trackPts = pts;

        if (!pts.length) {
            // No-track mode: mark ready immediately so photos can render
            _trackReady = true;
            _tryFillSentinels();
            return;
        }

        const lls = pts.map(p => [p.lat, p.lon]);

        // Base track — white, shows the full route
        L.polyline(lls, {
            color: TRACK_COLOR_BASE, weight: TRACK_WEIGHT,
            opacity: TRACK_OPACITY_BASE, lineCap: 'round', lineJoin: 'round',
            interactive: false,
        }).addTo(map);

        // Visited track — red, grows with scroll progress
        progressLine = L.polyline([], {
            color: TRACK_COLOR_VISITED, weight: TRACK_WEIGHT,
            opacity: 0.9, lineCap: 'round', lineJoin: 'round',
            interactive: false,
        }).addTo(map);

        trackBounds = L.latLngBounds(lls);
        map.fitBounds(trackBounds, { padding: [40, 40], animate: false });
        map.zoomIn(1, { animate: false });
        buildCumulativeStats();
        drawElevation();
        fillTrackDebug();
        _trackReady = true;
        _tryFillSentinels();
    });

// ── Fill track debug info after track loads ──────────────
function fillTrackDebug() {
    if (!trackPts.length || !photos.length) return;
    document.querySelectorAll('.photo-details[data-idx]').forEach(el => {
        const idx   = parseInt(el.dataset.idx);
        const photo = photos[idx];
        if (!photo || !photo.taken_at) return;

        const trackEl  = el.querySelector('.dbg-track');
        const interpEl = el.querySelector('.dbg-interp');
        if (!trackEl) return;

        // Find nearest trackpoint by timestamp only
        const photoMs = new Date(photo.taken_at).getTime();
        let bestI = 0, bestDiff = Infinity;
        for (let ti = 0; ti < trackPts.length; ti++) {
            const diff = Math.abs(new Date(trackPts[ti].ts).getTime() - photoMs);
            if (diff < bestDiff) { bestDiff = diff; bestI = ti; }
        }
        const tp = trackPts[bestI];
        if (!tp) return;

        const diffMin = Math.round(bestDiff / 60000);
        const trkTime = new Date(tp.ts).toLocaleTimeString('en-US', {
            hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'America/Denver'
        });

        // Distance between photo coords and nearest trackpoint
        let distM = null;
        if (photo.lat && photo.lon) {
            const dlat = (tp.lat - photo.lat) * 111320;
            const dlon = (tp.lon - photo.lon) * 111320 * Math.cos(photo.lat * Math.PI / 180);
            distM = Math.round(Math.sqrt(dlat*dlat + dlon*dlon));
        }

        if (photo.tier === 1) {
            // EXIF GPS: show track match quality
            const timePart = diffMin > 0 ? ` · Δ${diffMin} min` : '';
            const distPart = distM !== null ? ` · ${distM}m from track` : '';
            trackEl.textContent = '⏱ Nearest track: ' + trkTime + timePart + distPart;
            // Show snap button if >100m from track
            if (IS_ADMIN && distM !== null && distM > 100) {
                const snapBtn = el.querySelector('.dbg-btn--snap');
                if (snapBtn) snapBtn.style.display = '';
            }
        } else if (photo.tier === 2) {
            // GPX interpolated: position IS the track, show the time it was matched to
            trackEl.textContent = '⏱ Placed at track: ' + trkTime + (diffMin > 0 ? ` (Δ${diffMin} min)` : '');
        } else if (photo.tier === 3) {
            trackEl.textContent = '⏱ Nearest track: ' + trkTime;
        }
        // tier 4: no track info to show
    });
}

// ── Elevation ────────────────────────────────────────────
function drawElevation() {
    const canvas    = document.getElementById('elev-canvas');
    const container = document.getElementById('elevation');
    canvas.width    = container.offsetWidth * window.devicePixelRatio;
    canvas.height   = container.offsetHeight * window.devicePixelRatio;
    canvas.style.width  = container.offsetWidth + 'px';
    canvas.style.height = container.offsetHeight + 'px';

    const ctx = canvas.getContext('2d');
    ctx.scale(window.devicePixelRatio, window.devicePixelRatio);

    const W = container.offsetWidth;
    const H = container.offsetHeight;

    const FONT     = '9px "JetBrains Mono", monospace';
    const LABEL_W  = 68;  // wide enough for "14,345 ft", cursor can't overlap
    const LABEL_H  = 26;  // bottom margin for x-axis labels
    const PAD      = { top: 12, right: 8 };

    const eles = trackPts.map(p => p.ele || 0).filter(e => e > 0);
    if (!eles.length) return;

    const minE = Math.min(...eles), maxE = Math.max(...eles);
    const rangeE = maxE - minE || 1;
    const n = trackPts.length;

    // Plot area bounds
    const plotL = LABEL_W;
    const plotR = W - PAD.right;
    const plotT = PAD.top;
    const plotB = H - LABEL_H;
    const plotW = plotR - plotL;
    const plotH = plotB - plotT;

    // Cumulative distance along track — used for proportional x positioning
    // This ensures sparse Spot points get the same horizontal space as dense Gaia points
    const dists = [0];
    for (let i = 1; i < n; i++) {
        const a = trackPts[i-1], b = trackPts[i];
        const dlat = (b.lat - a.lat) * 111320;
        const dlon = (b.lon - a.lon) * 111320 * Math.cos(a.lat * Math.PI / 180);
        dists.push(dists[i-1] + Math.sqrt(dlat*dlat + dlon*dlon));
    }
    const totalDistM = dists[dists.length - 1];

    const xOf = i => plotL + (dists[i] / totalDistM) * plotW;
    const yOf = e => plotT + (1 - (e - minE) / rangeE) * plotH;

    // Store for cursor use (unscaled coords)
    canvas._xOf = xOf;
    canvas._yOf = yOf;

    ctx.clearRect(0, 0, W, H);

    // ── Fill ────────────────────────────────────────────────
    ctx.beginPath();
    ctx.moveTo(xOf(0), plotB);
    ctx.lineTo(xOf(0), yOf(trackPts[0].ele || minE));
    for (let i = 1; i < n; i++) {
        const x0 = xOf(i - 1), y0 = yOf(trackPts[i-1].ele || minE);
        const x1 = xOf(i),     y1 = yOf(trackPts[i].ele   || minE);
        const mx = (x0 + x1) / 2;
        ctx.bezierCurveTo(mx, y0, mx, y1, x1, y1);
    }
    ctx.lineTo(xOf(n - 1), plotB);
    ctx.closePath();
    ctx.fillStyle = 'rgba(90, 110, 90, 0.12)';
    ctx.fill();

    // ── Line ────────────────────────────────────────────────
    ctx.beginPath();
    ctx.moveTo(xOf(0), yOf(trackPts[0].ele || minE));
    for (let i = 1; i < n; i++) {
        const x0 = xOf(i - 1), y0 = yOf(trackPts[i-1].ele || minE);
        const x1 = xOf(i),     y1 = yOf(trackPts[i].ele   || minE);
        const mx = (x0 + x1) / 2;
        ctx.bezierCurveTo(mx, y0, mx, y1, x1, y1);
    }
    ctx.strokeStyle = 'rgba(80, 105, 80, 0.55)';
    ctx.lineWidth = 1.5;
    ctx.lineJoin  = 'round';
    ctx.stroke();

    // ── Y-axis labels (elevation in ft) ─────────────────────
    const minFt  = Math.round(minE * 3.28084);
    const maxFt  = Math.round(maxE * 3.28084);
    const midFt  = Math.round((minFt + maxFt) / 2);
    const yLabels = [
        { ft: maxFt, y: plotT + 2 },
        { ft: midFt, y: (plotT + plotB) / 2 },
        { ft: minFt, y: plotB - 3 },
    ];

    ctx.font      = FONT;
    ctx.fillStyle = 'rgba(80, 105, 80, 0.65)';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    yLabels.forEach(({ ft, y }) => {
        ctx.fillText(ft.toLocaleString() + ' ft', LABEL_W - 12, y);
    });

    // ── X-axis labels (distance in miles) ───────────────────
    const totalDistMi = totalDistM / 1609.34;
    const mileStep = totalDistMi > 6 ? 2 : 1;

    ctx.textAlign    = 'center';
    ctx.textBaseline = 'bottom';
    ctx.fillStyle    = 'rgba(80, 105, 80, 0.65)';

    for (let mi = mileStep; mi < totalDistMi; mi += mileStep) {
        const targetM = mi * 1609.34;
        // Find trackpoint index closest to this distance
        let closest = 0;
        for (let i = 1; i < n; i++) {
            if (Math.abs(dists[i] - targetM) < Math.abs(dists[closest] - targetM)) closest = i;
        }
        const x = xOf(closest);
        ctx.fillText(mi + ' mi', x, H - 6);

        // Subtle tick mark
        ctx.beginPath();
        ctx.moveTo(x, plotB);
        ctx.lineTo(x, plotB + 3);
        ctx.strokeStyle = 'rgba(80, 105, 80, 0.25)';
        ctx.lineWidth = 1;
        ctx.stroke();
    }

    // ── Baseline ────────────────────────────────────────────
    ctx.beginPath();
    ctx.moveTo(plotL, plotB);
    ctx.lineTo(plotR, plotB);
    ctx.strokeStyle = 'rgba(80, 105, 80, 0.15)';
    ctx.lineWidth = 1;
    ctx.stroke();
}

function updateElevationCursor(tIdx) {
    const canvas = document.getElementById('elev-canvas');
    if (!canvas._xOf || tIdx < 0 || tIdx >= trackPts.length) return;
    const x = canvas._xOf(tIdx);
    const y = canvas._yOf(trackPts[tIdx].ele || 0);
    // Use offsetWidth (CSS pixels) not canvas.width (physical pixels)
    const W = canvas.offsetWidth;
    document.getElementById('elev-cursor').style.left = (x / W * 100) + '%';
    document.getElementById('elev-dot').style.left    = x + 'px';
    document.getElementById('elev-dot').style.top     = y + 'px';
}

window.addEventListener('resize', () => { if (trackPts.length) drawElevation(); });

// ── Load photos ───────────────────────────────────────────
let photos    = [];
let activeIdx = -1;
const photosCol = document.getElementById('photos-col');

fetch(`/api/photos/${YEAR}/${SLUG}/${DAY}`)
    .then(r => r.json())
    .then(data => {
        photos = data;
        document.getElementById('loading-msg').remove();

        if (!photos.length) {
            photosCol.innerHTML = '<div class="no-photos">No photos for this day</div>';
            return;
        }

        // In no-track mode, fit the map to photo GPS points now
        if (!HAS_TRACK) {
            const geoPhotos = photos.filter(p => p.lat && p.lon);
            if (geoPhotos.length) {
                const bounds = L.latLngBounds(geoPhotos.map(p => [p.lat, p.lon]));
                map.fitBounds(bounds, { padding: [40, 40], animate: false });
            }
        }

        photos.forEach((photo, idx) => {
            const item = document.createElement('div');
            item.className   = 'photo-item';
            item.dataset.idx = idx;

            const time = photo.taken_at
                ? new Date(photo.taken_at).toLocaleTimeString('en-US', {
                    hour: 'numeric', minute: '2-digit', hour12: true,
                    timeZone: 'America/Denver',
                  })
                : '';

            // Build location source label
            const tierLabel = ['', 'EXIF GPS', 'GPX interpolated', 'manual', 'none'][photo.tier] || '?';
            const tierClass  = `dbg-source-${photo.tier || 4}`;
            const coordStr   = (photo.lat && photo.lon)
                ? `${photo.lat.toFixed(5)}, ${photo.lon.toFixed(5)}`
                : 'no coordinates';

            // Track-based details filled after track loads via fillTrackDebug()
            item.innerHTML = `
                <div class="photo-item__img-wrap">
                    <img src="/uploads/${TRIP_ID}/thumbs/${photo.filename}" alt="" loading="lazy" width="${photo.width}" height="${photo.height}">
                    <div class="photo-item__img-scrim"></div>
                    <div class="photo-item__overlay">
                        <span class="photo-item__overlay-time">${time}</span>
                        <span class="photo-item__overlay-details">Details<span class="photo-item__overlay-chevron">›</span></span>
                    </div>
                </div>
                <div class="photo-details" data-idx="${idx}" data-id="${photo.id}">
                    <span class="dbg-num">Photo ${idx + 1} / ${photos.length}</span>
                    <span class="${tierClass}">📍 ${coordStr} (${tierLabel})</span>
                    <span class="dbg-track"></span>
                    <span class="dbg-interp"></span>
                    ${IS_ADMIN ? `<div class="dbg-actions">
                        <button class="dbg-btn dbg-btn--snap" style="display:none">Snap to track</button>
                        <button class="dbg-btn dbg-btn--delete">Delete</button>
                    </div>` : ''}
                </div>`;

            // Details toggle — click the overlay details button
            item.querySelector('.photo-item__overlay-details').addEventListener('click', e => {
                e.stopPropagation();
                item.classList.toggle('debug-open');
            });

            // Details panel stops propagation so text can be selected without opening lightbox
            item.querySelector('.photo-details').addEventListener('click', e => {
                e.stopPropagation();
            });

            // ── Admin actions ──────────────────────────────────
            if (IS_ADMIN) {
                const detailsEl = item.querySelector('.photo-details');
                const photoId   = photo.id;

                // Delete
                const deleteBtn = item.querySelector('.dbg-btn--delete');
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        if (deleteBtn.dataset.confirming) {
                            // Confirmed — delete
                            fetch(`/api/photo-action?id=${photoId}`, { method: 'DELETE' })
                                .then(r => r.json())
                                .then(json => {
                                    if (json.ok) {
                                        item.remove();
                                        photos.splice(idx, 1);
                                        // Update photo counters
                                        document.querySelectorAll('.dbg-num').forEach((el, i) => {
                                            el.textContent = `Photo ${i + 1} / ${photos.length}`;
                                        });
                                    } else {
                                        alert('Delete failed: ' + json.error);
                                    }
                                });
                        } else {
                            // First click — ask for confirmation inline
                            deleteBtn.dataset.confirming = '1';
                            deleteBtn.textContent = 'Delete?';
                            const cancelBtn = document.createElement('button');
                            cancelBtn.className = 'dbg-btn';
                            cancelBtn.style.color = 'var(--day-text-faint)';
                            cancelBtn.textContent = 'Cancel';
                            cancelBtn.addEventListener('click', e => {
                                e.stopPropagation();
                                delete deleteBtn.dataset.confirming;
                                deleteBtn.textContent = 'Delete';
                                cancelBtn.remove();
                            });
                            deleteBtn.after(cancelBtn);
                        }
                    });
                }

                // Snap to track
                const snapBtn = item.querySelector('.dbg-btn--snap');
                if (snapBtn) {
                    snapBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        snapBtn.textContent = 'Snapping…';
                        snapBtn.disabled = true;
                        fetch(`/api/photo-action?id=${photoId}&action=snap`, { method: 'POST' })
                            .then(r => r.json())
                            .then(json => {
                                if (json.ok) {
                                    // Update photo in memory
                                    photos[idx].lat  = json.lat;
                                    photos[idx].lon  = json.lon;
                                    photos[idx].tier = 2;
                                    // Update coord display
                                    const tierSpan = detailsEl.querySelector('[class^="dbg-source"]');
                                    if (tierSpan) {
                                        tierSpan.className = 'dbg-source-2';
                                        tierSpan.textContent = `📍 ${json.lat.toFixed(5)}, ${json.lon.toFixed(5)} (GPX interpolated)`;
                                    }
                                    snapBtn.textContent = '✓ Snapped';
                                    snapBtn.style.display = 'none';
                                } else {
                                    snapBtn.textContent = '✗ ' + json.error;
                                    snapBtn.disabled = false;
                                }
                            });
                    });
                }
            }

            item.addEventListener('click', () => openLightbox(idx));
            photosCol.appendChild(item);
        });

        <?php if ($nextDay): ?>
        // nextDayLink is created here but appended after the end sentinel in fillSentinels
        const nextDayLink = document.createElement('a');
        nextDayLink.className = 'next-day';
        nextDayLink.href = '/<?= $year ?>/<?= $slug ?>/day/<?= $nextDay ?>';
        nextDayLink.innerHTML = 'Day <?= $nextDay ?> <span class="next-day__arrow"><svg width="14" height="10" viewBox="0 0 14 10" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-left:6px;flex-shrink:0"><path d="M1 5H13M13 5L9 1M13 5L9 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        <?php else: ?>
        const nextDayLink = null;
        <?php endif ?>

        // ── Window scroll — find photo closest to viewport center ──
        const viewMid = window.innerHeight / 2;
        let lastIdx = -1;
        let lastActiveEl = null;

        // Sentinels are inserted at the correct chronological position
        // after track loads, so we create them but don't place them yet.
        function makeSentinel(type) {
            const el = document.createElement('div');
            el.className = 'track-sentinel';
            el.dataset.sentinel = type;
            return el;
        }
        const startSentinel = makeSentinel('start');
        const endSentinel   = makeSentinel('end');

        // Fill sentinel labels and insert at correct chronological position
        window.fillSentinels = function() {
            if (!trackPts.length) {
                // No-track mode: no sentinels needed, just trigger initial scroll
                if (window._onWindowScroll) window._onWindowScroll();
                return;
            }
            const fmt = ts => new Date(ts).toLocaleTimeString('en-GB', {
                hour: '2-digit', minute: '2-digit', hour12: false,
                timeZone: 'America/Denver'
            });
            const first    = trackPts[0];
            const last     = trackPts[trackPts.length - 1];
            const firstMs  = new Date(first.ts).getTime();
            const lastMs   = new Date(last.ts).getTime();

            startSentinel.innerHTML = `
                <div class="track-sentinel__card">
                    <span class="track-sentinel__label">Track begins</span>
                    <span class="track-sentinel__time">${fmt(first.ts)}</span>
                </div>`;

            endSentinel.innerHTML = `
                <div class="track-sentinel__card">
                    <span class="track-sentinel__label">Track ends</span>
                    <span class="track-sentinel__time">${fmt(last.ts)}</span>
                </div>`;

            // Only insert the start sentinel mid-stream (Day 2 style)
            // When track starts first, the PHP header handles the display
            const items = [...photosCol.querySelectorAll('.photo-item')];
            if (!TRACK_STARTS_FIRST) {
                const firstAfterStart = items.find(el => {
                    const p = photos[parseInt(el.dataset.idx)];
                    return p?.taken_at && new Date(p.taken_at).getTime() >= firstMs;
                });
                if (firstAfterStart) {
                    photosCol.insertBefore(startSentinel, firstAfterStart);
                } else {
                    photosCol.appendChild(startSentinel);
                }
            }

            // Insert end sentinel before the first photo after track end
            const firstAfterEnd = items.find(el => {
                const p = photos[parseInt(el.dataset.idx)];
                return p?.taken_at && new Date(p.taken_at).getTime() > lastMs;
            });
            if (firstAfterEnd) {
                photosCol.insertBefore(endSentinel, firstAfterEnd);
            } else {
                // All photos are within the track — put sentinel at the end
                photosCol.appendChild(endSentinel);
            }

            // Next-day link always goes after the end sentinel so ::after
            // pseudo-element provides 50vh of trailing space after the sentinel
            if (nextDayLink) photosCol.appendChild(nextDayLink);

            // Both track and photos are now ready — set initial state
            if (window._onWindowScroll) window._onWindowScroll();
        };

        // Return the timestamp (ms) for any scrollable element
        function tsOf(el) {
            if (!el) return null;
            if (el.dataset.headerTs) return new Date(el.dataset.headerTs).getTime();
            if (el.dataset.sentinel === 'start') return trackPts.length ? new Date(trackPts[0].ts).getTime() : null;
            if (el.dataset.sentinel === 'end')   return trackPts.length ? new Date(trackPts[trackPts.length-1].ts).getTime() : null;
            const idx = parseInt(el.dataset.idx);
            return (!isNaN(idx) && photos[idx]?.taken_at) ? new Date(photos[idx].taken_at).getTime() : null;
        }

        function onWindowScroll() {
            // All elements in DOM order — sentinels included for time interpolation
            // but excluded from the closest-element sweep (option C: one-way gates)
            const allEls = [...photosCol.querySelectorAll('.day-header[data-header-ts], .photo-item, .track-sentinel')];
            if (!allEls.length) return;

            // Find the closest PHOTO to viewport mid — sentinels are skipped here
            let closestIdx = 0, closestDist = Infinity;
            allEls.forEach((el, i) => {
                if (el.dataset.sentinel) return; // skip sentinels
                if (el.dataset.headerTs) return; // skip day-header
                const r    = el.getBoundingClientRect();
                const dist = Math.abs((r.top + r.height / 2) - viewMid);
                if (dist < closestDist) { closestDist = dist; closestIdx = i; }
            });

            const activeEl = allEls[closestIdx];

            // Update active stripe
            if (activeEl !== lastActiveEl) {
                if (lastActiveEl) lastActiveEl.classList.remove('is-active');
                activeEl.classList.add('is-active');
                lastActiveEl = activeEl;

                // No-track mode: just move marker to active photo's GPS
                if (!trackPts.length) {
                    const idx = parseInt(activeEl.dataset.idx);
                    if (!isNaN(idx) && photos[idx]?.lat) {
                        const photo = photos[idx];
                        const ll = [photo.lat, photo.lon];
                        if (!posMarker) {
                            posMarker = L.marker(ll, { icon: posIcon, interactive: false, zIndexOffset: 1000 }).addTo(map);
                        } else {
                            posMarker.setLatLng(ll);
                        }
                        map.panTo(ll, { animate: true, duration: 0.5, easeLinearity: 0.5 });
                        // Update time display
                        if (photo.taken_at) setLiveTime(photo.taken_at);
                    }
                    return;
                }

                // Update map marker when active element changes (track mode)
                const sentinel = activeEl.dataset.sentinel;
                if (sentinel === 'start') {
                    isTracking = true;
                    if (trackPts.length) {
                        const p = trackPts[0];
                        showPhotoOnMap({ lat: p.lat, lon: p.lon, taken_at: p.ts });
                    }
                } else if (sentinel === 'end') {
                    isTracking = false;
                    if (trackPts.length) {
                        const p = trackPts[trackPts.length - 1];
                        showPhotoOnMap({ lat: p.lat, lon: p.lon, taken_at: p.ts });
                    }
                } else {
                    const idx = parseInt(activeEl.dataset.idx);
                    if (!isNaN(idx)) showPhotoOnMap(photos[idx]);
                }
            }

            // No-track mode: no time interpolation or stat updates needed
            if (!trackPts.length) return;

            // ── Scroll-anchored time interpolation ───────────────
            const prevEl   = allEls[closestIdx - 1] || null;
            const nextEl   = allEls[closestIdx + 1] || null;

            const activeR   = activeEl.getBoundingClientRect();
            const activeMid = activeR.top + activeR.height / 2;

            const tsActive = tsOf(activeEl);
            if (!tsActive) return;

            // ── Virtual start — above first real element ──────────
            // prevEl may be a sentinel (not a real photo), treat that the same
            // as having no prevEl — we're still at the top edge.
            // But only fire virtual start if we're also above the sentinel's center
            // (i.e. haven't entered the sentinel→photo transition zone yet).
            const prevIsReal  = prevEl && !prevEl.dataset.sentinel && !prevEl.dataset.headerTs;
            const sentinelEl  = prevEl?.dataset?.sentinel ? prevEl : null;
            const sentinelMid = sentinelEl
                ? (() => { const r = sentinelEl.getBoundingClientRect(); return r.top + r.height / 2; })()
                : -Infinity;
            const headerEl  = prevEl?.dataset?.headerTs ? prevEl : null;
            const headerMid = headerEl
                ? (() => { const r = headerEl.getBoundingClientRect(); return r.top + r.height / 2; })()
                : -Infinity;
            const aboveHeaderCenter = headerEl ? viewMid < headerMid : false;
            const inVirtualStart = (activeMid > viewMid && !prevIsReal && (viewMid < sentinelMid || aboveHeaderCenter))
                || (window.scrollY === 0 && !prevIsReal && TRACK_STARTS_FIRST);
            // Safety: virtual start can never fire if we're deep in the page
            const safeVirtualStart = inVirtualStart && window.scrollY < 200;
            if (safeVirtualStart) {
                // Virtual start: use track start time
                const tsVirtualStart = trackPts.length
                    ? new Date(trackPts[0].ts).getTime()
                    : tsActive;
                const initStr = new Date(tsVirtualStart).toLocaleTimeString('en-GB', {
                    hour: '2-digit', minute: '2-digit',
                    timeZone: 'America/Denver', hour12: false,
                });
                setLiveTimeText(initStr);
                if (statGainEl) statGainEl.textContent = fmtEle(0);
                if (statLossEl) statLossEl.textContent = fmtEle(0);
                if (statDistEl) statDistEl.textContent = fmtDist(0);
                if (progressLine) progressLine.setLatLngs([]);
                updateMarkerForTime(trackPts.length ? new Date(trackPts[0].ts).getTime() : tsActive);
                // fitBounds intentionally omitted — initial load already set the view
                // re-fitting on every virtual-start entry causes jarring map jumps
                updateElevationCursor(0);
                if (trackPts.length) {
                    const rMs  = Math.round(tsVirtualStart / 60000) * 60000;
                    const rS   = Math.floor(new Date(trackPts[0].ts).getTime() / 60000) * 60000;
                    const rE   = Math.floor(new Date(trackPts[trackPts.length-1].ts).getTime() / 60000) * 60000;
                    isTracking = rMs >= rS && rMs <= rE;
                }
                if (liveTimeEl) liveTimeEl.classList.toggle('is-tracking', isTracking);
                document.getElementById('topbar').classList.toggle('is-tracking', isTracking);
                wasInVirtualStart = true;
                return;
            }

            wasInVirtualStart = false;
            let displayMs = tsActive;

            // ── Virtual end — past last element, no nextEl ─────────
            // displayMs just stays at tsActive — normal display runs below, no extra logic

            // ── Normal interpolation ───────────────────────────────
            // Allow sentinel as prevEl when we're past its center.
            // End sentinel is always valid as prevForInterp — once you're in post-track
            // photos, the end sentinel should always anchor the left side of interpolation.
            const isEndSentinelPrev = sentinelEl && sentinelEl.dataset.sentinel === 'end';
            const prevForInterp = (prevIsReal || isEndSentinelPrev || (sentinelEl && viewMid >= sentinelMid) || (headerEl && viewMid >= headerMid)) ? prevEl : null;
            if (activeMid > viewMid && prevForInterp) {
                const prevR   = prevForInterp.getBoundingClientRect();
                const prevMid = prevR.top + prevR.height / 2;
                const tsPrev  = tsOf(prevForInterp);
                if (tsPrev && activeMid > prevMid) {
                    const progress = (viewMid - prevMid) / (activeMid - prevMid);
                    displayMs = tsPrev + Math.max(0, Math.min(1, progress)) * (tsActive - tsPrev);
                }
            } else if (activeMid <= viewMid && nextEl) {
                const nextR   = nextEl.getBoundingClientRect();
                const nextMid = nextR.top + nextR.height / 2;
                const tsNext  = tsOf(nextEl);
                if (tsNext && nextMid > activeMid) {
                    const progress = (viewMid - activeMid) / (nextMid - activeMid);
                    displayMs = tsActive + Math.max(0, Math.min(1, progress)) * (tsNext - tsActive);
                }
            }

            // Round to whole minutes and display
            const rounded = new Date(Math.round(displayMs / 60000) * 60000);
            const str = rounded.toLocaleTimeString('en-GB', {
                hour: '2-digit', minute: '2-digit',
                timeZone: 'America/Denver',
                hour12: false,
            });
            setLiveTimeText(str);

            // Derive tracking from displayMs — flips exactly when clock hits track bounds
            if (trackPts.length) {
                const trackStartMs2 = new Date(trackPts[0].ts).getTime();
                const trackEndMs2   = new Date(trackPts[trackPts.length - 1].ts).getTime();
                const roundedMs = Math.round(displayMs / 60000) * 60000;
                const roundedStart = Math.floor(trackStartMs2 / 60000) * 60000;
                const roundedEnd   = Math.floor(trackEndMs2  / 60000) * 60000;
                // In virtual end, if the last element is the end sentinel, stay red
                const inVirtualEnd = activeMid <= viewMid && !nextEl;
                const lastIsEndSentinel = inVirtualEnd && activeEl.dataset.sentinel === "end";
                isTracking = lastIsEndSentinel || (roundedMs >= roundedStart && roundedMs <= roundedEnd);
            }
            if (liveTimeEl) liveTimeEl.classList.toggle('is-tracking', isTracking);
            document.getElementById('topbar').classList.toggle('is-tracking', isTracking);

            // ── Move marker in lockstep with the clock ────────────
            updateMarkerForTime(displayMs); // raw ms — interpolates continuously
            updateLiveStats(displayMs);
        }

        window.addEventListener('scroll', onWindowScroll, { passive: true });
        window._onWindowScroll = onWindowScroll; // expose for post-sentinel init
        _photosReady = true;
        _tryFillSentinels();

        // Initial state set after sentinels are placed — see fillSentinels()
    });

// ── Lightbox ──────────────────────────────────────────────
const lightbox = document.getElementById('lightbox');
const lbImg    = document.getElementById('lb-img');
const lbCap    = document.getElementById('lb-caption');
const lbCount  = document.getElementById('lb-counter');

function openLightbox(idx) {
    activeIdx = idx;
    const photo = photos[idx];
    lbImg.src           = `/uploads/${TRIP_ID}/${photo.filename}`;
    lbCap.textContent   = photo.caption || '';
    lbCount.textContent = `${idx + 1} / ${photos.length}`;
    lightbox.classList.add('is-open');
    showPhotoOnMap(photo);
}

function closeLightbox() {
    lightbox.classList.remove('is-open');
    lbImg.src = '';
    activeIdx = -1;
}

function stepLightbox(dir) {
    if (activeIdx < 0) return;
    const next = activeIdx + dir;
    if (next >= 0 && next < photos.length) {
        openLightbox(next);
        photosCol.querySelectorAll('.photo-item')[next]
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

document.getElementById('lb-close').addEventListener('click', closeLightbox);
document.getElementById('lb-prev').addEventListener('click', () => stepLightbox(-1));
document.getElementById('lb-next').addEventListener('click', () => stepLightbox(1));
lightbox.addEventListener('click', e => { if (e.target === lightbox) closeLightbox(); });

document.addEventListener('keydown', e => {
    if (!lightbox.classList.contains('is-open')) return;
    if (e.key === 'Escape')     closeLightbox();
    if (e.key === 'ArrowLeft')  stepLightbox(-1);
    if (e.key === 'ArrowRight') stepLightbox(1);
});
</script>
</body>
</html>