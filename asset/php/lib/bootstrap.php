<?php
/**
 * Loads the Cdash\ library for the scripts in asset/php (geosync, geoaudit,
 * places-geojson).
 *
 * The library separates three things the scripts used to mix in every block:
 * the SQL (Schema, WorkingTables, Sync, Audit), running it (the Db
 * interface), and printing HTML (left to the scripts). It is written to move
 * into the planned CDASH module: the classes would go to its src/ as they
 * are, a DBAL Db over Omeka\Connection would replace MysqliDb, and module
 * routes and views would replace these scripts and _require-admin.php.
 *
 * Functions and classes only, no output -- and not to be requested directly.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Cdash\\', 6) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * Now, for the top of a report, in Omeka's own time zone (Admin > Settings >
 * Time zone). The container's PHP runs in UTC, which read five hours off in
 * Cambridge. $app is the Omeka application _require-admin.php boots; without
 * one (a CLI test) it falls back to UTC, and says so.
 */
function cdash_report_time($app = null): string
{
    $zone = 'UTC';
    if ($app) {
        try {
            $zone = $app->getServiceManager()->get('Omeka\Settings')->get('time_zone') ?: 'UTC';
        } catch (\Throwable $e) {
            $zone = 'UTC';
        }
    }
    try {
        $tz = new \DateTimeZone($zone);
    } catch (\Throwable $e) {
        $tz = new \DateTimeZone('UTC');
    }
    return (new \DateTime('now', $tz))->format('l, F j, Y H:i:s T');
}

/** The database Omeka uses, as a Cdash\Db. */
function cdash_db(): \Cdash\Db
{
    return \Cdash\MysqliDb::fromIni('/var/www/html/persist/config/database.ini');
}
