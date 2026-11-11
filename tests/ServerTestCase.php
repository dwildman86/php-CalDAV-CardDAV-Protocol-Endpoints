<?php

declare(strict_types=1);

namespace GhostParse\Dav\Tests;

use DOMDocument;
use DOMXPath;
use GhostParse\Dav\Backend\Pdo\PdoAddressBookBackend;
use GhostParse\Dav\Backend\Pdo\PdoCalendarBackend;
use GhostParse\Dav\Backend\Pdo\PdoUserBackend;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Schema\Schema;
use GhostParse\Dav\Server;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class ServerTestCase extends TestCase
{
    protected PDO $pdo;

    protected Server $server;

    protected PdoUserBackend $users;

    protected PdoCalendarBackend $calendars;

    protected PdoAddressBookBackend $addressBooks;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        Schema::install($this->pdo);

        $this->users = new PdoUserBackend($this->pdo);
        $this->users->createUser('alice', 'secret', 'Alice Example', 'alice@example.com');
        $this->users->createUser('bob', 'hunter2', 'Bob');

        $this->calendars = new PdoCalendarBackend($this->pdo);
        $this->addressBooks = new PdoAddressBookBackend($this->pdo);

        $this->server = new Server(
            $this->users,
            $this->users,
            $this->calendars,
            $this->addressBooks,
            baseUri: '/dav/',
            debug: true,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, string $body = '', array $headers = [], ?string $user = 'alice', string $password = ''): Response
    {
        if ($user !== null) {
            $password = $password !== '' ? $password : ($user === 'alice' ? 'secret' : 'hunter2');
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $password);
        }

        return $this->server->handle(new Request($method, $path, $headers, $body));
    }

    protected function xpath(Response $response): DOMXPath
    {
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($response->getBody()), 'Response is not valid XML: ' . $response->getBody());
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');
        $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');
        $xpath->registerNamespace('cs', 'http://calendarserver.org/ns/');
        $xpath->registerNamespace('ical', 'http://apple.com/ns/ical/');

        return $xpath;
    }

    protected function xpathValue(Response $response, string $query): string
    {
        $nodes = $this->xpath($response)->query($query);
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "No match for $query in:\n" . $response->getBody());

        return (string) $nodes->item(0)?->textContent;
    }

    protected function xpathCount(Response $response, string $query): int
    {
        $nodes = $this->xpath($response)->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    protected static function event(string $uid, string $start = '20250110T100000Z', string $end = '20250110T110000Z', string $extra = ''): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:$start\r\nDTEND:$end\r\nSUMMARY:Event $uid\r\n{$extra}END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    protected static function vcard(string $uid, string $name, string $email = ''): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$name\r\nN:;$name;;;\r\n"
            . ($email !== '' ? "EMAIL;TYPE=WORK:$email\r\n" : '')
            . "END:VCARD\r\n";
    }

    protected function propfind(string $path, string $props, string $depth = '0', ?string $user = 'alice'): Response
    {
        $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" '
            . 'xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ical="http://apple.com/ns/ical/">'
            . '<d:prop>' . $props . '</d:prop></d:propfind>';

        return $this->request('PROPFIND', $path, $body, ['Depth' => $depth], $user);
    }
}
