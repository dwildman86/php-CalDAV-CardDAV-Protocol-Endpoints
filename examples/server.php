<?php

/**
 * Minimal CalDAV + CardDAV server backed by SQLite.
 *
 * 1. composer require ghostparse/caldav-carddav
 * 2. vendor/bin/ghostparse-dav schema:install --dsn="sqlite:/path/to/dav.sqlite"
 * 3. vendor/bin/ghostparse-dav user:add alice --dsn="sqlite:/path/to/dav.sqlite"
 * 4. Route every request under /dav/ (and /.well-known/caldav|carddav) to this file.
 */

declare(strict_types=1);

use GhostParse\Dav\Backend\Pdo\PdoAddressBookBackend;
use GhostParse\Dav\Backend\Pdo\PdoCalendarBackend;
use GhostParse\Dav\Backend\Pdo\PdoUserBackend;
use GhostParse\Dav\Server;

require __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO('sqlite:' . __DIR__ . '/dav.sqlite');

$users = new PdoUserBackend($pdo);

$server = new Server(
    authBackend: $users,
    principalBackend: $users,
    calendarBackend: new PdoCalendarBackend($pdo),
    addressBookBackend: new PdoAddressBookBackend($pdo),
    baseUri: '/dav/',
);

$server->setErrorLogger(static function (Throwable $e): void {
    error_log((string) $e);
});

$server->run();
