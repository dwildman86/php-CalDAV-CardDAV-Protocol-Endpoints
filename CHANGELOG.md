# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-11

### Added
- CalDAV server endpoints (RFC 4791): MKCALENDAR, calendar-query, calendar-multiget, object validation.
- CardDAV server endpoints (RFC 6352): addressbook-query, addressbook-multiget, vCard validation.
- WebDAV core: PROPFIND, PROPPATCH, GET/HEAD/PUT/DELETE, conditional requests, extended MKCOL.
- Collection synchronisation (RFC 6578) and CTags.
- Service discovery via `/.well-known/caldav` and `/.well-known/carddav` (RFC 6764).
- PDO backends for SQLite, MySQL/MariaDB and PostgreSQL with optional table prefixes.
- `ghostparse-dav` command-line tool for schema installation and user management.
