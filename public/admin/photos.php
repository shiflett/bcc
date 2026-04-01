<?php
// public/admin/photos.php
// Drag-and-drop photo upload for a trip.
// Usage: /admin/photos/2024/james-peak-scouts

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';

// Parse year/slug from URL — router passes them via $_GET
$year = (int)($_GET['year'] ?? 0);
$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');

$stmt = db()->prepare("SELECT * FROM trips WHERE year = ? AND slug = ?");
$stmt->execute([$year, $slug]);
$trip = $stmt->fetch();

if (!$trip) {
	http_response_code(404); require __DIR__ . '/../pages/404.php'; exit;
}

// Fetch already-imported photos for this trip
$photoStmt = db()->prepare("
	SELECT * FROM media
	WHERE trip_id = ? AND kind = 'photo'
	ORDER BY taken_at, display_order
");
$photoStmt->execute([$trip['id']]);
$photos = $photoStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Upload Photos — <?= htmlspecialchars($trip['name']) ?></title>
	<link rel="stylesheet" href="/css/app.css">
	<style>
		.upload-area {
			border: 2px dashed var(--border-light);
			border-radius: var(--radius-lg);
			padding: 60px 40px;
			text-align: center;
			cursor: pointer;
			transition: border-color 0.2s, background 0.2s;
			margin-bottom: 32px;
		}
		.upload-area.drag-over {
			border-color: var(--accent);
			background: rgba(200, 146, 42, 0.05);
		}
		.upload-area__icon {
			font-size: 2.5rem;
			margin-bottom: 12px;
			opacity: 0.4;
		}
		.upload-area__label {
			font-family: var(--font-mono);
			font-size: 0.75rem;
			color: var(--text-muted);
			letter-spacing: 0.06em;
			text-transform: uppercase;
		}
		.upload-area__sub {
			font-size: 0.8rem;
			color: var(--text-faint);
			margin-top: 6px;
		}

		.queue {
			display: flex;
			flex-direction: column;
			gap: 4px;
			margin-bottom: 32px;
		}
		.queue-item {
			display: grid;
			grid-template-columns: 48px 1fr auto;
			align-items: center;
			gap: 12px;
			background: var(--bg-card);
			border: 1px solid var(--border);
			padding: 8px 12px;
			border-radius: var(--radius);
			font-family: var(--font-mono);
			font-size: 0.7rem;
		}
		.queue-item__thumb {
			width: 48px;
			height: 36px;
			object-fit: cover;
			border-radius: 2px;
			background: var(--bg-raised);
		}
		.queue-item__name { color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.queue-item__status { color: var(--text-faint); flex-shrink: 0; }
		.queue-item--done .queue-item__status { color: var(--green); }
		.queue-item--error .queue-item__status { color: var(--red); }
		.queue-item--uploading .queue-item__status { color: var(--accent); }

		.photo-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
			gap: 4px;
			margin-bottom: 40px;
		}
		.photo-thumb {
			object-fit: cover;
			border-radius: var(--radius);
			background: var(--bg-card);
		}
		.photo-meta {
			font-family: var(--font-mono);
			font-size: 0.6rem;
			color: var(--text-faint);
			padding: 4px 0;
		}
		.tier-badge {
			display: inline-block;
			padding: 1px 5px;
			border-radius: 2px;
			font-size: 0.6rem;
		}
		.tier-1 { background: rgba(90,138,90,0.2); color: var(--green); }
		.tier-2 { background: rgba(200,146,42,0.15); color: var(--accent); }
		.tier-4 { background: rgba(100,100,100,0.15); color: var(--text-faint); }

		.btn {
			font-family: var(--font-mono);
			font-size: 0.7rem;
			letter-spacing: 0.06em;
			text-transform: uppercase;
			padding: 10px 20px;
			border: 1px solid var(--accent);
			background: transparent;
			color: var(--accent);
			border-radius: var(--radius);
			cursor: pointer;
			transition: background 0.15s;
		}
		.btn:hover { background: rgba(200,146,42,0.1); }
		.btn:disabled { opacity: 0.4; cursor: default; }
		.btn-primary { background: var(--accent); color: var(--bg); }
		.btn-primary:hover { background: #d4a040; }

		#upload-input { display: none; }
		.progress-bar {
			height: 2px;
			background: var(--border);
			border-radius: 1px;
			margin-bottom: 24px;
			overflow: hidden;
			display: none;
		}
		.progress-bar__fill {
			height: 100%;
			background: var(--accent);
			transition: width 0.3s;
			width: 0%;
		}
	</style>
</head>
<body>

<header class="site-header">
	<div class="container">
		<div class="site-header__inner">
			<div class="site-logo">
				<a href="/"><span class="site-logo__mark">▲</span>Backcountry Club</a>
			</div>
		</div>
	</div>
</header>

<main class="container">
	<nav class="breadcrumb">
		<a href="/">Trips</a>
		<span class="breadcrumb__sep">/</span>
		<a href="/<?= $year ?>/<?= $slug ?>"><?= htmlspecialchars($trip['name']) ?></a>
		<span class="breadcrumb__sep">/</span>
		<span>Upload Photos</span>
	</nav>

	<div class="trip-header">
		<h1 class="trip-header__name">Upload Photos</h1>
		<div class="trip-header__subtitle"><?= htmlspecialchars($trip['name']) ?> · <?= htmlspecialchars($trip['subtitle'] ?? '') ?></div>
	</div>

	<div class="upload-area" id="drop-zone">
		<div class="upload-area__icon">⛰</div>
		<div class="upload-area__label">Drop photos here</div>
		<div class="upload-area__sub">or click to select · JPEG · GPS extracted automatically</div>
		<input type="file" id="upload-input" multiple accept="image/jpeg,image/heic">
	</div>

	<div class="progress-bar" id="progress-bar">
		<div class="progress-bar__fill" id="progress-fill"></div>
	</div>

	<div class="queue" id="queue"></div>

	<?php if ($photos): ?>
	<h2 style="font-family: var(--font-display); font-size: 1.2rem; margin-bottom: 16px;">
		Imported (<?= count($photos) ?>)
	</h2>
	<div class="photo-grid">
		<?php foreach ($photos as $p): ?>
		<div>
			<img class="photo-thumb"
				 src="/uploads/<?= $trip['id'] ?>/thumbs/<?= htmlspecialchars($p['filename']) ?>"
				 alt=""
				 loading="lazy">
			<div class="photo-meta">
				<?php if ($p['taken_at']): ?>
					<?= date('M j, g:ia', strtotime($p['taken_at'])) ?>
				<?php endif ?>
				<?php if ($p['placement_tier']): ?>
					<span class="tier-badge tier-<?= $p['placement_tier'] ?>">
						<?= ['', 'GPS', 'GPX', 'manual', '—'][$p['placement_tier']] ?>
					</span>
				<?php endif ?>
			</div>
		</div>
		<?php endforeach ?>
	</div>
	<?php endif ?>
</main>

<footer class="site-footer">
	<div class="container">
		<div class="site-footer__inner">
			<span>© <?= date('Y') ?> Backcountry Club</span>
		</div>
	</div>
</footer>

<script>
const TRIP_YEAR = <?= json_encode($year) ?>;
const TRIP_SLUG = <?= json_encode($slug) ?>;
const UPLOAD_URL = '/api/upload-photo';

const dropZone   = document.getElementById('drop-zone');
const fileInput  = document.getElementById('upload-input');
const queue      = document.getElementById('queue');
const progressBar  = document.getElementById('progress-bar');
const progressFill = document.getElementById('progress-fill');

// Click to open file picker
dropZone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e => handleFiles([...e.target.files]));

// Drag and drop
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
	e.preventDefault();
	dropZone.classList.remove('drag-over');
	handleFiles([...e.dataTransfer.files].filter(f => f.type === 'image/jpeg' || f.name.toLowerCase().endsWith('.heic')));
});

async function handleFiles(files) {
	if (!files.length) return;

	progressBar.style.display = 'block';
	let done = 0;

	// Build queue UI
	const items = files.map(file => {
		const el = document.createElement('div');
		el.className = 'queue-item';
		el.innerHTML = `
			<div class="queue-item__thumb-wrap"></div>
			<div class="queue-item__name">${file.name}</div>
			<div class="queue-item__status">waiting</div>
		`;
		queue.prepend(el);

		// Show thumbnail preview
		const reader = new FileReader();
		reader.onload = e => {
			const img = document.createElement('img');
			img.src = e.target.result;
			img.className = 'queue-item__thumb';
			el.querySelector('.queue-item__thumb-wrap').appendChild(img);
		};
		reader.readAsDataURL(file);

		return { file, el };
	});

	// Upload sequentially to avoid overwhelming the server
	for (const { file, el } of items) {
		const statusEl = el.querySelector('.queue-item__status');
		el.classList.add('queue-item--uploading');
		statusEl.textContent = 'uploading…';

		try {
			const formData = new FormData();
			formData.append('photo', file);
			formData.append('year', TRIP_YEAR);
			formData.append('slug', TRIP_SLUG);

			const res = await fetch(UPLOAD_URL, { method: 'POST', body: formData });
			const json = await res.json();

			if (json.ok) {
				el.classList.remove('queue-item--uploading');
				el.classList.add('queue-item--done');
				const tierLabels = ['', 'GPS', 'GPX↗', 'manual', '—'];
				statusEl.textContent = `✓ tier ${json.tier} (${tierLabels[json.tier] || '?'})`;
			} else {
				throw new Error(json.error || 'Upload failed');
			}
		} catch (err) {
			el.classList.remove('queue-item--uploading');
			el.classList.add('queue-item--error');
			statusEl.textContent = `✗ ${err.message}`;
		}

		done++;
		progressFill.style.width = (done / items.length * 100) + '%';
	}

	// Reload after a beat so the imported grid updates
	setTimeout(() => location.reload(), 1200);
}
</script>
</body>
</html>