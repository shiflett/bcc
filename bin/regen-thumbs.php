<?php
// public/pages/day.php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';

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
	--day-text-muted:   #6b6860;
	--day-text-faint:   #a8a499;
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
	height: 80px;
	background: #fff;
	border-top: 1px solid var(--day-border);
	position: relative;
	flex-shrink: 0;
}
#elev-canvas { display: block; width: 100%; height: 100%; }
#elev-cursor {
	position: absolute; top: 0; bottom: 0; width: 1px;
	background: var(--day-red); pointer-events: none; left: 0;
	transition: left 0.25s ease;
}
#elev-dot {
	position: absolute; width: 7px; height: 7px;
	border-radius: 50%; background: var(--day-red); border: 2px solid white;
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
	padding: 12px 36px 0;
	cursor: pointer;
	position: relative;
	margin-bottom: 2px;
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
.photo-item__meta {
	padding: 7px 2px 4px;
	display: flex;
	align-items: center;
	gap: 10px;
	font-family: var(--font-mono);
	font-size: 1rem;
	color: var(--day-text-faint);
	cursor: pointer;
	user-select: none;
}
.photo-item__meta-sep {
	color: var(--day-border);
}
.photo-item__meta-count {
	color: var(--day-text-faint);
}
.photo-item__meta-debug {
	color: var(--day-text-faint);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-size: 0.85rem;
}
.photo-item__meta-chevron {
	margin-left: auto;
	font-size: 0.75rem;
	color: var(--day-border);
	transition: transform 0.2s ease;
}
.photo-item.debug-open .photo-item__meta-chevron {
	transform: rotate(90deg);
}

/* Track sentinels */
.track-sentinel {
	padding: 16px 16px;
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 4px 0;
}
.track-sentinel__line {
	flex: 1; height: 1px;
	background: var(--day-border);
}
.track-sentinel__label {
	font-family: var(--font-mono);
	font-size: 1rem;
	color: var(--day-text-faint);
	letter-spacing: 0.06em;
	text-transform: uppercase;
	white-space: nowrap;
}
.track-sentinel__time {
	font-family: var(--font-mono);
	font-size: 1rem;
	color: var(--day-text-muted);
	font-weight: 500;
}
.track-sentinel.is-active .track-sentinel__label,
.track-sentinel.is-active .track-sentinel__time { color: var(--day-red); }

#photos-col::after { content: ''; display: block; height: 50vh; }

/* ── Leaflet overrides ────────────────────────────────────── */
.leaflet-container { background: #f4f1eb; }
.leaflet-control-attribution { font-size: 9px !important; }

/* ── Position marker ──────────────────────────────────────── */
.pos-marker {
	width: 12px; height: 12px;
	border-radius: 50%;
	background: white;
	border: 2.5px solid var(--day-red);
	box-shadow: 0 1px 4px rgba(0,0,0,0.35);
}

/* ── Debug panel ──────────────────────────────────────────── */
.photo-debug {
	display: none;
	padding: 6px 2px 10px;
	font-family: var(--font-mono);
	font-size: 0.8rem;
	color: var(--day-text-faint);
	line-height: 1.7;
	border-top: 1px dashed var(--day-border);
}
.photo-item.debug-open .photo-debug { display: block; }
.photo-debug span { display: block; }
.dbg-source-1 { color: #4a7a4a; }
.dbg-source-2 { color: #9a6a1a; }
.dbg-source-4 { color: var(--day-text-faint); }

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
</style>
</head>
<body>

<div id="topbar">
	<nav class="topbar__nav">
		<a href="/<?= $year ?>/<?= $slug ?>" class="topbar__trip">← <?= htmlspecialchars($trip['name']) ?></a>
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
let isTracking   = false; // true when between start/end sentinels

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
	map.panTo(ll, { animate: true, duration: 0.5, easeLinearity: 0.5 });
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

function updateLiveStats(ms) {
	if (!trackPts.length) return;
	const trackStartMs = new Date(trackPts[0].ts).getTime();
	const trackEndMs   = new Date(trackPts[trackPts.length - 1].ts).getTime();
	const duration     = trackEndMs - trackStartMs;
	if (duration <= 0) return;

	// Progress 0→1 across the track's time span
	const progress = Math.max(0, Math.min(1, (ms - trackStartMs) / duration));

	if (statGainEl) statGainEl.textContent = fmtEle(TOTAL_GAIN_M * progress);
	if (statLossEl) statLossEl.textContent = fmtEle(TOTAL_LOSS_M * progress);
	if (statDistEl) statDistEl.textContent = fmtDist(TOTAL_DIST_M * progress);
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

	// Grow the red visited line to tIdx (whole trackpoints only — no partial segment)
	if (progressLine) {
		progressLine.setLatLngs(trackPts.slice(0, tIdx + 1).map(p => [p.lat, p.lon]));
	}

	clearTimeout(panTimer);
	panTimer = setTimeout(() => {
		map.panTo(ll, { animate: true, duration: 0.4, easeLinearity: 0.5 });
	}, 80);

	updateElevationCursor(tIdx);
}

// ── Load track ───────────────────────────────────────────
fetch(`/api/points/${YEAR}/${SLUG}/${DAY}`)
	.then(r => r.json())
	.then(pts => {
		trackPts = pts;
		if (!pts.length) return;

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

		map.fitBounds(L.latLngBounds(lls), { padding: [40, 40] });
		drawElevation();
		fillTrackDebug();
		if (window.fillSentinels) window.fillSentinels();
	});

// ── Fill track debug info after track loads ──────────────
function fillTrackDebug() {
	if (!trackPts.length || !photos.length) return;
	document.querySelectorAll('.photo-debug[data-idx]').forEach(el => {
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

		// Distance between photo coords and timestamp-matched trackpoint
		let distStr = '';
		if (photo.lat && photo.lon) {
			const dlat = (tp.lat - photo.lat) * 111320;
			const dlon = (tp.lon - photo.lon) * 111320 * Math.cos(photo.lat * Math.PI / 180);
			const distM = Math.round(Math.sqrt(dlat*dlat + dlon*dlon));
			if (distM > 0) distStr = ' · ' + distM + 'm from track';
		}
		trackEl.textContent = 'track @ ' + trkTime + ' (Δ' + diffMin + 'm)' + distStr;

		// For EXIF photos, show where timestamp-only interpolation would place them
		if (photo.tier === 1 && photo.lat && photo.lon && interpEl) {
			const dlat = (tp.lat - photo.lat) * 111320;
			const dlon = (tp.lon - photo.lon) * 111320 * Math.cos(photo.lat * Math.PI / 180);
			const distM = Math.round(Math.sqrt(dlat*dlat + dlon*dlon));
			interpEl.textContent = '↕ GPX would place: ' + tp.lat.toFixed(5) + ', ' + tp.lon.toFixed(5) + ' (' + distM + 'm away)';
		}
	});
}

// ── Elevation ────────────────────────────────────────────
function drawElevation() {
	const canvas    = document.getElementById('elev-canvas');
	const container = document.getElementById('elevation');
	canvas.width    = container.offsetWidth;
	canvas.height   = container.offsetHeight;
	const ctx = canvas.getContext('2d');
	const W = canvas.width, H = canvas.height;
	const PAD = { top: 10, bottom: 18, left: 6, right: 6 };
	const eles = trackPts.map(p => p.ele || 0).filter(e => e > 0);
	if (!eles.length) return;
	const minE = Math.min(...eles), maxE = Math.max(...eles);
	const rangeE = maxE - minE || 1;
	const n = trackPts.length;
	const xOf = i => PAD.left + (i / (n-1)) * (W - PAD.left - PAD.right);
	const yOf = e => PAD.top  + (1 - (e - minE) / rangeE) * (H - PAD.top - PAD.bottom);

	ctx.beginPath();
	ctx.moveTo(xOf(0), H);
	trackPts.forEach((p, i) => ctx.lineTo(xOf(i), yOf(p.ele || minE)));
	ctx.lineTo(xOf(n-1), H);
	ctx.closePath();
	ctx.fillStyle = 'rgba(228, 87, 46, 0.10)';
	ctx.fill();

	ctx.beginPath();
	trackPts.forEach((p, i) =>
		i === 0 ? ctx.moveTo(xOf(i), yOf(p.ele || minE))
				: ctx.lineTo(xOf(i), yOf(p.ele || minE)));
	ctx.strokeStyle = 'rgba(228, 87, 46, 0.65)';
	ctx.lineWidth = 1.5;
	ctx.stroke();

	canvas._xOf = xOf;
	canvas._yOf = yOf;
}

function updateElevationCursor(tIdx) {
	const canvas = document.getElementById('elev-canvas');
	if (!canvas._xOf || tIdx < 0 || tIdx >= trackPts.length) return;
	const x = canvas._xOf(tIdx);
	const y = canvas._yOf(trackPts[tIdx].ele || 0);
	document.getElementById('elev-cursor').style.left = (x / canvas.width * 100) + '%';
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

			// Track-based debug is filled after track loads via fillTrackDebug()
			item.innerHTML = `
				<img src="/uploads/${TRIP_ID}/thumbs/${photo.filename}" alt="" loading="lazy" width="${photo.width}" height="${photo.height}">
				<div class="photo-item__meta">
					${time ? `<span class="photo-item__time">${time}</span>` : ''}
					${time ? `<span class="photo-item__meta-sep">•</span>` : ''}
					<span class="photo-item__meta-count">${idx + 1}/${photos.length}</span>
					<span class="photo-item__meta-sep">•</span>
					<span class="photo-item__meta-debug">Debug</span>
					<span class="photo-item__meta-chevron">›</span>
				</div>
				<div class="photo-debug" data-idx="${idx}">
					<span class="${tierClass}">📍 ${tierLabel}: ${coordStr}</span>
					<span class="dbg-track"></span>
					<span class="dbg-interp dbg-source-2"></span>
				</div>`;

			// Debug toggle — clicks on meta row toggle debug panel
			// but don't open lightbox
			item.querySelector('.photo-item__meta').addEventListener('click', e => {
				e.stopPropagation();
				item.classList.toggle('debug-open');
			});

			item.addEventListener('click', () => openLightbox(idx));
			photosCol.appendChild(item);
		});

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
			if (!trackPts.length) return;
			const fmt = ts => new Date(ts).toLocaleTimeString('en-US', {
				hour: 'numeric', minute: '2-digit', hour12: true,
				timeZone: 'America/Denver'
			});
			const first    = trackPts[0];
			const last     = trackPts[trackPts.length - 1];
			const firstMs  = new Date(first.ts).getTime();
			const lastMs   = new Date(last.ts).getTime();

			startSentinel.innerHTML = `
				<div class="track-sentinel__line"></div>
				<span class="track-sentinel__label">Track begins</span>
				<span class="track-sentinel__time">${fmt(first.ts)}</span>
				<div class="track-sentinel__line"></div>`;
			endSentinel.innerHTML = `
				<div class="track-sentinel__line"></div>
				<span class="track-sentinel__label">Track ends</span>
				<span class="track-sentinel__time">${fmt(last.ts)}</span>
				<div class="track-sentinel__line"></div>`;

			// Insert start sentinel before the first photo at or after track start
			const items = [...photosCol.querySelectorAll('.photo-item')];
			const firstAfterStart = items.find(el => {
				const p = photos[parseInt(el.dataset.idx)];
				return p?.taken_at && new Date(p.taken_at).getTime() >= firstMs;
			});
			if (firstAfterStart) {
				photosCol.insertBefore(startSentinel, firstAfterStart);
			} else {
				// All photos are before the track — put sentinel at the end
				photosCol.appendChild(startSentinel);
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
		};

		// Return the timestamp (ms) for any scrollable element
		function tsOf(el) {
			if (!el) return null;
			if (el.dataset.sentinel === 'start') return trackPts.length ? new Date(trackPts[0].ts).getTime() : null;
			if (el.dataset.sentinel === 'end')   return trackPts.length ? new Date(trackPts[trackPts.length-1].ts).getTime() : null;
			const idx = parseInt(el.dataset.idx);
			return (!isNaN(idx) && photos[idx]?.taken_at) ? new Date(photos[idx].taken_at).getTime() : null;
		}

		function onWindowScroll() {
			// All elements in DOM order — sentinels included for time interpolation
			// but excluded from the closest-element sweep (option C: one-way gates)
			const allEls = [...photosCol.querySelectorAll('.photo-item, .track-sentinel')];
			if (!allEls.length) return;

			// Find the closest PHOTO to viewport mid — sentinels are skipped here
			let closestIdx = 0, closestDist = Infinity;
			allEls.forEach((el, i) => {
				if (el.dataset.sentinel) return; // skip sentinels
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

				// Update map marker when active element changes
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

			// ── Scroll-anchored time interpolation ───────────────
			// The transition zone spans from one element's center to the next.
			// If viewport mid is between prevEl and activeEl: interpolate prev→active
			// If viewport mid is between activeEl and nextEl: interpolate active→next
			const prevEl   = allEls[closestIdx - 1] || null;
			const nextEl   = allEls[closestIdx + 1] || null;

			const activeR   = activeEl.getBoundingClientRect();
			const activeMid = activeR.top + activeR.height / 2;

			const tsActive = tsOf(activeEl);
			if (!tsActive) return;

			let displayMs = tsActive;

			if (activeMid > viewMid && prevEl) {
				// Viewport mid is before activeEl center — interpolate from prev toward active
				const prevR   = prevEl.getBoundingClientRect();
				const prevMid = prevR.top + prevR.height / 2;
				const tsPrev  = tsOf(prevEl);
				if (tsPrev && activeMid > prevMid) {
					const progress = (viewMid - prevMid) / (activeMid - prevMid);
					displayMs = tsPrev + Math.max(0, Math.min(1, progress)) * (tsActive - tsPrev);
				}
			} else if (activeMid <= viewMid && nextEl) {
				// Viewport mid has passed activeEl center — interpolate from active toward next
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
				isTracking = roundedMs >= roundedStart && roundedMs <= roundedEnd;
			}
			if (liveTimeEl) liveTimeEl.classList.toggle('is-tracking', isTracking);
			document.getElementById('topbar').classList.toggle('is-tracking', isTracking);

			// ── Move marker in lockstep with the clock ────────────
			updateMarkerForTime(displayMs); // raw ms — interpolates continuously
			updateLiveStats(displayMs);
		}

		window.addEventListener('scroll', onWindowScroll, { passive: true });

		// Initialise to first photo — sentinels aren't placed yet (need track data)
		const firstPhotoItem = photosCol.querySelector('.photo-item');
		if (firstPhotoItem) {
			firstPhotoItem.classList.add('is-active');
			lastActiveEl = firstPhotoItem;
			// Show first photo time immediately on load
			if (photos[0]?.taken_at) {
				const initTime = new Date(photos[0].taken_at).toLocaleTimeString('en-GB', {
					hour: '2-digit', minute: '2-digit',
					timeZone: 'America/Denver', hour12: false,
				});
				setLiveTimeText(initTime);
			}
			setTimeout(() => showPhotoOnMap(photos[0]), 600);
		}
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