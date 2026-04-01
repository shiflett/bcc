<?php
/**
 * Backcountry Club — trip index
 * Served at /
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';

$is_admin = is_admin();

$pdo = db();

// All trips, most recent first
$stmt = $pdo->query('
    SELECT
        t.*,
        COUNT(DISTINCT td.id)  AS day_count,
        SUM(td.gain_m)         AS total_gain_m,
        SUM(td.distance_m)     AS total_dist_m,
        COUNT(DISTINCT m.id)   AS photo_count
    FROM trips t
    LEFT JOIN trip_days td ON td.trip_id = t.id
    LEFT JOIN media m      ON m.trip_id  = t.id
    GROUP BY t.id
    ORDER BY t.started_at DESC
');
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a static map URL for each trip using a simplified full-track overview
$mapbox_token = json_encode(getenv('BCC_MAPBOX') ?: '');

function trip_static_map(int $trip_id, string $token, $pdo): string {
    $stmt = $pdo->prepare('
        SELECT lat, lon FROM trackpoints
        WHERE trip_id = :trip_id
        ORDER BY recorded_at ASC
    ');
    $stmt->execute([':trip_id' => $trip_id]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($all)) return '';

    // Simplify to 80 points
    $max  = 80;
    $n    = count($all);
    $step = ($n - 1) / ($max - 1);
    $pts  = [];
    for ($i = 0; $i < $max && $i < $n; $i++) $pts[] = $all[(int)round($i * $step)];

    $coords = array_map(fn($p) => [(float)$p['lon'], (float)$p['lat']], $pts);
    $geojson = json_encode([
        'type'       => 'Feature',
        'properties' => ['stroke' => '#E4572E', 'stroke-width' => 3, 'stroke-opacity' => 0.9],
        'geometry'   => ['type' => 'LineString', 'coordinates' => $coords],
    ]);
    $overlay = 'geojson(' . rawurlencode($geojson) . ')';

    $lats = array_column($pts, 'lat');
    $lons = array_column($pts, 'lon');
    $bbox = '[' . min($lons) . ',' . min($lats) . ',' . max($lons) . ',' . max($lats) . ']';

    return "https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/{$overlay}/{$bbox}/600x340@2x?padding=24&access_token={$token}";
}

$trip_maps = [];
foreach ($trips as $trip) {
    $trip_maps[(int)$trip['id']] = trip_static_map((int)$trip['id'], $mapbox_token, $pdo);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backcountry Club</title>

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
    --sys:        -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    --mono:       'JetBrains Mono', 'Courier New', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    min-height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: var(--sys);
    font-size: 15px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}

/* ── Topbar removed — not needed on index ── */

/* ── Page layout ── */
#page {
    max-width: 980px;
    margin: 0 auto;
    padding: 0 32px 0;
}

@media (min-width: 900px) {
    #page { padding-left: 80px; }
    #site-footer { padding-left: 80px; text-align: left; }
}

/* ── Masthead ── */
#masthead {
    padding: 56px 0 48px;
    border-bottom: 1px solid var(--border);
}

.masthead-lockup {
    display: inline-flex;
    align-items: flex-end;
    gap: 16px;
    margin-bottom: 20px;
    line-height: 1;
}
.masthead-mark {
    display: inline-block;
    flex-shrink: 0;
}
.masthead-mark img {
    display: block;
    height: 50px; width: auto;
    transform: translateY(-4px);
}
.masthead-name {
    font-family: var(--sys);
    font-size: 3rem; font-weight: 600;
    letter-spacing: -0.03em; line-height: 1;
    color: var(--text);
}
.masthead-tagline {
    font-size: 1.1rem; font-weight: 600;
    color: #5a6b5a;
    margin-bottom: 16px;
    font-style: italic;
}
.masthead-copy {
    font-size: 15px; line-height: 1.7;
    color: var(--text-muted); font-weight: 400;
    max-width: 520px;
}

/* ── Trip grid ── */
#trips-section {
    padding-top: 40px;
    padding-bottom: 40px;
}
#trips-heading {
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.1em; text-transform: uppercase;
    color: var(--text-faint);
    margin-bottom: 20px;
    font-family: var(--mono);
}
#trip-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

/* ── Trip card ── */
.trip-card {
    display: block; text-decoration: none; color: inherit;
    border: 1px solid var(--border);
    border-radius: 3px; overflow: hidden;
    background: var(--bg);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.trip-card:hover {
    border-color: var(--text-faint);
    box-shadow: 0 3px 14px rgba(26,25,22,0.09);
}
.trip-card-map {
    width: 100%; height: 160px;
    overflow: hidden; background: var(--bg-col);
}
.trip-card-map img {
    width: 100%; height: 100%;
    object-fit: cover; display: block;
    transition: transform 0.4s ease;
}
.trip-card:hover .trip-card-map img { transform: scale(1.02); }
.trip-card-map-empty {
    width: 100%; height: 160px; background: var(--bg-col);
}
.trip-card-body { padding: 14px 16px 16px; }
.trip-card-year {
    font-size: 10px; font-weight: 600; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--text-faint);
    margin-bottom: 4px; font-family: var(--mono);
}
.trip-card-name {
    font-size: 1.05rem; font-weight: 600;
    letter-spacing: -0.01em; line-height: 1.25;
    margin-bottom: 3px;
}
.trip-card-subtitle {
    font-size: 13px; color: var(--text-muted); margin-bottom: 12px;
}
.trip-card-meta {
    display: flex; justify-content: space-between;
    font-size: 12px; color: var(--text-faint);
    padding-top: 10px; border-top: 1px solid var(--border);
    font-family: var(--mono);
}

/* ── Invitation sections ── */
.invite-section {
    padding: 40px 0;
    border-top: 1px solid var(--border);
}
.invite-section h2 {
    font-size: 1.1rem; font-weight: 600;
    letter-spacing: -0.01em;
    margin-bottom: 12px;
    color: var(--text);
    max-width: 520px;
}
.invite-section p {
    font-size: 15px; line-height: 1.7;
    color: var(--text-muted);
    margin-bottom: 16px;
    max-width: 520px;
}
.invite-link {
    display: inline-block;
    font-size: 14px; font-weight: 600;
    color: var(--bcc-red);
    text-decoration: none;
    letter-spacing: -0.01em;
}
.invite-link:hover { text-decoration: underline; }
#site-footer {
    text-align: center; padding: 32px;
    font-size: 13px; font-weight: 600;
    color: rgba(255,255,255,0.75);
    background: #3d4a3d;
}
.masthead-admin {
    display: inline-block;
    margin-top: 20px;
    font-size: 12px; font-weight: 600;
    font-family: var(--mono);
    letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--text-faint);
    text-decoration: none;
    transition: color 0.15s;
}
.masthead-admin:hover { color: var(--text); }
</style>
</head>
<body>

<div id="page">

    <div id="masthead">
        <div class="masthead-lockup">
            <div class="masthead-mark"><img src="/images/bcc-red.svg" alt="Backcountry Club"></div>
            <span class="masthead-name">Backcountry Club</span>
        </div>
        <p class="masthead-tagline">An outdoor adventure club for indoor people</p>
        <p class="masthead-copy">Backcountry Club is a simple idea: connect, challenge, and inspire good people — in the most epic settings possible. I’ve spent years leading beginners into the backcountry who would’ve never gone on their own. Many of them say it was a highlight of their lives (seriously).</p>
        <p class="masthead-copy">The secret is meticulous planning. Permits, gear lists, spreadsheets, etc. I nerd out, so you don’t have to. You bring yourself. We do the rest together.</p>
        <?php if ($is_admin): ?>
        <a href="/admin" class="masthead-admin">Admin &rarr;</a>
        <?php endif; ?>
    </div>

    <div id="trips-section">
        <div id="trips-heading">Trips</div>
        <div id="trip-grid">
<?php foreach ($trips as $trip):
    $tid    = (int)$trip['id'];
    $url    = "/{$trip['year']}/{$trip['slug']}";
    $mapurl = $trip_maps[$tid] ?? '';
    $dates  = ($trip['started_at'] || $trip['ended_at']) ? fmt_date_range($trip['started_at'], $trip['ended_at']) : '';
    $days   = (int)$trip['day_count'];
    $photos = (int)$trip['photo_count'];
?>
            <a class="trip-card" href="<?= $url ?>">
                <?php if ($mapurl): ?>
                <div class="trip-card-map">
                    <img src="<?= htmlspecialchars($mapurl) ?>"
                         alt="<?= htmlspecialchars($trip['name']) ?> track"
                         loading="lazy" decoding="async">
                </div>
                <?php else: ?>
                <div class="trip-card-map-empty"></div>
                <?php endif; ?>
                <div class="trip-card-body">
                    <div class="trip-card-year"><?= (int)$trip['year'] ?></div>
                    <div class="trip-card-name"><?= htmlspecialchars($trip['name']) ?></div>
                    <?php if (!empty($trip['subtitle'])): ?>
                    <div class="trip-card-subtitle"><?= htmlspecialchars($trip['subtitle']) ?></div>
                    <?php endif; ?>
                    <div class="trip-card-meta">
                        <span><?= $dates ?></span>
                        <span>
                            <?= $days ?> day<?= $days !== 1 ? 's' : '' ?>
                            <?php if ($photos): ?> · <?= $photos ?> photos<?php endif; ?>
                        </span>
                    </div>
                </div>
            </a>
<?php endforeach; ?>
        </div>
    </div>

    <div class="invite-section">
        <h2>Want to come?</h2>
        <p>Everyone who’s been was personally invited, but I’m always looking to include new people. If we know each other and you might be interested, I’d love to hear from you.</p>
        <a class="invite-link" href="mailto:chris@shiflett.org">Get in touch →</a>
    </div>

    <div class="invite-section">
        <h2>Interested in starting a chapter?</h2>
        <p>Backcountry Club started in Boulder, but the idea works anywhere there’s backcountry nearby. If you’re interested in starting a chapter in your area, I’d love to help. I can share itineraries, gear lists, planning templates, apps, and more.</p>
        <a class="invite-link" href="mailto:chris@shiflett.org">Let’s talk →</a>
    </div>

</div>

<footer id="site-footer">
    © <?= date('Y') ?> Backcountry Club
</footer>

</body>
</html>