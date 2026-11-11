<?php

declare(strict_types=1);

namespace GhostParse\Dav\Tests;

final class CalDavTest extends ServerTestCase
{
    private const CAL = '/dav/calendars/alice/work/';

    protected function setUp(): void
    {
        parent::setUp();

        $body = '<?xml version="1.0"?><cal:mkcalendar xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:ical="http://apple.com/ns/ical/">'
            . '<d:set><d:prop><d:displayname>Work</d:displayname><ical:calendar-color>#ff0000</ical:calendar-color>'
            . '<cal:supported-calendar-component-set><cal:comp name="VEVENT"/><cal:comp name="VTODO"/></cal:supported-calendar-component-set>'
            . '</d:prop></d:set></cal:mkcalendar>';
        $this->assertSame(201, $this->request('MKCALENDAR', self::CAL, $body)->getStatus());
    }

    public function testMkcalendarProperties(): void
    {
        $response = $this->propfind(self::CAL, '<d:displayname/><ical:calendar-color/><d:sync-token/><d:resourcetype/><d:supported-report-set/>');

        $this->assertSame('Work', $this->xpathValue($response, '//d:displayname'));
        $this->assertSame('#ff0000', $this->xpathValue($response, '//ical:calendar-color'));
        $this->assertSame('urn:ghostparse:sync:1', $this->xpathValue($response, '//d:sync-token'));
        $this->assertSame(1, $this->xpathCount($response, '//d:resourcetype/cal:calendar'));
        $this->assertSame(1, $this->xpathCount($response, '//d:supported-report/d:report/cal:calendar-query'));
    }

    public function testMkcalendarOnExistingFails(): void
    {
        $this->assertSame(405, $this->request('MKCALENDAR', self::CAL)->getStatus());
    }

    public function testExtendedMkcolCreatesCalendar(): void
    {
        $body = '<d:mkcol xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:set><d:prop>'
            . '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><d:displayname>Home</d:displayname>'
            . '</d:prop></d:set></d:mkcol>';
        $this->assertSame(201, $this->request('MKCOL', '/dav/calendars/alice/home/', $body)->getStatus());
        $this->assertSame('Home', $this->xpathValue($this->propfind('/dav/calendars/alice/home/', '<d:displayname/>'), '//d:displayname'));
    }

    public function testProppatch(): void
    {
        $body = '<d:propertyupdate xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:set><d:prop>'
            . '<d:displayname>Renamed</d:displayname><cal:calendar-description>Desc</cal:calendar-description>'
            . '</d:prop></d:set></d:propertyupdate>';
        $response = $this->request('PROPPATCH', self::CAL, $body);
        $this->assertSame(207, $response->getStatus());
        $this->assertStringContainsString('200', $this->xpathValue($response, '//d:status'));

        $response = $this->propfind(self::CAL, '<d:displayname/><cal:calendar-description/>');
        $this->assertSame('Renamed', $this->xpathValue($response, '//d:displayname'));
        $this->assertSame('Desc', $this->xpathValue($response, '//cal:calendar-description'));
    }

    public function testProppatchIsAtomic(): void
    {
        $body = '<d:propertyupdate xmlns:d="DAV:"><d:set><d:prop>'
            . '<d:displayname>Nope</d:displayname><d:getetag>x</d:getetag>'
            . '</d:prop></d:set></d:propertyupdate>';
        $response = $this->request('PROPPATCH', self::CAL, $body);
        $this->assertStringContainsString('403', $this->xpathValue($response, '//d:propstat[d:prop/d:getetag]/d:status'));
        $this->assertStringContainsString('424', $this->xpathValue($response, '//d:propstat[d:prop/d:displayname]/d:status'));
        $this->assertSame('Work', $this->xpathValue($this->propfind(self::CAL, '<d:displayname/>'), '//d:displayname'));
    }

    public function testPutGetDeleteEvent(): void
    {
        $data = self::event('evt-1');
        $response = $this->request('PUT', self::CAL . 'evt-1.ics', $data, ['Content-Type' => 'text/calendar']);
        $this->assertSame(201, $response->getStatus());
        $etag = (string) $response->getHeader('ETag');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag);

        $response = $this->request('GET', self::CAL . 'evt-1.ics');
        $this->assertSame(200, $response->getStatus());
        $this->assertSame($data, $response->getBody());
        $this->assertSame($etag, $response->getHeader('ETag'));
        $this->assertStringContainsString('component=vevent', (string) $response->getHeader('Content-Type'));

        $this->assertSame(304, $this->request('GET', self::CAL . 'evt-1.ics', '', ['If-None-Match' => $etag])->getStatus());

        $head = $this->request('HEAD', self::CAL . 'evt-1.ics');
        $this->assertSame('', $head->getBody());
        $this->assertSame((string) strlen($data), $head->getHeader('Content-Length'));

        // Create-only PUT must fail now that the object exists.
        $this->assertSame(412, $this->request('PUT', self::CAL . 'evt-1.ics', $data, ['If-None-Match' => '*'])->getStatus());
        // Stale If-Match.
        $this->assertSame(412, $this->request('PUT', self::CAL . 'evt-1.ics', $data, ['If-Match' => '"stale"'])->getStatus());

        $updated = self::event('evt-1', extra: "LOCATION:Office\r\n");
        $response = $this->request('PUT', self::CAL . 'evt-1.ics', $updated, ['If-Match' => $etag]);
        $this->assertSame(204, $response->getStatus());
        $this->assertNotSame($etag, $response->getHeader('ETag'));

        $this->assertSame(204, $this->request('DELETE', self::CAL . 'evt-1.ics')->getStatus());
        $this->assertSame(404, $this->request('GET', self::CAL . 'evt-1.ics')->getStatus());
    }

    public function testPutValidation(): void
    {
        $response = $this->request('PUT', self::CAL . 'bad.ics', "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('valid-calendar-data', $response->getBody());

        $noUid = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART:20250101T000000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->assertSame(403, $this->request('PUT', self::CAL . 'nouid.ics', $noUid)->getStatus());

        $journal = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VJOURNAL\r\nUID:j1\r\nDTSTART:20250101T000000Z\r\nEND:VJOURNAL\r\nEND:VCALENDAR\r\n";
        $response = $this->request('PUT', self::CAL . 'j1.ics', $journal);
        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('supported-calendar-component', $response->getBody());

        $this->assertSame(409, $this->request('PUT', '/dav/calendars/alice/missing/x.ics', self::event('x'))->getStatus());
    }

    public function testUidConflict(): void
    {
        $this->assertSame(201, $this->request('PUT', self::CAL . 'a.ics', self::event('same'))->getStatus());
        $response = $this->request('PUT', self::CAL . 'b.ics', self::event('same'));
        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('no-uid-conflict', $response->getBody());
    }

    public function testCalendarQueryTimeRange(): void
    {
        $this->request('PUT', self::CAL . 'jan.ics', self::event('jan', '20250110T100000Z', '20250110T110000Z'));
        $this->request('PUT', self::CAL . 'mar.ics', self::event('mar', '20250310T100000Z', '20250310T110000Z'));
        $this->request('PUT', self::CAL . 'weekly.ics', self::event('weekly', '20241201T090000Z', '20241201T100000Z', "RRULE:FREQ=WEEKLY\r\n"));
        $this->request('PUT', self::CAL . 'short.ics', self::event('short', '20240101T090000Z', '20240101T100000Z', "RRULE:FREQ=DAILY;COUNT=3\r\n"));
        $todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:todo\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
        $this->request('PUT', self::CAL . 'todo.ics', $todo);

        $response = $this->calendarQuery('VEVENT', '20250101T000000Z', '20250201T000000Z');
        $hrefs = $this->hrefs($response);
        sort($hrefs);
        $this->assertSame([self::CAL . 'jan.ics', self::CAL . 'weekly.ics'], $hrefs);
        $this->assertStringContainsString('UID:jan', $this->xpathValue($response, '//d:response[d:href="' . self::CAL . 'jan.ics"]//cal:calendar-data'));

        $response = $this->calendarQuery('VTODO', null, null);
        $this->assertSame([self::CAL . 'todo.ics'], $this->hrefs($response));
    }

    public function testCalendarQueryTextMatch(): void
    {
        $this->request('PUT', self::CAL . 'a.ics', self::event('a', extra: "LOCATION:Board Room\r\n"));
        $this->request('PUT', self::CAL . 'b.ics', self::event('b', extra: "LOCATION:Kitchen\r\n"));

        $body = '<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop>'
            . '<cal:filter><cal:comp-filter name="VCALENDAR"><cal:comp-filter name="VEVENT">'
            . '<cal:prop-filter name="LOCATION"><cal:text-match>board</cal:text-match></cal:prop-filter>'
            . '</cal:comp-filter></cal:comp-filter></cal:filter></cal:calendar-query>';
        $response = $this->request('REPORT', self::CAL, $body, ['Depth' => '1']);
        $this->assertSame([self::CAL . 'a.ics'], $this->hrefs($response));
    }

    public function testCalendarMultiget(): void
    {
        $this->request('PUT', self::CAL . 'a.ics', self::event('a'));

        $body = '<cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><cal:calendar-data/></d:prop>'
            . '<d:href>' . self::CAL . 'a.ics</d:href><d:href>https://example.com' . self::CAL . 'missing.ics</d:href>'
            . '</cal:calendar-multiget>';
        $response = $this->request('REPORT', self::CAL, $body, ['Depth' => '1']);

        $this->assertSame(207, $response->getStatus());
        $this->assertStringContainsString('UID:a', $this->xpathValue($response, '//cal:calendar-data'));
        $this->assertStringContainsString('404', $this->xpathValue($response, '//d:response[d:href="https://example.com' . self::CAL . 'missing.ics"]/d:status'));
    }

    public function testSyncCollection(): void
    {
        $this->request('PUT', self::CAL . 'a.ics', self::event('a'));
        $this->request('PUT', self::CAL . 'b.ics', self::event('b'));

        $response = $this->sync('');
        $this->assertCount(2, $this->hrefs($response));
        $token = $this->xpathValue($response, '/d:multistatus/d:sync-token');
        $this->assertSame('urn:ghostparse:sync:3', $token);

        $this->assertSame(0, count($this->hrefs($this->sync($token))));

        $this->request('PUT', self::CAL . 'c.ics', self::event('c'));
        $this->request('DELETE', self::CAL . 'a.ics');

        $response = $this->sync($token);
        $this->assertSame(1, $this->xpathCount($response, '//d:response[d:href="' . self::CAL . 'c.ics"]/d:propstat'));
        $this->assertStringContainsString('404', $this->xpathValue($response, '//d:response[d:href="' . self::CAL . 'a.ics"]/d:status'));
        $this->assertSame('urn:ghostparse:sync:5', $this->xpathValue($response, '/d:multistatus/d:sync-token'));

        $invalid = $this->sync('urn:ghostparse:sync:999');
        $this->assertSame(403, $invalid->getStatus());
        $this->assertStringContainsString('valid-sync-token', $invalid->getBody());
    }

    public function testCtagChangesOnWrite(): void
    {
        $before = $this->xpathValue($this->propfind(self::CAL, '<cs:getctag/>'), '//cs:getctag');
        $this->request('PUT', self::CAL . 'a.ics', self::event('a'));
        $after = $this->xpathValue($this->propfind(self::CAL, '<cs:getctag/>'), '//cs:getctag');
        $this->assertNotSame($before, $after);
    }

    public function testCalendarExportAndDelete(): void
    {
        $this->request('PUT', self::CAL . 'a.ics', self::event('a'));
        $this->request('PUT', self::CAL . 'b.ics', self::event('b'));

        $response = $this->request('GET', self::CAL);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(2, substr_count($response->getBody(), 'BEGIN:VEVENT'));
        $this->assertSame(1, substr_count($response->getBody(), 'BEGIN:VCALENDAR'));

        $this->assertSame(204, $this->request('DELETE', self::CAL)->getStatus());
        $this->assertSame(404, $this->propfind(self::CAL, '<d:displayname/>')->getStatus());
    }

    private function calendarQuery(string $component, ?string $start, ?string $end): \GhostParse\Dav\Http\Response
    {
        $range = '';
        if ($start !== null || $end !== null) {
            $range = '<cal:time-range' . ($start ? ' start="' . $start . '"' : '') . ($end ? ' end="' . $end . '"' : '') . '/>';
        }
        $body = '<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><cal:calendar-data/></d:prop>'
            . '<cal:filter><cal:comp-filter name="VCALENDAR"><cal:comp-filter name="' . $component . '">' . $range
            . '</cal:comp-filter></cal:comp-filter></cal:filter></cal:calendar-query>';
        $response = $this->request('REPORT', self::CAL, $body, ['Depth' => '1']);
        $this->assertSame(207, $response->getStatus(), $response->getBody());

        return $response;
    }

    private function sync(string $token): \GhostParse\Dav\Http\Response
    {
        $body = '<d:sync-collection xmlns:d="DAV:"><d:sync-token>' . $token . '</d:sync-token><d:sync-level>1</d:sync-level>'
            . '<d:prop><d:getetag/></d:prop></d:sync-collection>';

        return $this->request('REPORT', self::CAL, $body);
    }

    /**
     * @return list<string>
     */
    private function hrefs(\GhostParse\Dav\Http\Response $response): array
    {
        $result = [];
        foreach ($this->xpath($response)->query('//d:response/d:href') ?: [] as $node) {
            $result[] = $node->textContent;
        }

        return $result;
    }
}
