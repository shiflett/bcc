<?php
/**
 * Admin — trips index
 * GET /admin
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_admin_auth();

$pdo = db();

$trips = $pdo->query('
	SELECT
		t.*,
		COUNT(DISTINCT td.id) AS day_count,
		COUNT(DISTINCT m.id)  AS photo_count,
		COUNT(DISTINCT tp.id) AS point_count
	FROM trips t
	LEFT JOIN trip_days td  ON td.trip_id = t.id
	LEFT JOIN media m       ON m.trip_id  = t.id
	LEFT JOIN trackpoints tp ON tp.trip_id = t.id
	GROUP BY t.id
	ORDER BY t.started_at DESC NULLS LAST, t.created_at DESC
')->fetchAll();

// Derive status for each trip
$now = new DateTimeImmutable();
foreach ($trips as &$trip) {
	$start = $trip['started_at'] ? new DateTimeImmutable($trip['started_at']) : null;
	$end   = $trip['ended_at']   ? new DateTimeImmutable($trip['ended_at'])   : null;
	if (!$start) {
		$trip['_status'] = 'draft';
	} elseif ($now < $start) {
		$trip['_status'] = 'upcoming';
	} elseif ($end && $now > $end) {
		$trip['_status'] = 'completed';
	} else {
		$trip['_status'] = 'active';
	}
}
unset($trip);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trips — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
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
	display: flex; align-items: center; justify-content: space-between;
	padding: 0 20px 0 24px;
}
.topbar__nav {
	display: flex; align-items: center; gap: 6px;
	font-family: var(--mono); font-size: 1rem;
}
.topbar__mark img { height: 22px; width: auto; display: block; }
.topbar__site {
	display: flex; align-items: center; gap: 10px;
	text-decoration: none; color: var(--text);
	font-size: 14px; font-weight: 600;
}
.btn-new {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 7px 14px; font-size: 13px; font-weight: 600;
	font-family: var(--sys); color: #fff;
	background: var(--text); border: none; border-radius: 3px;
	text-decoration: none; transition: opacity 0.15s;
}
.btn-new:hover { opacity: 0.8; }

/* ── Page ── */
#page {
	max-width: 860px; margin: 0 auto;
	padding: 40px 24px 80px;
}

/* ── Empty state ── */
.empty {
	padding: 60px 0; text-align: center;
	color: var(--text-faint); font-size: 14px;
}
.empty a {
	color: var(--bcc-red); text-decoration: none; font-weight: 600;
}
.empty a:hover { text-decoration: underline; }

/* ── Trip list ── */
.trip-list { display: flex; flex-direction: column; gap: 1px; }

.trip-row {
	display: grid;
	grid-template-columns: 1fr auto;
	align-items: center;
	gap: 16px;
	padding: 16px 0;
	border-bottom: 1px solid var(--border);
	text-decoration: none; color: inherit;
}
.trip-row:first-child { border-top: 1px solid var(--border); }
.trip-row:hover .trip-name { color: var(--bcc-red); }

.trip-main { min-width: 0; }

.trip-name {
	font-size: 1rem; font-weight: 600;
	letter-spacing: -0.01em;
	margin-bottom: 2px;
	transition: color 0.15s;
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.trip-sub {
	font-size: 13px; color: var(--text-muted);
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.trip-meta {
	display: flex; align-items: center; gap: 12px;
	margin-top: 6px;
	font-size: 11px; font-family: var(--mono);
	color: var(--text-faint);
}
.trip-meta-sep { color: var(--border); }

.trip-right {
	display: flex; align-items: center; gap: 12px;
	flex-shrink: 0;
}

/* Status badges */
.status {
	display: inline-block;
	padding: 2px 8px; border-radius: 2px;
	font-size: 10px; font-weight: 700;
	letter-spacing: 0.08em; text-transform: uppercase;
	font-family: var(--mono);
}
.status-draft    { background: var(--bg-col); color: var(--text-faint); }
.status-upcoming { background: #eef3fa; color: #3a5a8a; }
.status-active   { background: #fff0ee; color: var(--red); }
.status-completed { background: #eef5ee; color: #3a6a3a; }

.trip-edit-link {
	font-size: 12px; font-weight: 600;
	color: var(--text-faint); text-decoration: none;
	font-family: var(--mono);
	transition: color 0.15s;
	white-space: nowrap;
}
.trip-edit-link:hover { color: var(--text); }
</style>
</head>
<body>

<div id="topbar">
	<a class="topbar__site" href="/">
		<span class="topbar__mark"><img src="/images/bcc-red.svg" alt="BCC"></span>
		Backcountry Club
	</a>
	<a href="/admin/new" class="btn-new">+ New Trip</a>
</div>

<div id="page">

<?php if (empty($trips)): ?>
	<div class="empty">
		<p>No trips yet. <a href="/admin/new">Create your first trip →</a></p>
	</div>
<?php else: ?>
	<div class="trip-list">
	<?php foreach ($trips as $trip):
		$dates = '';
		if ($trip['started_at']) {
			$s = new DateTime($trip['started_at']);
			$dates = $s->format('M j, Y');
			if ($trip['ended_at']) {
				$e = new DateTime($trip['ended_at']);
				if ($s->format('Y') === $e->format('Y')) {
					$dates .= ' – ' . $e->format('M j');
				} else {
					$dates .= ' – ' . $e->format('M j, Y');
				}
			}
		}
		$days   = (int)$trip['day_count'];
		$photos = (int)$trip['photo_count'];
		$points = (int)$trip['point_count'];
		$status = $trip['_status'];
	?>
		<a class="trip-row" href="/admin/<?= htmlspecialchars($trip['token']) ?>">
			<div class="trip-main">
				<div class="trip-name"><?= htmlspecialchars($trip['name']) ?></div>
				<?php if ($trip['subtitle']): ?>
				<div class="trip-sub"><?= htmlspecialchars($trip['subtitle']) ?></div>
				<?php endif; ?>
				<div class="trip-meta">
					<?php if ($dates): ?>
					<span><?= $dates ?></span>
					<?php endif; ?>
					<?php if ($days): ?>
					<span class="trip-meta-sep">·</span>
					<span><?= $days ?> day<?= $days !== 1 ? 's' : '' ?></span>
					<?php endif; ?>
					<?php if ($photos): ?>
					<span class="trip-meta-sep">·</span>
					<span><?= number_format($photos) ?> photos</span>
					<?php endif; ?>
					<?php if ($points): ?>
					<span class="trip-meta-sep">·</span>
					<span><?= number_format($points) ?> trackpoints</span>
					<?php endif; ?>
					<?php if ($trip['token']): ?>
					<span class="trip-meta-sep">·</span>
					<span><?= htmlspecialchars($trip['token']) ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="trip-right">
				<span class="status status-<?= $status ?>"><?= $status ?></span>
				<span class="trip-edit-link">Edit →</span>
			</div>
		</a>
	<?php endforeach; ?>
	</div>
<?php endif; ?>

</div>
</body>
</html>