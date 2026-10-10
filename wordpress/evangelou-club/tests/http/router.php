<?php
/**
 * CI-ONLY router for `php -S` serving a disposable WordPress (pretty
 * permalinks). Existing files (assets, wp-login.php, …) are served by the
 * built-in server; everything else goes through WordPress's index.php.
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
chdir($_SERVER['DOCUMENT_ROOT']);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
