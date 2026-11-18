<?php
/**
 * GeoAudit: counts, and the integrity checks of the CDASH schema. Reads only.
 * The checks are Cdash\Audit (lib/Cdash/Audit.php); this page renders them.
 *
 * Links go to the admin pages, relative, so they hold on any host and under
 * any site slug.
 */

// Must come first: the guard needs to set a response code before any output.
// This page only reads, but it reports across the whole collection and has
// no business answering anonymous requests.
require __DIR__ . '/_require-admin.php';
require __DIR__ . '/lib/bootstrap.php';

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

echo '<!doctype html><html><head><meta charset="utf-8"><title>GeoAudit</title>'
    . '<style>table, th, td { border-collapse: collapse; padding: 5px; } .explain { margin-left: 20px; }</style>'
    . '</head><body>';
echo '<h1>GeoAudit: Check Integrity of CDASH Schema</h1>';

try {
    $db = cdash_db();
    $audit = Cdash\Audit::run($db, new Cdash\Schema($db));
} catch (\Throwable $e) {
    error_log('geoaudit.php: ' . $e);
    echo '<p style="color:red"><b>GeoAudit failed.</b><br>' . $h($e->getMessage()) . '</p>';
    exit('</body></html>');
}

$when = cdash_report_time($cdashApp);
echo '<p><b>Run: ' . $h($when) . '</b></p>';

echo '<hr><b>Integrity Audit:</b> CDASH-specific integrity issues are listed below.<br>';
foreach ($audit['checks'] as $check) {
    echo '<br>' . $h($check['count']) . ' ' . $h($check['label']) . '<br>';
    if (!$check['count']) {
        continue;
    }
    echo '<div class="explain">' . $h($check['explain']) . '<table>';
    foreach ($check['rows'] as $row) {
        if ($check['link']) {
            $url = '/admin/' . $check['link'] . '/' . (int) $row['id'];
            echo '<tr><td>' . $h($row['title']) . '</td><td><a href="' . $h($url) . '" target="omeka_admin">'
                . $h($url) . '</a></td></tr>';
        } else {
            echo '<tr><td>' . $h($row['title']) . '</td><td>' . $h($row['folders'] ?? '') . '</td></tr>';
        }
    }
    echo '</table></div>';
}

$c = $audit['counts'];
echo '<p><b>Geo Audit Summary:</b></p>';
echo '<b>Counts: ' . $h($when) . '</b><table>';
foreach (['Folders', 'Document Items', 'Place Items', 'Omeka Media Count'] as $label) {
    echo '<tr><td>' . $h($c[$label]) . '</td><td>' . $h($label) . '</td></tr>';
}
echo '</table>';
if ($c['Document Items'] + $c['Place Items'] !== $c['Items']) {
    echo '<p style="color:red">Total items: ' . $h($c['Items']) . ' not equal to: '
        . $h($c['Document Items'] + $c['Place Items']) . ' Total Places + Total Documents.</p>';
}

echo '<p><b>Integrity Issues: ' . $h($when) . '</b><br><table>';
foreach ($audit['checks'] as $check) {
    echo '<tr><td>' . $h($check['count']) . '</td><td>' . $h($check['label']) . '</td></tr>';
}
echo '</table></p>';
echo '</body></html>';
