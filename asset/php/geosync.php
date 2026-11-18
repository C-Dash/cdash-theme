<?php
/**
 * GeoSync: makes every Place's derived values, and every Document's copies of
 * its Place -- point, title, street sort key, inherited properties -- true
 * again. The work is Cdash\Sync (lib/Cdash/Sync.php); this page runs it and
 * reports.
 *
 * Sync writes straight to the database, so Omeka's full-text index -- what
 * keyword search reads -- does not see the changes. When anything changed,
 * this page queues Omeka's own re-index job, the one Admin > Settings >
 * "Index full-text search" runs.
 */

// Must come first: it sets a response code before any output, and it boots
// Omeka, leaving $cdashApp -- used below to queue the re-index.
require __DIR__ . '/_require-admin.php';
require __DIR__ . '/lib/bootstrap.php';

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

echo '<!doctype html><html><head><meta charset="utf-8"><title>GeoSync</title></head><body>';
echo '<h1>GeoSync: Synchronize Place updates to Documents</h1>';
echo '<p><b>Run: ' . $h(cdash_report_time($cdashApp)) . '</b></p>';

try {
    $db = cdash_db();
    $result = Cdash\Sync::run($db, new Cdash\Schema($db));
} catch (\Throwable $e) {
    error_log('geosync.php: ' . $e);
    echo '<p style="color:red"><b>GeoSync failed; nothing was changed.</b><br>' . $h($e->getMessage()) . '</p>';
    exit('</body></html>');
}

echo '<p><b>Item Summary:</b><br>';
echo $h($result['places']) . ' Place items<br>';
echo $h($result['documents']) . ' Document items<br>';
echo $h($result['places'] + $result['documents']) . ' Total items</p>';

echo '<p><b>Updates:</b><br>';
if ($result['changed'] === 0) {
    echo 'None: everything was already in sync.';
} else {
    foreach ($result['changes'] as $label => $count) {
        if ($count > 0) {
            echo $h($count) . ' ' . $h($label) . '<br>';
        }
    }
}
echo '</p>';

if ($result['changed'] > 0) {
    try {
        $job = $cdashApp->getServiceManager()->get('Omeka\Job\Dispatcher')
            ->dispatch('Omeka\Job\IndexFulltextSearch');
        $jobUrl = '/admin/job/' . $job->getId();
        echo '<p>Keyword search is being re-indexed so it finds the updated titles and values: '
            . '<a href="' . $h($jobUrl) . '" target="omeka_admin">job #' . $h($job->getId()) . '</a>. '
            . 'It runs in the background and may take a few minutes.</p>';
    } catch (\Throwable $e) {
        error_log('geosync.php re-index: ' . $e);
        echo '<p style="color:red">Could not queue the keyword-search re-index (' . $h($e->getMessage()) . '). '
            . 'Run it from Admin &gt; Settings: tick "Index full-text search" and save.</p>';
    }
}

echo '<p><b>Close this tab and refresh to check results.</b></p>';
echo '</body></html>';
