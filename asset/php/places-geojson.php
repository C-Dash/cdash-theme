<?php
/**
 * CDASH Places as GeoJSON -- the map's marker feed, and a public one.
 *
 * Returns an RFC 7946 FeatureCollection, one Point per public CDASH Place:
 *
 *   {
 *     "type": "FeatureCollection",
 *     "metadata": { "attribution": "...", "generated": "...", "count": N },
 *     "features": [
 *       { "type": "Feature", "id": 13333,
 *         "geometry": { "type": "Point", "coordinates": [-71.0897, 42.3712] },
 *         "properties": { "title": "...", "placetype": "...", "url": "https://...",
 *                         "documents": [13343, 13344] } }
 *     ]
 *   }
 *
 * `documents` lists the item IDs of the Place's public Documents (those whose
 * cdash:placeItem links to it), possibly empty. The theme's map uses it to
 * light up the Places of Documents a search matched.
 *
 * Coordinates are [longitude, latitude], as GeoJSON requires -- the reverse of
 * Leaflet's L.latLng. The format is a contract with outside users (GIS tools,
 * other sites): add properties, never rename or remove them.
 *
 * Query parameters, all optional; each narrows the feed, never Omeka's own
 * search results:
 *
 *   site=<slug>        whose item pool and item URLs. Default: the site using
 *                      this theme folder.
 *   ids=1,2,3          only these item IDs.
 *   placetype=<text>   only Places of this type (exact match).
 *   bbox=w,s,e,n       only Places inside this box, in degrees.
 *
 * GET, cacheable (ETag over the features, so an unchanged feed answers 304),
 * and open to any origin (CORS), since it is public.
 *
 * Replaces placemarkers.php, which returned {success, resp: [rows]} to a POST.
 *
 * The URL is provisional: it lives under the theme folder, which is renamed on
 * a version bump. A stable route comes with the planned CDASH module, with the
 * format unchanged.
 *
 * Like the other scripts here, this reads Omeka's database directly and so
 * bypasses its ACL; it enforces visibility itself -- the resource must be
 * public and in the site's item pool.
 *
 * Mapping 2.x storage: mapping_feature.geography is a GEOMETRY, SRID 0,
 * x = longitude, y = latitude. Only points become features.
 */

const ATTRIBUTION = 'Cambridge Historical Commission, Digital Architectural Survey';
// The licence is still to be decided; add 'license' to the metadata then.
const MAX_IDS = 10000;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Expose-Headers: ETag');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: If-None-Match');
    http_response_code(204);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    fail(405, 'Use GET.');
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}

// --- Parameters -------------------------------------------------------------

$ids = null;
if (isset($_GET['ids']) && $_GET['ids'] !== '') {
    $parts = explode(',', (string) $_GET['ids']);
    if (count($parts) > MAX_IDS) {
        fail(400, 'Too many ids (at most ' . MAX_IDS . ').');
    }
    $ids = [];
    foreach ($parts as $p) {
        if (!ctype_digit(trim($p))) {
            fail(400, 'ids must be a comma-separated list of item IDs.');
        }
        $ids[] = (int) trim($p);
    }
}

$placetype = isset($_GET['placetype']) && $_GET['placetype'] !== ''
    ? (string) $_GET['placetype'] : null;

$bbox = null;
if (isset($_GET['bbox']) && $_GET['bbox'] !== '') {
    $parts = explode(',', (string) $_GET['bbox']);
    if (count($parts) !== 4 || array_filter($parts, fn ($p) => !is_numeric(trim($p)))) {
        fail(400, 'bbox must be four numbers: west,south,east,north.');
    }
    $bbox = array_map(fn ($p) => (float) trim($p), $parts);
    if ($bbox[0] > $bbox[2] || $bbox[1] > $bbox[3]) {
        fail(400, 'bbox must be west,south,east,north, with west <= east and south <= north.');
    }
}

$siteSlug = isset($_GET['site']) && $_GET['site'] !== '' ? (string) $_GET['site'] : null;

// --- Database ---------------------------------------------------------------

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $ini = parse_ini_file('/var/www/html/persist/config/database.ini');
    $conn = new mysqli($ini['host'], $ini['user'], $ini['password'], $ini['dbname']);
    $conn->set_charset('utf8mb4');

    // Which site: the one named, else the one wearing this theme folder.
    if ($siteSlug !== null) {
        $stmt = $conn->prepare('SELECT id, slug FROM site WHERE slug = ?');
        $stmt->bind_param('s', $siteSlug);
    } else {
        $themeFolder = basename(dirname(__DIR__, 2));
        $stmt = $conn->prepare('SELECT id, slug FROM site WHERE theme = ? ORDER BY id LIMIT 1');
        $stmt->bind_param('s', $themeFolder);
    }
    $stmt->execute();
    $site = $stmt->get_result()->fetch_assoc();
    if (!$site) {
        fail(404, 'No such site.');
    }

    $sql = "SELECT resource.id AS item_id, resource.title, value.value AS placetype,
              ST_Y(mapping_feature.geography) AS lat, ST_X(mapping_feature.geography) AS lng
            FROM mapping_feature
            JOIN resource
              ON mapping_feature.item_id = resource.id
              AND ST_GeometryType(mapping_feature.geography) = 'POINT'
              AND resource.is_public = 1
              AND resource.resource_template_id =
                (SELECT id FROM resource_template WHERE label = 'CDASH Place')
            JOIN item_site
              ON item_site.item_id = resource.id AND item_site.site_id = ?
            JOIN value
              ON value.resource_id = resource.id
              AND value.property_id = (SELECT id FROM property WHERE local_name = 'placeType')";
    $types = 'i';
    $args = [(int) $site['id']];
    $where = [];

    if ($ids !== null) {
        $where[] = 'resource.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $types .= str_repeat('i', count($ids));
        array_push($args, ...$ids);
    }
    if ($placetype !== null) {
        $where[] = 'value.value = ?';
        $types .= 's';
        $args[] = $placetype;
    }
    if ($bbox !== null) {
        $where[] = 'ST_X(mapping_feature.geography) BETWEEN ? AND ?
                    AND ST_Y(mapping_feature.geography) BETWEEN ? AND ?';
        $types .= 'dddd';
        array_push($args, $bbox[0], $bbox[2], $bbox[1], $bbox[3]);
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    // Stable order, so an unchanged database gives an identical body and ETag.
    $sql .= ' ORDER BY resource.id, mapping_feature.id, value.id';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $result = $stmt->get_result();

    // Each Place's Documents: the public items in this site's pool whose
    // cdash:placeItem links to it. The map needs them to light up the Place
    // of a Document that a search matched, since only Places have markers.
    $docStmt = $conn->prepare(
        "SELECT value.value_resource_id AS place_id, value.resource_id AS doc_id
         FROM value
         JOIN resource ON resource.id = value.resource_id AND resource.is_public = 1
         JOIN item_site ON item_site.item_id = resource.id AND item_site.site_id = ?
         WHERE value.property_id = (SELECT id FROM property WHERE local_name = 'placeItem')
           AND value.value_resource_id IS NOT NULL
         ORDER BY value.value_resource_id, value.resource_id"
    );
    $siteId = (int) $site['id'];
    $docStmt->bind_param('i', $siteId);
    $docStmt->execute();
    $documentsByPlace = [];
    foreach ($docStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $doc) {
        $documentsByPlace[(int) $doc['place_id']][] = (int) $doc['doc_id'];
    }
} catch (mysqli_sql_exception $e) {
    error_log('places-geojson.php: ' . $e->getMessage());
    fail(500, 'Database error.');
}

// --- Item URLs --------------------------------------------------------------
// Absolute, for outside users. Omeka's base path is whatever precedes /themes/
// in this script's own URL.

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$base = ($pos = strpos($script, '/themes/')) !== false ? substr($script, 0, $pos) : '';
$siteUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . $base . '/s/' . rawurlencode($site['slug']);

// --- Features ---------------------------------------------------------------

$features = [];
$seen = [];
while ($row = $result->fetch_assoc()) {
    $id = (int) $row['item_id'];
    // One feature per Place: a second point, or a second placeType value,
    // would otherwise repeat the id, which GeoJSON consumers expect unique.
    if (isset($seen[$id])) {
        continue;
    }
    $seen[$id] = true;
    $features[] = [
        'type' => 'Feature',
        'id' => $id,
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [round((float) $row['lng'], 6), round((float) $row['lat'], 6)],
        ],
        'properties' => [
            'title' => $row['title'],
            'placetype' => $row['placetype'],
            'url' => $siteUrl . '/item/' . $id,
            'documents' => array_values(array_unique($documentsByPlace[$id] ?? [])),
        ],
    ];
}
$conn->close();

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
$etag = '"' . md5(json_encode($features, $flags)) . '"';

header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/geo+json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}
echo json_encode([
    'type' => 'FeatureCollection',
    'metadata' => [
        'attribution' => ATTRIBUTION,
        'generated' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($features),
    ],
    'features' => $features,
], $flags);
