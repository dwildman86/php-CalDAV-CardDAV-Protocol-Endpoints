# GhostParse CalDAV & CardDAV

Lightweight, dependency-free **CalDAV** ([RFC 4791](https://www.rfc-editor.org/rfc/rfc4791)) and
**CardDAV** ([RFC 6352](https://www.rfc-editor.org/rfc/rfc6352)) protocol endpoints for PHP 8.1+.

Drop it into any PHP application, point your web server at a single front controller, and sync
calendars and contacts with Apple Calendar/Contacts, iOS, Thunderbird, DAVx⁵, Evolution and other
standards-compliant clients.

- No framework and no third-party runtime dependencies (only `ext-dom` and `ext-pdo`)
- SQLite, MySQL/MariaDB and PostgreSQL storage out of the box
- Pluggable backends: bring your own user store, calendar storage or address book storage
- Efficient incremental sync (`sync-collection`, RFC 6578) and CTags
- Hardened XML parsing (no DTDs / external entities)
- Command-line tool for schema installation and user management

## Installation

```bash
composer require ghostparse/caldav-carddav
```

## Quick start

### 1. Create the database and a user

```bash
vendor/bin/ghostparse-dav schema:install --dsn="sqlite:/var/lib/dav/dav.sqlite"
vendor/bin/ghostparse-dav user:add alice --dsn="sqlite:/var/lib/dav/dav.sqlite" --email=alice@example.com --name="Alice"
```

For MySQL / PostgreSQL use a matching DSN and pass `--db-user` / `--db-password`
(or set `DAV_DB_USER` / `DAV_DB_PASSWORD`):

```bash
vendor/bin/ghostparse-dav schema:install --dsn="mysql:host=localhost;dbname=dav;charset=utf8mb4" --db-user=dav
```

`vendor/bin/ghostparse-dav schema:dump --driver=mysql` prints the SQL if you prefer to run it yourself.

### 2. Create a front controller

```php
<?php
// public/dav.php

use GhostParse\Dav\Backend\Pdo\PdoAddressBookBackend;
use GhostParse\Dav\Backend\Pdo\PdoCalendarBackend;
use GhostParse\Dav\Backend\Pdo\PdoUserBackend;
use GhostParse\Dav\Server;

require __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO('sqlite:/var/lib/dav/dav.sqlite');
$users = new PdoUserBackend($pdo);

$server = new Server(
    authBackend: $users,
    principalBackend: $users,
    calendarBackend: new PdoCalendarBackend($pdo),
    addressBookBackend: new PdoAddressBookBackend($pdo),
    baseUri: '/dav/',
);

$server->run();
```

### 3. Route requests to it

All requests below the base URI — plus the `/.well-known/caldav` and `/.well-known/carddav`
discovery URLs — must reach the front controller with the original method and headers intact.

**nginx**

```nginx
location ~ ^/(dav/|\.well-known/(caldav|carddav)) {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /srv/app/public/dav.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

**Apache (.htaccess)**

```apache
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteRule ^(dav/|\.well-known/(caldav|carddav)) dav.php [L]
```

**PHP built-in server (development only)**

```bash
php -S 127.0.0.1:8080 public/dav.php
```

### 4. Connect a client

Use `https://your-host/` (clients follow the `.well-known` redirect) or the explicit URLs:

| Client | URL |
| --- | --- |
| Apple Calendar / iOS (CalDAV account) | `https://your-host/dav/principals/alice/` |
| Apple Contacts / iOS (CardDAV account) | `https://your-host/dav/principals/alice/` |
| DAVx⁵ | `https://your-host/dav/` |
| Thunderbird (calendar) | `https://your-host/dav/calendars/alice/default/` |
| Thunderbird (address book) | `https://your-host/dav/addressbooks/alice/default/` |

> **Always serve the endpoints over HTTPS.** Clients authenticate with HTTP Basic auth, which
> sends credentials with every request.

## URL layout

```
/dav/                                       root (current-user-principal discovery)
/dav/principals/{user}/                     principal
/dav/calendars/{user}/                      calendar home
/dav/calendars/{user}/{calendar}/           calendar collection
/dav/calendars/{user}/{calendar}/{uid}.ics  calendar object
/dav/addressbooks/{user}/                   address book home
/dav/addressbooks/{user}/{book}/            address book collection
/dav/addressbooks/{user}/{book}/{uid}.vcf   vCard
```

New users automatically receive a `default` calendar and a `default` address book the first time
their client connects (disable with `autoProvision: false`).

## Server options

| Option | Default | Description |
| --- | --- | --- |
| `authBackend` | — | `AuthBackendInterface` that verifies Basic credentials |
| `principalBackend` | — | `PrincipalBackendInterface` providing display names and e-mail addresses |
| `calendarBackend` | `null` | `CalendarBackendInterface`; omit to disable CalDAV |
| `addressBookBackend` | `null` | `AddressBookBackendInterface`; omit to disable CardDAV |
| `baseUri` | `/` | Path prefix the server is mounted on |
| `realm` | `GhostParse DAV` | Basic authentication realm |
| `autoProvision` | `true` | Create a default calendar / address book for new users |
| `maxResourceSize` | `10485760` | Maximum size (bytes) of a single `.ics` / `.vcf` upload |
| `debug` | `false` | Include exception messages in 500 responses |

Use `$server->setErrorLogger(fn (Throwable $e) => ...)` to log unexpected errors.

For frameworks and tests, `$server->handle(Request $request): Response` processes a request without
touching PHP's output; build a `GhostParse\Dav\Http\Request` from your framework's request object and
copy the returned `Response` status, headers and body back.

## Custom backends

Every storage concern is an interface, so the server can sit on top of an existing application:

```php
use GhostParse\Dav\Auth\CallbackAuthBackend;

$auth = new CallbackAuthBackend(
    fn (string $username, string $password): bool => $myApp->verifyLogin($username, $password),
);
```

| Interface | Purpose | Bundled implementation |
| --- | --- | --- |
| `Auth\AuthBackendInterface` | Verify credentials | `PdoUserBackend`, `CallbackAuthBackend` |
| `Backend\PrincipalBackendInterface` | Look up users | `PdoUserBackend` |
| `Backend\CalendarBackendInterface` | Calendars, events, tasks, change log | `PdoCalendarBackend` |
| `Backend\AddressBookBackendInterface` | Address books, vCards, change log | `PdoAddressBookBackend` |

All PDO backends accept an optional table prefix as their second constructor argument
(`new PdoCalendarBackend($pdo, 'dav_')`); pass the same prefix to `schema:install --prefix=dav_`.

## Supported protocol features

| Area | Features |
| --- | --- |
| WebDAV | `OPTIONS`, `PROPFIND` (`prop`, `allprop`, `propname`, Depth 0/1), `PROPPATCH` (atomic), `GET`, `HEAD`, `PUT`, `DELETE`, `If-Match` / `If-None-Match`, extended `MKCOL` (RFC 5689), `Prefer: return=minimal` |
| CalDAV | `MKCALENDAR`, `calendar-query` (comp/prop/param filters, text-match, time-range), `calendar-multiget`, supported component sets, `no-uid-conflict` and data validation, Apple calendar colour/order, whole-calendar `.ics` export via `GET` |
| CardDAV | `addressbook-query` (prop/param filters, all match types, `anyof`/`allof`, `limit`), `addressbook-multiget`, vCard 3.0 / 4.0 storage, `no-uid-conflict`, whole-book `.vcf` export via `GET` |
| Sync | `sync-collection` (RFC 6578), CalendarServer `getctag` |
| Discovery | `current-user-principal`, `calendar-home-set`, `addressbook-home-set`, `calendar-user-address-set`, `/.well-known/caldav` & `/.well-known/carddav` (RFC 6764) |

### Limitations

- Each user can only access their own collections; sharing, delegation and full WebDAV ACL are not implemented.
- Recurring events are not expanded instance by instance in `calendar-query`; the span from the first to the last possible occurrence is used, which can return a few extra objects (clients filter these). `expand` / `limit-recurrence-set` and partial `calendar-data` retrieval return the full object.
- Scheduling (RFC 6638 inbox/outbox, iTIP), `free-busy-query`, `LOCK`/`UNLOCK`, `COPY`/`MOVE` are not supported.
- vCards are stored and returned as uploaded; no 3.0 ↔ 4.0 conversion is performed.

## Development

```bash
composer install
composer test
```

## License

MIT © GhostParse. See [LICENSE](LICENSE).
