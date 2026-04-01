<?php
/**
 * Admin — create or edit a trip
 * GET/POST /admin/new        → create
 * GET/POST /admin/{token}    → edit
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_admin_auth();

$pdo      = db();
$token    = $_GET['token'] ?? null;
$is_new   = ($token === null);
$trip     = null;
$errors   = [];
$flash    = null;

// ── Load existing trip ────────────────────────────────────────────────────
if (!$is_new) {
	$stmt = $pdo->prepare('SELECT * FROM trips WHERE token = :token LIMIT 1');
	$stmt->execute([':token' => $token]);
	$trip = $stmt->fetch();
	if (!$trip) { http_response_code(404); exit('Trip not found.'); }

	$senders_stmt = $pdo->prepare('SELECT phone, name FROM trip_senders WHERE trip_id = :id ORDER BY id');
	$senders_stmt->execute([':id' => $trip['id']]);
	$existing_senders = $senders_stmt->fetchAll();
} else {
	$existing_senders = [];
}

// ── Helper: generate a unique 12-char token ───────────────────────────────
function generate_token(PDO $pdo): string {
	do {
		$tok = substr(bin2hex(random_bytes(8)), 0, 12);
		$exists = $pdo->prepare('SELECT 1 FROM trips WHERE token = ?');
		$exists->execute([$tok]);
	} while ($exists->fetchColumn());
	return $tok;
}

// ── Helper: slug from name ────────────────────────────────────────────────
function slugify(string $s): string {
	$s = strtolower(trim($s));
	$s = preg_replace('/[^a-z0-9]+/', '-', $s);
	return trim($s, '-');
}

// ── Helper: save senders (delete + re-insert) ─────────────────────────────
function save_senders(PDO $pdo, int $trip_id): void {
	$phones = $_POST['sender_phone'] ?? [];
	$names  = $_POST['sender_name']  ?? [];
	$pdo->prepare('DELETE FROM trip_senders WHERE trip_id = ?')->execute([$trip_id]);
	$insert = $pdo->prepare('INSERT INTO trip_senders (trip_id, phone, name) VALUES (?, ?, ?)');
	foreach ($phones as $i => $phone) {
		$phone = trim($phone);
		if ($phone === '') continue;
		// Normalize to E.164 — strip non-digits, add +1 if US number
		$digits = preg_replace('/\D/', '', $phone);
		if (strlen($digits) === 10) $digits = '1' . $digits;
		$e164 = '+' . $digits;
		$name = trim($names[$i] ?? '');
		$insert->execute([$trip_id, $e164, $name ?: null]);
	}
}

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$name         = trim($_POST['name'] ?? '');
	$subtitle     = trim($_POST['subtitle'] ?? '');
	$slug         = trim($_POST['slug'] ?? '');
	$description  = trim($_POST['description'] ?? '');
	$started_at   = trim($_POST['started_at'] ?? '');
	$ended_at     = trim($_POST['ended_at'] ?? '');
	$tracker_type = $_POST['tracker_type'] ?? '';
	$tracker_id   = trim($_POST['tracker_id'] ?? '');
	$map_lat      = trim($_POST['map_lat'] ?? '');
	$map_lon      = trim($_POST['map_lon'] ?? '');
	$map_zoom     = (int)($_POST['map_zoom'] ?? 12);

	// Derive polling window: midnight before day 1 → midnight after last day
	$track_from  = $started_at ? $started_at . 'T00:00' : null;
	$track_until = $ended_at   ? $ended_at   . 'T23:59' : null;

	// Validation
	if ($name === '') $errors[] = 'Name is required.';
	if ($slug === '') $slug = slugify($name);
	if (!preg_match('/^[a-z0-9-]+$/', $slug)) $errors[] = 'Slug may only contain lowercase letters, numbers, and hyphens.';
	if ($started_at === '') $errors[] = 'Start date is required.';
	if ($ended_at === '')   $errors[] = 'End date is required.';
	if ($tracker_type !== '' && $tracker_type !== 'none' && $tracker_id === '') $errors[] = 'Feed URL / ID is required when a tracker type is selected.';

	if (empty($errors)) {
		$year = (int)date('Y', strtotime($started_at));

		// Check slug uniqueness (excluding current trip on edit)
		$slug_check = $pdo->prepare('SELECT id FROM trips WHERE year = :year AND slug = :slug AND (:id::int IS NULL OR id != :id::int)');
		$slug_check->execute([':year' => $year, ':slug' => $slug, ':id' => $trip['id'] ?? null]);
		if ($slug_check->fetchColumn()) $errors[] = "Slug '{$slug}' is already used for {$year}.";
	}

	if (empty($errors)) {
		$params = [
			':name'         => $name,
			':subtitle'     => $subtitle ?: null,
			':description'  => $description ?: null,
			':slug'         => $slug,
			':year'         => $year,
			':started_at'   => $started_at ?: null,
			':ended_at'     => $ended_at ?: null,
			':tracker_type' => ($tracker_type && $tracker_type !== 'none') ? $tracker_type : null,
			':tracker_id'   => $tracker_id ?: null,
			':track_from'   => $track_from ?: null,
			':track_until'  => $track_until ?: null,
			':map_lat'      => $map_lat !== '' ? (float)$map_lat : null,
			':map_lon'      => $map_lon !== '' ? (float)$map_lon : null,
			':map_zoom'     => $map_zoom,
		];

		if ($is_new) {
			$new_token = generate_token($pdo);
			$params[':token'] = $new_token;
			$pdo->prepare('
				INSERT INTO trips (name, subtitle, description, slug, year, started_at, ended_at,
								   tracker_type, tracker_id, track_from, track_until,
								   map_lat, map_lon, map_zoom, token)
				VALUES (:name, :subtitle, :description, :slug, :year, :started_at, :ended_at,
						:tracker_type, :tracker_id, :track_from, :track_until,
						:map_lat, :map_lon, :map_zoom, :token)
			')->execute($params);
			$new_id = (int)$pdo->lastInsertId();
			save_senders($pdo, $new_id);
			header('Location: /admin/' . $new_token . '?saved=1');
			exit;
		} else {
			$params[':id'] = $trip['id'];
			$pdo->prepare('
				UPDATE trips SET
					name = :name, subtitle = :subtitle, description = :description,
					slug = :slug, year = :year, started_at = :started_at, ended_at = :ended_at,
					tracker_type = :tracker_type, tracker_id = :tracker_id,
					track_from = :track_from, track_until = :track_until,
					map_lat = :map_lat, map_lon = :map_lon, map_zoom = :map_zoom
				WHERE id = :id
			')->execute($params);
			save_senders($pdo, (int)$trip['id']);
			// Reload trip after save
			$stmt = $pdo->prepare('SELECT * FROM trips WHERE id = :id');
			$stmt->execute([':id' => $trip['id']]);
			$trip = $stmt->fetch();
			// Reload senders
			$senders_stmt = $pdo->prepare('SELECT phone, name FROM trip_senders WHERE trip_id = :id ORDER BY id');
			$senders_stmt->execute([':id' => $trip['id']]);
			$existing_senders = $senders_stmt->fetchAll();
			$flash = 'Saved.';
		}
	}
}

// ── Flash message from redirect ───────────────────────────────────────────
if (isset($_GET['saved'])) $flash = 'Trip created.';

// ── Values for form (POST data takes precedence on error) ─────────────────
$v = [];
$fields = ['name','subtitle','slug','description','started_at','ended_at',
		   'tracker_type','tracker_id','track_from','track_until','map_lat','map_lon','map_zoom'];
foreach ($fields as $f) {
	$v[$f] = !empty($errors) && isset($_POST[$f])
		? $_POST[$f]
		: ($trip[$f] ?? '');
}

// Format timestamps for date inputs
foreach (['started_at', 'ended_at'] as $f) {
	if ($v[$f]) {
		$v[$f] = (new DateTime($v[$f]))->format('Y-m-d');
	}
}

$page_title = $is_new ? 'New Trip' : htmlspecialchars($trip['name']);
$mapbox_token = json_encode(getenv('BCC_MAPBOX') ?: '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ?> — Admin</title>

<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
:root {
	--bg:         #f5f3ee;
	--bg-col:     #efece6;
	--border:     #ddd9cf;
	--text:       #1a1916;
	--text-muted: #6b6860;
	--text-faint: #a8a499;
	--red:        #E4572E;
	--bcc-red:    #BF2C34;
	--topbar-h:   52px;
	--sys:        -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
	--mono:       'JetBrains Mono', 'Courier New', monospace;
	--page-w:     680px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
	min-height: 100%; background: var(--bg); color: var(--text);
	font-family: var(--sys); font-size: 15px; line-height: 1.5;
	-webkit-font-smoothing: antialiased;
}

/* ── Topbar ── */
#topbar {
	position: sticky; top: 0; z-index: 100;
	height: var(--topbar-h);
	background: var(--bg); border-bottom: 1px solid var(--border);
	display: flex; align-items: center; gap: 0;
	padding: 0 20px 0 24px;
}
.topbar__nav {
	display: flex; align-items: center; gap: 6px;
	font-family: var(--mono); font-size: 1rem;
	flex: 1;
}
.topbar__back {
	color: var(--text-faint); text-decoration: none;
	transition: color 0.15s; display: flex; align-items: center;
}
.topbar__back:hover { color: var(--bcc-red); }
.topbar__sep { color: var(--border); }
.topbar__current { color: var(--text); font-weight: 600; }

/* ── Page ── */
#page {
	max-width: var(--page-w);
	margin: 0 auto;
	padding: 40px 24px 80px;
}

/* ── Flash / errors ── */
.flash {
	background: #eaf4ea; border: 1px solid #b8dbb8;
	color: #2d5a2d; border-radius: 3px;
	padding: 10px 14px; font-size: 13px;
	margin-bottom: 28px;
}
.errors {
	background: #fdf0ee; border: 1px solid #f0c4bc;
	color: #7a2a1e; border-radius: 3px;
	padding: 10px 14px; font-size: 13px;
	margin-bottom: 28px;
}
.errors ul { margin: 4px 0 0 16px; }

/* ── Sections ── */
.form-section {
	margin-bottom: 40px;
	padding-bottom: 40px;
	border-bottom: 1px solid var(--border);
}
.form-section:last-of-type { border-bottom: none; }
.form-section-title {
	font-size: 11px; font-weight: 700;
	letter-spacing: 0.1em; text-transform: uppercase;
	color: var(--text-faint); font-family: var(--mono);
	margin-bottom: 20px;
}

/* ── Field ── */
.field { margin-bottom: 18px; }
.field:last-child { margin-bottom: 0; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
label {
	display: block; font-size: 12px; font-weight: 600;
	letter-spacing: 0.03em; color: var(--text-muted);
	margin-bottom: 5px;
}
.label-hint {
	font-weight: 400; color: var(--text-faint);
	font-size: 11px; margin-left: 6px;
}
input[type="text"],
input[type="datetime-local"],
input[type="number"],
textarea,
select {
	display: block; width: 100%;
	padding: 9px 11px; font-size: 14px;
	font-family: var(--sys); color: var(--text);
	background: #fff; border: 1px solid var(--border);
	border-radius: 3px; outline: none;
	transition: border-color 0.15s;
}
input:focus, textarea:focus, select:focus {
	border-color: var(--text-muted);
}
input[type="text"].mono,
input.mono { font-family: var(--mono); font-size: 13px; }
textarea { min-height: 80px; resize: vertical; line-height: 1.6; }
.field-hint {
	font-size: 12px; color: var(--text-faint);
	margin-top: 4px;
}

/* ── Radio group ── */
.radio-group { display: flex; gap: 20px; padding-top: 2px; }
.radio-group label {
	display: flex; align-items: center; gap: 6px;
	font-size: 14px; font-weight: 400; letter-spacing: 0;
	color: var(--text); cursor: pointer; margin-bottom: 0;
}
.radio-group input[type="radio"] { width: auto; }

/* ── Map ── */
#map-preview {
	height: 280px; border-radius: 3px;
	border: 1px solid var(--border);
	margin-bottom: 12px;
}
.map-coords {
	display: grid; grid-template-columns: 1fr 1fr 80px; gap: 10px;
}

/* ── Senders ── */
.sender-row {
	display: grid; grid-template-columns: 1fr 1fr auto;
	gap: 10px; align-items: end; margin-bottom: 10px;
}
.sender-row:last-child { margin-bottom: 0; }
.btn-remove {
	display: flex; align-items: center; justify-content: center;
	width: 32px; height: 36px; padding: 0;
	background: none; border: 1px solid var(--border);
	border-radius: 3px; cursor: pointer; color: var(--text-faint);
	font-size: 16px; transition: color 0.15s, border-color 0.15s;
	flex-shrink: 0;
}
.btn-remove:hover { color: var(--bcc-red); border-color: var(--bcc-red); }
.btn-add-sender {
	display: inline-flex; align-items: center; gap: 6px;
	margin-top: 12px; font-size: 13px; font-weight: 600;
	color: var(--text-muted); background: none; border: none;
	cursor: pointer; padding: 0; transition: color 0.15s;
}
.btn-add-sender:hover { color: var(--text); }

/* ── Submit ── */
.form-actions {
	display: flex; align-items: center; gap: 16px;
	padding-top: 8px;
}
.btn-primary {
	padding: 10px 24px; font-size: 14px; font-weight: 600;
	font-family: var(--sys); color: #fff;
	background: var(--text); border: none; border-radius: 3px;
	cursor: pointer; transition: opacity 0.15s;
}
.btn-primary:hover { opacity: 0.8; }
.btn-link {
	font-size: 13px; color: var(--text-muted);
	text-decoration: none;
}
.btn-link:hover { color: var(--text); }

/* ── Tracker reveal ── */
#tracker-fields { display: none; }
#tracker-fields.visible { display: block; }
</style>
</head>
<body>

<div id="topbar">
	<nav class="topbar__nav">
		<a href="/trips/" class="topbar__back">
			<svg width="14" height="10" viewBox="0 0 14 10" fill="none" style="margin-right:6px"><path d="M13 5H1M1 5L5 1M1 5L5 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
			Trips
		</a>
		<span class="topbar__sep">·</span>
		<span class="topbar__current"><?= $page_title ?></span>
	</nav>
</div>

<div id="page">

<?php if ($flash): ?>
<div class="flash"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="errors">
	<strong>Please fix the following:</strong>
	<ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST">

	<!-- ── Basic info ── -->
	<div class="form-section">
		<div class="form-section-title">Basic Info</div>

		<div class="field">
			<label for="name">Name</label>
			<input type="text" id="name" name="name"
				   value="<?= htmlspecialchars($v['name']) ?>"
				   placeholder="James Peak">
		</div>

		<div class="field">
			<label for="subtitle">Subtitle <span class="label-hint">location or area</span></label>
			<input type="text" id="subtitle" name="subtitle"
				   value="<?= htmlspecialchars($v['subtitle']) ?>"
				   placeholder="James Peak Wilderness">
		</div>

		<div class="field">
			<label for="slug">Slug</label>
			<input type="text" id="slug" name="slug" class="mono"
				   value="<?= htmlspecialchars($v['slug']) ?>"
				   placeholder="james-peak-scouts">
			<p class="field-hint">Auto-generated from name. URL: /{year}/{slug}</p>
		</div>

		<div class="field-row">
			<div class="field">
				<label for="started_at">Start date</label>
				<input type="date" id="started_at" name="started_at"
					   value="<?= htmlspecialchars(substr($v['started_at'], 0, 10)) ?>">
			</div>
			<div class="field">
				<label for="ended_at">End date</label>
				<input type="date" id="ended_at" name="ended_at"
					   value="<?= htmlspecialchars(substr($v['ended_at'], 0, 10)) ?>">
			</div>
		</div>

		<div class="field">
			<label for="description">Description <span class="label-hint">optional, markdown</span></label>
			<textarea id="description" name="description" placeholder="Trip notes…"><?= htmlspecialchars($v['description']) ?></textarea>
		</div>
	</div>

	<!-- ── Live tracking ── -->
	<div class="form-section">
		<div class="form-section-title">Live Tracking</div>

		<div class="field">
			<label>Device</label>
			<div class="radio-group">
				<?php foreach (['none' => 'None', 'spot' => 'Spot Gen3', 'inreach' => 'Garmin inReach'] as $val => $label): ?>
				<label>
					<input type="radio" name="tracker_type" value="<?= $val ?>"
						   <?= ($v['tracker_type'] ?: 'none') === $val ? 'checked' : '' ?>>
					<?= $label ?>
				</label>
				<?php endforeach; ?>
			</div>
		</div>

		<div id="tracker-fields" class="<?= ($v['tracker_type'] && $v['tracker_type'] !== 'none') ? 'visible' : '' ?>">
			<div class="field">
				<label for="tracker_id">Feed URL / Share ID</label>
				<input type="text" id="tracker_id" name="tracker_id" class="mono"
					   value="<?= htmlspecialchars($v['tracker_id']) ?>"
					   placeholder="https://share.garmin.com/… or Spot feed URL">
				<p class="field-hint">Polling starts at midnight before day 1 and stops at midnight after the last day.</p>
			</div>
		</div>
	</div>

	<!-- ── Map preview ── -->
	<div class="form-section">
		<div class="form-section-title">Map Preview <span class="label-hint" style="text-transform:none;font-size:11px">drag and zoom to set the pre-trip map</span></div>

		<div id="map-preview"></div>

		<div class="map-coords">
			<div class="field" style="margin-bottom:0">
				<label for="map_lat">Latitude</label>
				<input type="text" id="map_lat" name="map_lat" class="mono"
					   value="<?= htmlspecialchars($v['map_lat']) ?>"
					   placeholder="39.902">
			</div>
			<div class="field" style="margin-bottom:0">
				<label for="map_lon">Longitude</label>
				<input type="text" id="map_lon" name="map_lon" class="mono"
					   value="<?= htmlspecialchars($v['map_lon']) ?>"
					   placeholder="-105.643">
			</div>
			<div class="field" style="margin-bottom:0">
				<label for="map_zoom">Zoom</label>
				<input type="number" id="map_zoom" name="map_zoom"
					   value="<?= htmlspecialchars($v['map_zoom'] ?: 12) ?>"
					   min="1" max="18">
			</div>
		</div>
	</div>

	<!-- ── Allowed senders ── -->
	<div class="form-section">
		<div class="form-section-title">Allowed Senders <span class="label-hint" style="text-transform:none;font-size:11px">phone numbers that can text in to this trip</span></div>

		<div id="senders-list">
<?php
$form_senders = !empty($errors) && isset($_POST['sender_phone'])
	? array_map(null, $_POST['sender_phone'] ?? [], $_POST['sender_name'] ?? [])
	: array_map(fn($s) => [$s['phone'], $s['name']], $existing_senders);
if (empty($form_senders)) $form_senders = [['', '']];
foreach ($form_senders as $idx => [$phone, $name]):
?>
			<div class="sender-row">
				<div class="field" style="margin-bottom:0">
					<?php if ($idx === 0): ?><label>Phone number</label><?php endif; ?>
					<input type="text" name="sender_phone[]" class="mono"
						   value="<?= htmlspecialchars($phone ?? '') ?>"
						   placeholder="+1 303 555 0100">
				</div>
				<div class="field" style="margin-bottom:0">
					<?php if ($idx === 0): ?><label>Name <span class="label-hint">optional</span></label><?php endif; ?>
					<input type="text" name="sender_name[]"
						   value="<?= htmlspecialchars($name ?? '') ?>"
						   placeholder="Chris">
				</div>
				<button type="button" class="btn-remove" onclick="removeSender(this)" title="Remove">×</button>
			</div>
<?php endforeach; ?>
		</div>

		<button type="button" class="btn-add-sender" onclick="addSender()">
			+ Add sender
		</button>
	</div>

	<!-- ── Actions ── -->
	<div class="form-actions">
		<button type="submit" class="btn-primary">
			<?= $is_new ? 'Create Trip' : 'Save Changes' ?>
		</button>
		<?php if (!$is_new): ?>
		<a href="/<?= $trip['year'] ?>/<?= $trip['slug'] ?>" class="btn-link">View trip →</a>
		<?php endif; ?>
	</div>

</form>
</div>

<?php if (!$is_new): ?>
<div id="page" style="padding-top:0">
	<div class="form-section">
		<div class="form-section-title">Photos</div>
		<div id="upload-zone" style="
			border: 1.5px dashed var(--border); border-radius: 4px;
			padding: 20px 18px; cursor: pointer;
			display: flex; align-items: center; gap: 10px;
			transition: border-color 0.2s, background 0.2s;
			font-family: var(--mono); font-size: 0.75rem;
			color: var(--text-faint); letter-spacing: 0.05em; text-transform: uppercase;
		">
			<span style="font-size:1.1rem; opacity:0.4">⛰</span>
			<span>Drop photos or click to upload</span>
			<input type="file" id="upload-input" multiple accept="image/jpeg" style="display:none">
		</div>
		<div id="upload-queue" style="margin-top:6px; display:flex; flex-direction:column; gap:3px;"></div>
	</div>
</div>
<?php endif; ?>

<script>
const MAPBOX_TOKEN = <?= json_encode($mapbox_token) ?>;

// ── Slug auto-generation ─────────────────────────────────────────────────
const nameInput = document.getElementById('name');
const slugInput = document.getElementById('slug');
let slugManuallyEdited = <?= json_encode(!$is_new || $v['slug'] !== '') ?>;

nameInput.addEventListener('input', () => {
	if (slugManuallyEdited) return;
	slugInput.value = nameInput.value
		.toLowerCase().trim()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-|-$/g, '');
});
slugInput.addEventListener('input', () => { slugManuallyEdited = true; });

// ── Tracker type show/hide ────────────────────────────────────────────────
const trackerFields = document.getElementById('tracker-fields');
document.querySelectorAll('input[name="tracker_type"]').forEach(radio => {
	radio.addEventListener('change', () => {
		trackerFields.classList.toggle('visible', radio.value !== 'none');
	});
});

// ── Senders ──────────────────────────────────────────────────────────────
function senderRowHTML(isFirst) {
	return `<div class="sender-row">
		<div class="field" style="margin-bottom:0">
			${isFirst ? '' /* labels only on first row */ : ''}
			<input type="text" name="sender_phone[]" class="mono" placeholder="+1 303 555 0100">
		</div>
		<div class="field" style="margin-bottom:0">
			<input type="text" name="sender_name[]" placeholder="Chris">
		</div>
		<button type="button" class="btn-remove" onclick="removeSender(this)" title="Remove">×</button>
	</div>`;
}

function addSender() {
	const list = document.getElementById('senders-list');
	const div = document.createElement('div');
	div.innerHTML = senderRowHTML(false);
	list.appendChild(div.firstElementChild);
	list.lastElementChild.querySelector('input').focus();
}

function removeSender(btn) {
	const list = document.getElementById('senders-list');
	const row = btn.closest('.sender-row');
	// Always keep at least one row
	if (list.querySelectorAll('.sender-row').length > 1) {
		row.remove();
	} else {
		row.querySelectorAll('input').forEach(i => i.value = '');
	}
}

const latInput  = document.getElementById('map_lat');
const lonInput  = document.getElementById('map_lon');
const zoomInput = document.getElementById('map_zoom');

const defaultLat  = parseFloat(latInput.value)  || 39.9;
const defaultLon  = parseFloat(lonInput.value)  || -105.6;
const defaultZoom = parseInt(zoomInput.value)   || 12;

const map = L.map('map-preview', {
	zoomControl: true,
	attributionControl: false,
	scrollWheelZoom: true,
}).setView([defaultLat, defaultLon], defaultZoom);

L.tileLayer(
	`https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/256/{z}/{x}/{y}@2x?access_token=${MAPBOX_TOKEN}`,
	{ tileSize: 256, maxZoom: 18 }
).addTo(map);

// Sync map position → hidden inputs
function syncMap() {
	const c = map.getCenter();
	latInput.value  = c.lat.toFixed(6);
	lonInput.value  = c.lng.toFixed(6);
	zoomInput.value = map.getZoom();
}
map.on('moveend', syncMap);
map.on('zoomend', syncMap);

// Sync manual input → map
[latInput, lonInput].forEach(inp => {
	inp.addEventListener('change', () => {
		const lat = parseFloat(latInput.value);
		const lon = parseFloat(lonInput.value);
		if (!isNaN(lat) && !isNaN(lon)) map.setView([lat, lon], map.getZoom());
	});
});
zoomInput.addEventListener('change', () => {
	const z = parseInt(zoomInput.value);
	if (!isNaN(z)) map.setZoom(z);
});

<?php if (!$is_new): ?>
// ── Photo upload ──────────────────────────────────────────────────────────
const TRIP_YEAR = <?= json_encode((int)$trip['year']) ?>;
const TRIP_SLUG = <?= json_encode($trip['slug']) ?>;

const uploadZone  = document.getElementById('upload-zone');
const uploadInput = document.getElementById('upload-input');
const uploadQueue = document.getElementById('upload-queue');

uploadZone.addEventListener('click', () => uploadInput.click());
uploadInput.addEventListener('change', e => handleUpload([...e.target.files]));
uploadZone.addEventListener('dragover', e => {
	e.preventDefault();
	uploadZone.style.borderColor = 'var(--text-faint)';
	uploadZone.style.background  = 'rgba(26,25,22,0.02)';
});
uploadZone.addEventListener('dragleave', () => {
	uploadZone.style.borderColor = '';
	uploadZone.style.background  = '';
});
uploadZone.addEventListener('drop', e => {
	e.preventDefault();
	uploadZone.style.borderColor = '';
	uploadZone.style.background  = '';
	handleUpload([...e.dataTransfer.files].filter(f => f.type === 'image/jpeg'));
});

async function handleUpload(files) {
	if (!files.length) return;
	const items = files.map(file => {
		const el = document.createElement('div');
		el.style.cssText = 'font-family:var(--mono);font-size:0.72rem;color:var(--text-muted);display:flex;gap:8px;padding:3px 0;';
		el.innerHTML = `<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${file.name}</span><span class="status">waiting</span>`;
		uploadQueue.appendChild(el);
		return { file, el };
	});

	let anyDone = false;
	for (const { file, el } of items) {
		const statusEl = el.querySelector('.status');
		statusEl.textContent = 'uploading…';
		try {
			const fd = new FormData();
			fd.append('photo', file);
			fd.append('year', TRIP_YEAR);
			fd.append('slug', TRIP_SLUG);
			const res  = await fetch('/api/upload-photo', { method: 'POST', body: fd });
			const text = await res.text();
			let json;
			try { json = JSON.parse(text); } catch(e) { throw new Error(`Server error (${res.status})`); }
			if (json.ok) {
				const tierLabels = ['', 'GPS', 'GPX', 'manual', '—'];
				statusEl.textContent = `✓ day ${json.day ?? '?'} · ${tierLabels[json.tier] ?? '?'}`;
				statusEl.style.color = '#4a7a4a';
				anyDone = true;
			} else {
				throw new Error(json.error || 'Upload failed');
			}
		} catch (err) {
			statusEl.textContent = `✗ ${err.message}`;
			statusEl.style.color = 'var(--red)';
		}
	}
	// Don't reload — stay on the edit page
}
<?php endif; ?>
</script>

</body>
</html>