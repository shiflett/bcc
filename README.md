# Backcountry Club — Implementation Notes

## Executive Summary

Backcountry Club (BCC) is a personal trip tracker and GPS map viewer for backcountry hiking trips. The headline feature is the day page: a split-screen layout where photos scroll on the left and a live map responds on the right. As you scroll through photos, a marker moves continuously along the GPS track, a clock ticks through the hours, stats animate from zero to their final values, and the trail already covered fills in red. Everything is anchored directly to scroll position — no timers, no animation loops, no lag.

BCC runs at `localhost:8083` in local Docker development. It is the prototype and proving ground for a future multi-user product called **Mapalong**.

---

## Technology Stack

| Layer | Choice |
|---|---|
| Server | PHP 8.3, nginx, php-fpm |
| Database | PostgreSQL 16 |
| Map | Leaflet.js 1.9.4 with Mapbox Outdoors tiles |
| Image processing | GD + Imagick (for HEIC support) |
| Local dev | Docker Compose (shared base image with Schoolcase, Mailhook, Mailroom) |
| Photo storage | Local filesystem at `/uploads/{trip_id}/` |

No frontend framework. No build step. PHP templates, vanilla JS, Leaflet.

---

## Docker Setup

BCC runs as the `bcc` service in `~/local/docker-compose.yml`. It extends `local-php-base:latest` (defined in `~/local/Dockerfile.php`). The base image must be rebuilt when PHP extensions change (e.g. adding Imagick).

```bash
# Start everything
cd ~/local && docker compose up

# BCC only
docker compose restart bcc

# Rebuild base image (e.g. after adding a PHP extension)
docker compose build php-base
docker compose build bcc
docker compose up -d bcc

# Connect to DB
docker exec local-bcc-db-1 psql -U bcc -d bcc

# Run a query
docker exec local-bcc-db-1 psql -U bcc -d bcc -c "SELECT count(*) FROM trackpoints;"
```

**Port mapping:** BCC is at `localhost:8083` externally, but nginx inside the container listens on port 8080. This caused a Chrome-cached 301 redirect bug — `port_in_redirect off` in nginx.conf fixes it for new requests, but cached 301s in Chrome must be cleared manually via `chrome://net-internals/#hsts`.

**Admin auth cookie:** The `bcc_admin` cookie is scoped to `path: '/'` so it works on all pages including the trip page upload zone. Cookie is HttpOnly, SameSite=Strict, 10-year expiry. After updating `auth.php` to widen the cookie path, users must log in once to get a new cookie.

---

## File Organisation

```
bcc/
├── bin/
│   ├── import-gpx.php                  # CLI: import GPX → trackpoints + trip_days
│   ├── import-trips.php                # CLI: import all trips from live backcountry.club
│   ├── timestamp-track-from-photos.php # CLI: assign timestamps to a Strava GPX using photo/track anchors
│   ├── backup.sh                       # timestamped pg_dump to backups/
│   └── regen-thumbs.php                # regenerate thumbnails
├── includes/
│   ├── auth.php                        # admin auth: require_admin_auth(), is_admin()
│   ├── db.php                          # PDO singleton
│   └── layout.php                      # shared helpers (fmt_ele, fmt_dist, fmt_date_range, etc.)
├── public/
│   ├── api/
│   │   ├── photos.php                  # GET /api/photos/{year}/{slug}/{day}
│   │   ├── points.php                  # GET /api/points/{year}/{slug}/{day}
│   │   ├── upload-photo.php            # POST /api/upload-photo
│   │   └── photo-action.php            # DELETE or POST /api/photo-action?id=N[&action=snap]
│   ├── admin/
│   │   ├── index.php                   # GET /admin — trip list
│   │   ├── trip-edit.php               # GET|POST /admin/{token} or /admin/new
│   │   └── photos.php                  # GET /admin/photos/{year}/{slug} (legacy, not primary)
│   ├── css/
│   │   └── app.css                     # global design tokens + base styles
│   ├── pages/
│   │   ├── home.php                    # trip index
│   │   ├── trip.php                    # trip overview page
│   │   ├── day.php                     # the main day page
│   │   └── 404.php
│   ├── uploads/
│   │   └── {trip_id}/
│   │       ├── {filename}.jpg          # full size (max 1800px)
│   │       └── thumbs/                 # thumbnails (max 900px)
│   └── router.php                      # URL routing
├── docker/
│   └── nginx.conf
└── strava/                             # Strava GPX files (no timestamps — see workflow below)
```

---

## Database Schema

```sql
trips        — year, slug, name, subtitle, started_at, ended_at, token, map_lat, map_lon, map_zoom
trip_days    — trip_id, day_number, date, gain_m, loss_m, distance_m, point_count, started_at, ended_at
trackpoints  — trip_id, lat, lon, ele, recorded_at (UTC timestamptz)
media        — trip_id, day_number, kind, filename, taken_at, lat, lon, placement_tier, width, height
```

Always store UTC, display in `America/Denver`. Day boundaries computed in Mountain Time.

Schema management: periodic `pg_dump --schema-only` rather than hand-written migrations.

---

## URL Routes

```
GET  /                              home.php — trip index
GET  /{year}/{slug}                 trip.php — trip overview
GET  /{year}/{slug}/day/{n}         day.php  — day experience
GET  /admin                         admin/index.php — trip list (requires auth)
GET  /admin/new                     admin/trip-edit.php — create trip
GET  /admin/{token}                 admin/trip-edit.php — edit trip
GET  /login                         auth form (redirects to /admin on success)
POST /api/upload-photo              upload + process a photo
DEL  /api/photo-action?id=N         delete a photo
POST /api/photo-action?id=N&action=snap  snap photo to nearest trackpoint
GET  /api/photos/{year}/{slug}/{day}
GET  /api/points/{year}/{slug}/{day}
GET  /api/gpx/{year}/{slug}
GET  /admin/photos/{year}/{slug}    legacy photo upload UI
```

The nginx `location = /admin` exact-match block is required to prevent nginx from issuing a directory redirect to `/admin/` (which rewrites the port in the Location header, breaking local dev).

---

## Trip Types

Three kinds of trips, all handled:

| Type | trip_days source | Day page |
|---|---|---|
| Photos + track | GPX import | Full scroll experience |
| Track only | GPX import | No day page (no photos) |
| Photos only | Created on-the-fly at upload time | Photos-only experience |

For photos-only trips, `upload-photo.php` creates `trip_days` rows on-the-fly from photo timestamps grouped by local date in Mountain Time. Subsequent photos on the same date find the existing row and extend `ended_at` if needed.

---

## Photo Placement Tiers

| Tier | Source | Notes |
|---|---|---|
| 1 | EXIF GPS | Coordinates from camera. Never overwritten. |
| 2 | GPX interpolation | Timestamp matched to nearest trackpoint. Can be re-interpolated. |
| 3 | Manual pin | Planned, not yet built. |
| 4 | None | No location data available. |

Tiers are stored in the DB at upload time and not recalculated live. `import-gpx.php` re-interpolates tier-2 and tier-4 photos after importing a new track.

### HEIC support
Imagick is installed in the base Docker image (`libheif-dev` + `libmagickwand-dev` + `pecl install imagick`). `upload-photo.php` converts HEIC → JPEG before EXIF extraction and GD processing. iOS panoramas are JFIF format (no EXIF) — they upload as tier 4 with no timestamp.

### GPX interpolation scope
Filters by **same day in Mountain Time** before searching by timestamp proximity:
```sql
AND (recorded_at AT TIME ZONE 'America/Denver')::date
  = (:ts::timestamptz AT TIME ZONE 'America/Denver')::date
```

### Admin controls on day page
When logged in (cookie path `/`), the DETAILS panel on each photo shows:
- **Delete** — inline confirm, removes from DB and disk
- **Snap to track** — shown when EXIF GPS is >100m from nearest trackpoint; replaces coordinates with nearest trackpoint by timestamp, sets tier to 2

---

## Photo Upload

Photos can be uploaded from:
1. **Trip page** (`/{year}/{slug}`) — upload zone at bottom of left panel, admin-only, visible when `is_admin()` returns true
2. **Admin trip edit page** (`/admin/{token}`) — upload zone below the form

Both use the same `POST /api/upload-photo` endpoint. Upload is sequential (one at a time). On success the queue shows `✓ day N · GPS` etc. On error the queue item stays visible with the error message. Page reloads only if all uploads succeed.

EXIF timezone: uses `OffsetTimeOriginal` if present, falls back to `tz_offset` POST param, defaults to `-06:00` (MDT). Summer trips are MDT.

---

## GPX Import Workflow

### Standard import (full trip, Gaia GPX)
```bash
docker compose exec bcc php bin/import-gpx.php \
  <year> <slug> "Name" "Subtitle" /path/to/track.gpx
```
Replaces all trackpoints and trip_days. Re-interpolates tier-2 and tier-4 photos.

### Per-day import (Strava GPX after timestamping)
```bash
docker compose exec bcc php bin/import-gpx.php \
  <year> <slug> "Name" "Subtitle" /path/to/day.gpx --day=N
```
Only replaces trackpoints and trip_days for day N. Derives the expected date from `trips.started_at + (N-1) days`. Does not overwrite `started_at`/`ended_at` on the trip row. Re-interpolates photos for that day only.

### Strava GPX workflow (no timestamps)
Strava deliberately strips timestamps from GPX exports. Use `bin/timestamp-track-from-photos.php` to assign timestamps using two anchor sources:

1. **GPS photos** (tier 1) for that day — EXIF timestamp + coordinates
2. **Existing sparse trackpoints** in DB for that day (e.g. Spot data)

The script:
- Simplifies the dense Strava track first (distance filter ≥8m + RDP ε=5m) to remove GPS noise from stationary periods
- Matches each anchor to the nearest point on the simplified track geographically
- Interpolates timestamps between anchors by cumulative distance
- Extrapolates before the first anchor and after the last at the same pace
- Outputs a standard timestamped GPX ready for `import-gpx.php`

```bash
# Step 1: assign timestamps
docker compose exec bcc php bin/timestamp-track-from-photos.php \
  <year> <slug> <day_number> /app/strava/input.gpx /tmp/output.gpx

# Step 2: import
docker compose exec bcc php bin/import-gpx.php \
  <year> <slug> "Name" "Subtitle" /tmp/output.gpx --day=N
```

If day N doesn't exist in `trip_days` yet (e.g. after a reset), the timestamp script derives the date from `trips.started_at + (N-1) days`.

**Loop for all 5 days:**
```bash
for DAY in 1 2 3 4 5; do
  docker compose exec bcc php bin/timestamp-track-from-photos.php \
	2025 flat-tops $DAY /app/strava/flat-tops-$DAY.gpx /tmp/flat-tops-day$DAY-timed.gpx && \
  docker compose exec bcc php bin/import-gpx.php \
	2025 flat-tops "Flat Tops" "Flat Tops Wilderness" /tmp/flat-tops-day$DAY-timed.gpx --day=$DAY
done
```

**Warnings to expect and understand:**
- `⚠ Anchor N timestamp not after anchor N-1` — out-of-order anchors from the Spot track's camp pings (Spot pings every 5 min including at camp, so end-of-day pings map to the track end but with late timestamps). Interpolation is imprecise near these but fine overall.
- `⚠ (>200m — possible GPS drift)` — one anchor matched far from the track, usually a photo taken at camp or away from the trail. Interpolation skips these by using surrounding anchors.

**Day 1 issue:** If day 1 has no GPS photos and the Spot track has already been deleted (e.g. after a reset), there are 0 anchors and the script will exit. Reimport the Spot track first (`import-trips.php`), then re-run.

### Reimport from live site
```bash
docker compose exec bcc php bin/import-trips.php
```
Safe to re-run. Imports all trips from the live backcountry.club site. The `james-peak-scouts` trip (ID 1, 2024) is **protected** — never overwrite it, it has manual corrections and photos.

---

## Trip Reset (nuclear option)
```bash
docker exec local-bcc-db-1 psql -U bcc -d bcc -c "
DELETE FROM media      WHERE trip_id = (SELECT id FROM trips WHERE slug = 'flat-tops' AND year = 2025);
DELETE FROM trackpoints WHERE trip_id = (SELECT id FROM trips WHERE slug = 'flat-tops' AND year = 2025);
DELETE FROM trip_days  WHERE trip_id = (SELECT id FROM trips WHERE slug = 'flat-tops' AND year = 2025);
DELETE FROM trips      WHERE slug = 'flat-tops' AND year = 2025;
"
```

---

## The Day Page

`public/pages/day.php` is the centrepiece of the app. Single PHP/HTML/JS file, light-mode design tokens, no dark mode, no framework.

### Layout
Split screen: left column (`50vw`) scrolls photos; right panel (`50vw`, `position: fixed`) shows a Leaflet map and a 120px elevation canvas below it. A sticky topbar spans both columns (`margin-right: calc(-50vw); width: calc(100% + 50vw)`).

### Stream elements
All scrollable elements are `.photo-item` (photos), `.track-sentinel` (start/end markers), `.day-header` (top), and `.next-day` (link to next day). All have `min-height: 50vh` so scroll interpolation has enough physical distance.

### Sentinel placement
Track sentinels are inserted by `fillSentinels()` after both the track and photos have loaded. The **end sentinel is always inserted before the next-day link** so the `#photos-col::after` pseudo-element (50vh) provides trailing scroll space after the sentinel. If the next-day link comes after the sentinel, the pseudo-element is ineffective and the elevation cursor stops short.

### Scroll-driven interpolation
`displayMs` is a single scroll-derived millisecond value that drives everything: clock, marker position, track color, elevation cursor, stats. Computed in the scroll handler by interpolating between the timestamps of adjacent stream elements proportional to scroll position.

### Photo details panel
Toggle with DETAILS↓ button on each photo. Shows:
- Photo N / total
- Coordinates + placement tier
- Nearest trackpoint time + distance

When admin is logged in, also shows:
- **Snap to track** button (only when >100m from track, tier 1 photos)
- **Delete** button (always shown, inline confirm)

### IS_ADMIN
PHP renders `const IS_ADMIN = true/false` into the JS. Admin controls are gated on this. Auth check uses `is_admin()` from `includes/auth.php` which reads the `bcc_admin` cookie.

---

## The Trip Page

`public/pages/trip.php` shows the trip overview.

### Day card types
- **Photos + track**: full clickable card with map thumbnail + "Experience Day N →" CTA
- **Track only**: plain text row, no card, no CTA
- **Photos only**: clickable card with "View Day N Photos →" CTA

### Static map images
Each day card shows a Mapbox static image using the trip's full bbox (not per-day bbox) so all day cards show the same geographic context.

### Upload zone
Admin-only upload zone at the bottom of the left panel. Drops/uploads JPEGs and HEICs. Shows inline status per file. Reloads on full success, stays visible on any error.

---

## Elevation Strip

120px canvas at the bottom of the map panel. Rendered at `devicePixelRatio` for retina sharpness.

**X-axis: distance-proportional** — each point's x position is `dists[i] / totalDistM * plotW`. Prevents GPS gaps from producing vertical cliffs.

**Bezier curves** — each segment uses `bezierCurveTo` with midpoint as control point.

**Cursor** — 2px red line + 10px dot. Uses `canvas.offsetWidth` (CSS pixels) not `canvas.width` (physical pixels) for percentage calculation.

---

## Fetch Coordination

Two parallel fetches: `/api/points/` and `/api/photos/`. Both must complete before `fillSentinels` runs. Fixed with coordination flags:

```javascript
let _trackReady = false, _photosReady = false;
function _tryFillSentinels() {
	if (_trackReady && _photosReady && window.fillSentinels) window.fillSentinels();
}
```

---

## GPS Disambiguation (Looping Tracks)

On out-and-back routes, pure geographic nearest-point matching picks the wrong leg. Solution: gather all trackpoints within ~100m, pick the one whose timestamp is closest to the photo's timestamp.

---

## Lessons Learned

**Use Gaia for all trip recording.** Strava deliberately strips timestamps from GPX exports. Spot data is sparse (one ping per 5 minutes). Gaia exports dense, timestamped GPX that works perfectly with `import-gpx.php` directly.

**Strava tracks need the timestamp pipeline.** If you have Strava tracks, use `timestamp-track-from-photos.php` to assign timestamps from GPS photo anchors + existing Spot track. The script simplifies the track first (RDP + distance filter) to remove GPS noise from stationary periods before doing anchor matching.

**The `--day` flag is essential for per-day imports.** Without it, `import-gpx.php` wipes all days and recreates from just the one GPX file. With it, only the target day's trackpoints and trip_days row are replaced.

**End sentinel ordering matters.** The next-day link must come *after* the end sentinel in the DOM so the `::after` pseudo-element provides trailing scroll space. If the link comes before the sentinel, the sentinel can't scroll to viewport center and the track end state is never reached.

**Chrome caches 301s aggressively.** A 301 from `localhost:8083/admin` to `localhost:8080/admin/` (nginx port rewrite) gets permanently cached. Clear with `chrome://net-internals/#hsts`. Use `absolute_redirect off` or `location = /admin` exact-match block in nginx to prevent the redirect entirely.

**Cookie path must match pages that check it.** If `bcc_admin` is scoped to `/admin`, it won't be sent on trip pages. Widen to `/` and have users re-login.

**PHP warnings corrupt JSON responses.** `ob_start()` at the top of API endpoints + `ob_get_clean()` before `echo json_encode(...)` ensures PHP notices and warnings don't leak into the response body.

**HEIC panoramas are JFIF.** iOS panoramas are stitched and exported as JFIF (no EXIF, no GPS). They upload as tier 4 with no day — expected behavior until manual placement is built.

**Memory limit matters for large images.** Set `ini_set('memory_limit', '256M')` in `upload-photo.php`. The default 128MB limit can be exhausted by GD when processing large JPEGs in sequence.

**GPX interpolation must be scoped to the same day.** Searching ±30 minutes without a day filter allows photos during GPS gaps to match trackpoints from a different day.

**Distance-proportional elevation x-axis.** Equal index spacing compresses GPS gaps into tiny slices. Distance-proportional positioning fixes this — sparse Spot points get the same chart width as dense Gaia points.

**Photos-only trips need on-the-fly day creation.** Without a GPX, there are no `trip_days` rows. `upload-photo.php` now creates them from photo timestamps. Subsequent photos on the same date find and extend the existing row.

**`import-gpx.php` upsert clobbers trip dates in --day mode.** The full upsert sets `started_at`/`ended_at` from the GPX file's first/last timestamp. In `--day` mode this is wrong — only insert/update the name and subtitle, leave dates alone. Fixed in current version.

**Anchor deduplication is necessary.** Multiple anchors matching the same track index (e.g. several photos taken at the same spot) cause zero-length interpolation segments. Deduplicate by keeping the anchor with smallest geographic match distance.

**RDP recursion depth.** PHP's default recursion limit can be hit by RDP on 14k+ point tracks. The distance filter (≥8m) reduces the input dramatically before RDP runs, but `ini_set` guards are still in place.

---

## Protected Trips

**`james-peak-scouts` (2024, trip ID 1)** — has manual photo corrections and photos. Never overwrite with `import-trips.php` or `import-gpx.php`. This is protected in `import-trips.php`.

---

## Flat Tops 2025 — Current Status

This is the most complex trip to set up. It has:
- Dense Strava GPX files (one per day, no timestamps) at `/app/strava/flat-tops-{1-5}.gpx`
- 58 GPS photos (tier 1) spanning Aug 7–10
- Sparse Spot track (original import from live site, days 1–5, Aug 6–10)

**Intended workflow:**
1. `import-trips.php` to get Spot track (provides day 1 anchors since no photos for day 1)
2. Upload all photos via trip page or admin edit page
3. Run `timestamp-track-from-photos.php` + `import-gpx.php --day=N` for each of days 1–5
4. Photos get re-interpolated automatically by `import-gpx.php` after each day's track import

**Day 1 note:** No GPS photos for day 1 — anchors come entirely from the Spot track. Must have Spot track in DB before running the timestamp script for day 1.