<?php

declare(strict_types=1);

namespace GhostParse\Dav\Schema;

use PDO;

/**
 * Database schema for the bundled PDO backends (SQLite, MySQL/MariaDB, PostgreSQL).
 */
final class Schema
{
    /**
     * Return the CREATE statements for a PDO driver name.
     *
     * @return list<string>
     */
    public static function statements(string $driver, string $prefix = ''): array
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Table prefix may only contain letters, digits and underscores');
        }

        $sql = match ($driver) {
            'sqlite' => self::sqlite(),
            'mysql' => self::mysql(),
            'pgsql' => self::pgsql(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported PDO driver "%s"', $driver)),
        };

        return array_map(static fn (string $s): string => str_replace('{p}', $prefix, $s), $sql);
    }

    /**
     * Return the schema as an SQL script.
     */
    public static function dump(string $driver, string $prefix = ''): string
    {
        return implode(";\n\n", self::statements($driver, $prefix)) . ";\n";
    }

    /**
     * Create all tables on the given connection.
     */
    public static function install(PDO $pdo, string $prefix = ''): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (self::statements($driver, $prefix) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    private static function sqlite(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS {p}users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                displayname TEXT NULL,
                email TEXT NULL
            )',
            'CREATE TABLE IF NOT EXISTS {p}calendars (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner TEXT NOT NULL,
                uri TEXT NOT NULL,
                displayname TEXT NULL,
                description TEXT NULL,
                color TEXT NULL,
                sortorder INTEGER NULL,
                timezone TEXT NULL,
                components TEXT NOT NULL,
                synctoken INTEGER NOT NULL DEFAULT 1,
                UNIQUE (owner, uri)
            )',
            'CREATE TABLE IF NOT EXISTS {p}calendarobjects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                calendarid INTEGER NOT NULL,
                uri TEXT NOT NULL,
                data TEXT NOT NULL,
                etag TEXT NOT NULL,
                size INTEGER NOT NULL,
                lastmodified INTEGER NOT NULL,
                componenttype TEXT NULL,
                uid TEXT NULL,
                firstoccurrence INTEGER NULL,
                lastoccurrence INTEGER NULL,
                UNIQUE (calendarid, uri)
            )',
            'CREATE INDEX IF NOT EXISTS {p}calendarobjects_uid ON {p}calendarobjects (calendarid, uid)',
            'CREATE TABLE IF NOT EXISTS {p}calendarchanges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                calendarid INTEGER NOT NULL,
                uri TEXT NOT NULL,
                synctoken INTEGER NOT NULL,
                operation INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS {p}calendarchanges_token ON {p}calendarchanges (calendarid, synctoken)',
            'CREATE TABLE IF NOT EXISTS {p}addressbooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner TEXT NOT NULL,
                uri TEXT NOT NULL,
                displayname TEXT NULL,
                description TEXT NULL,
                synctoken INTEGER NOT NULL DEFAULT 1,
                UNIQUE (owner, uri)
            )',
            'CREATE TABLE IF NOT EXISTS {p}cards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                addressbookid INTEGER NOT NULL,
                uri TEXT NOT NULL,
                data TEXT NOT NULL,
                etag TEXT NOT NULL,
                size INTEGER NOT NULL,
                lastmodified INTEGER NOT NULL,
                uid TEXT NULL,
                UNIQUE (addressbookid, uri)
            )',
            'CREATE INDEX IF NOT EXISTS {p}cards_uid ON {p}cards (addressbookid, uid)',
            'CREATE TABLE IF NOT EXISTS {p}addressbookchanges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                addressbookid INTEGER NOT NULL,
                uri TEXT NOT NULL,
                synctoken INTEGER NOT NULL,
                operation INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS {p}addressbookchanges_token ON {p}addressbookchanges (addressbookid, synctoken)',
        ];
    }

    /**
     * @return list<string>
     */
    private static function mysql(): array
    {
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';

        return [
            'CREATE TABLE IF NOT EXISTS {p}users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                displayname VARCHAR(255) NULL,
                email VARCHAR(255) NULL,
                UNIQUE KEY {p}users_username (username)
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS {p}calendars (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner VARCHAR(190) NOT NULL,
                uri VARCHAR(190) NOT NULL,
                displayname VARCHAR(255) NULL,
                description TEXT NULL,
                color VARCHAR(32) NULL,
                sortorder INT NULL,
                timezone MEDIUMTEXT NULL,
                components VARCHAR(255) NOT NULL,
                synctoken INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE KEY {p}calendars_owner_uri (owner, uri)
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS {p}calendarobjects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                calendarid INT UNSIGNED NOT NULL,
                uri VARCHAR(190) NOT NULL,
                data MEDIUMBLOB NOT NULL,
                etag VARCHAR(64) NOT NULL,
                size INT UNSIGNED NOT NULL,
                lastmodified INT UNSIGNED NOT NULL,
                componenttype VARCHAR(16) NULL,
                uid VARCHAR(255) NULL,
                firstoccurrence BIGINT NULL,
                lastoccurrence BIGINT NULL,
                UNIQUE KEY {p}calendarobjects_cal_uri (calendarid, uri),
                KEY {p}calendarobjects_uid (calendarid, uid(190))
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS {p}calendarchanges (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                calendarid INT UNSIGNED NOT NULL,
                uri VARCHAR(190) NOT NULL,
                synctoken INT UNSIGNED NOT NULL,
                operation TINYINT NOT NULL,
                KEY {p}calendarchanges_token (calendarid, synctoken)
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS {p}addressbooks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner VARCHAR(190) NOT NULL,
                uri VARCHAR(190) NOT NULL,
                displayname VARCHAR(255) NULL,
                description TEXT NULL,
                synctoken INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE KEY {p}addressbooks_owner_uri (owner, uri)
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS {p}cards (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                addressbookid INT UNSIGNED NOT NULL,
                uri VARCHAR(190) NOT NULL,
                data MEDIUMBLOB NOT NULL,
                etag VARCHAR(64) NOT NULL,
                size INT UNSIGNED NOT NULL,
                lastmodified INT UNSIGNED NOT NULL,
                uid VARCHAR(255) NULL,
                UNIQUE KEY {p}cards_book_uri (addressbookid, uri),
                KEY {p}cards_uid (addressbookid, uid(190))
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS {p}addressbookchanges (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                addressbookid INT UNSIGNED NOT NULL,
                uri VARCHAR(190) NOT NULL,
                synctoken INT UNSIGNED NOT NULL,
                operation TINYINT NOT NULL,
                KEY {p}addressbookchanges_token (addressbookid, synctoken)
            )' . $engine,
        ];
    }

    /**
     * @return list<string>
     */
    private static function pgsql(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS {p}users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                displayname VARCHAR(255) NULL,
                email VARCHAR(255) NULL
            )',
            'CREATE TABLE IF NOT EXISTS {p}calendars (
                id SERIAL PRIMARY KEY,
                owner VARCHAR(255) NOT NULL,
                uri VARCHAR(255) NOT NULL,
                displayname VARCHAR(255) NULL,
                description TEXT NULL,
                color VARCHAR(32) NULL,
                sortorder INTEGER NULL,
                timezone TEXT NULL,
                components VARCHAR(255) NOT NULL,
                synctoken INTEGER NOT NULL DEFAULT 1,
                UNIQUE (owner, uri)
            )',
            'CREATE TABLE IF NOT EXISTS {p}calendarobjects (
                id SERIAL PRIMARY KEY,
                calendarid INTEGER NOT NULL,
                uri VARCHAR(255) NOT NULL,
                data TEXT NOT NULL,
                etag VARCHAR(64) NOT NULL,
                size INTEGER NOT NULL,
                lastmodified BIGINT NOT NULL,
                componenttype VARCHAR(16) NULL,
                uid VARCHAR(255) NULL,
                firstoccurrence BIGINT NULL,
                lastoccurrence BIGINT NULL,
                UNIQUE (calendarid, uri)
            )',
            'CREATE INDEX IF NOT EXISTS {p}calendarobjects_uid ON {p}calendarobjects (calendarid, uid)',
            'CREATE TABLE IF NOT EXISTS {p}calendarchanges (
                id SERIAL PRIMARY KEY,
                calendarid INTEGER NOT NULL,
                uri VARCHAR(255) NOT NULL,
                synctoken INTEGER NOT NULL,
                operation SMALLINT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS {p}calendarchanges_token ON {p}calendarchanges (calendarid, synctoken)',
            'CREATE TABLE IF NOT EXISTS {p}addressbooks (
                id SERIAL PRIMARY KEY,
                owner VARCHAR(255) NOT NULL,
                uri VARCHAR(255) NOT NULL,
                displayname VARCHAR(255) NULL,
                description TEXT NULL,
                synctoken INTEGER NOT NULL DEFAULT 1,
                UNIQUE (owner, uri)
            )',
            'CREATE TABLE IF NOT EXISTS {p}cards (
                id SERIAL PRIMARY KEY,
                addressbookid INTEGER NOT NULL,
                uri VARCHAR(255) NOT NULL,
                data TEXT NOT NULL,
                etag VARCHAR(64) NOT NULL,
                size INTEGER NOT NULL,
                lastmodified BIGINT NOT NULL,
                uid VARCHAR(255) NULL,
                UNIQUE (addressbookid, uri)
            )',
            'CREATE INDEX IF NOT EXISTS {p}cards_uid ON {p}cards (addressbookid, uid)',
            'CREATE TABLE IF NOT EXISTS {p}addressbookchanges (
                id SERIAL PRIMARY KEY,
                addressbookid INTEGER NOT NULL,
                uri VARCHAR(255) NOT NULL,
                synctoken INTEGER NOT NULL,
                operation SMALLINT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS {p}addressbookchanges_token ON {p}addressbookchanges (addressbookid, synctoken)',
        ];
    }
}
