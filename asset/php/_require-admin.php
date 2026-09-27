<?php
/**
 * Refuses the request unless an Omeka global administrator is logged in.
 *
 * Required at the very top of the admin tools in this directory, before any
 * output, since it needs to set a response code.
 *
 * These scripts live in a theme asset directory, which Apache serves directly:
 * Omeka's routing never sees the request, so none of its ACL applies. Until
 * this guard existed, geosync.php rewrote the database for anyone who knew the
 * URL -- and the URL is not a secret, because this theme is published on
 * GitHub. Booting Omeka here is what gives us its session and its notion of
 * who is logged in, so the tools reuse the login the admin already has rather
 * than introducing a second password.
 *
 * MUST be \Omeka\Mvc\Application::init, not \Laminas\Mvc\Application::init.
 * They are unrelated classes, and only Omeka's fetches Omeka\ModuleManager --
 * which is the factory that runs "SELECT * FROM module" and calls
 * Status::setIsInstalled(true). Without that, isInstalled() stays false, and
 * AuthenticationServiceFactory then hands back NonPersistent storage with a
 * null adapter:
 *
 *     if (!$status->isInstalled() || ...) { $storage = new NonPersistent; ... }
 *
 * which can never hold an identity. The first version of this guard used the
 * Laminas one, copying cli-config.php -- fine there, since Doctrine tooling
 * only needs the EntityManager -- and locked every admin out with a permanent
 * 403. index.php is the precedent to follow, not cli-config.php.
 *
 * Nothing may be echoed before init(). Omeka's bootstrapSession listener calls
 * ini_set on session settings, which is fatal once headers have been sent, so
 * this file has to be required before the tools print anything.
 *
 * run() is never called: init() ends in bootstrap(), which fires
 * MvcEvent::EVENT_BOOTSTRAP, and that is where Omeka attaches its session
 * setup -- enough to make the identity resolvable without dispatching. The
 * session cookie reaches us because session.cookie_path is "/". Note the cookie
 * is not PHPSESSID; Omeka names it md5(OMEKA_PATH).
 *
 * Path is absolute and matches the convention already used for database.ini in
 * these scripts. It cannot be derived by walking up from __DIR__: omeka-s/themes
 * is a symlink to persist/themes, so __DIR__ resolves into persist and the
 * relative route back to the application does not exist.
 */

const CDASH_OMEKA_PATH = '/var/www/html/omeka-s';

require CDASH_OMEKA_PATH . '/bootstrap.php';

// bootstrap.php chdir()s to OMEKA_PATH, so this relative path resolves.
$cdashApp = \Omeka\Mvc\Application::init(
    require 'application/config/application.config.php'
);

$cdashAuth = $cdashApp->getServiceManager()->get('Omeka\AuthenticationService');

// Every account on this install is global_admin. Widen this list if an editor
// ever needs to run a sync.
$cdashAllowedRoles = ['global_admin'];

if (!$cdashAuth->hasIdentity()
    || !in_array($cdashAuth->getIdentity()->getRole(), $cdashAllowedRoles, true)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden.\n\nLog in to Omeka as an administrator, then try again.\n");
}
