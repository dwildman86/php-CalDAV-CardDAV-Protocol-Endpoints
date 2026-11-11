<?php

declare(strict_types=1);

namespace GhostParse\Dav\Tests;

final class DiscoveryTest extends ServerTestCase
{
    public function testOptionsAdvertisesCapabilities(): void
    {
        $response = $this->request('OPTIONS', '/dav/', user: null);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('calendar-access', (string) $response->getHeader('DAV'));
        $this->assertStringContainsString('addressbook', (string) $response->getHeader('DAV'));
        $this->assertStringContainsString('PROPFIND', (string) $response->getHeader('Allow'));
    }

    public function testRequiresAuthentication(): void
    {
        $response = $this->request('PROPFIND', '/dav/', user: null);
        $this->assertSame(401, $response->getStatus());
        $this->assertStringStartsWith('Basic realm=', (string) $response->getHeader('WWW-Authenticate'));

        $response = $this->request('PROPFIND', '/dav/', user: 'alice', password: 'wrong');
        $this->assertSame(401, $response->getStatus());
    }

    public function testWellKnownRedirect(): void
    {
        foreach (['/.well-known/caldav', '/.well-known/carddav'] as $path) {
            $response = $this->request('GET', $path, user: null);
            $this->assertSame(301, $response->getStatus());
            $this->assertSame('/dav/', $response->getHeader('Location'));
        }
    }

    public function testCurrentUserPrincipal(): void
    {
        $response = $this->propfind('/dav/', '<d:current-user-principal/>');

        $this->assertSame(207, $response->getStatus());
        $this->assertSame('/dav/principals/alice/', $this->xpathValue($response, '//d:current-user-principal/d:href'));
    }

    public function testPrincipalProperties(): void
    {
        $response = $this->propfind(
            '/dav/principals/alice/',
            '<d:displayname/><cal:calendar-home-set/><card:addressbook-home-set/><cal:calendar-user-address-set/><d:resourcetype/><d:unknown-prop/>',
        );

        $this->assertSame('Alice Example', $this->xpathValue($response, '//d:displayname'));
        $this->assertSame('/dav/calendars/alice/', $this->xpathValue($response, '//cal:calendar-home-set/d:href'));
        $this->assertSame('/dav/addressbooks/alice/', $this->xpathValue($response, '//card:addressbook-home-set/d:href'));
        $this->assertSame('mailto:alice@example.com', $this->xpathValue($response, '//cal:calendar-user-address-set/d:href[1]'));
        $this->assertSame(1, $this->xpathCount($response, '//d:resourcetype/d:principal'));
        $this->assertStringContainsString('404', $this->xpathValue($response, '//d:propstat[d:prop/d:unknown-prop]/d:status'));
    }

    public function testPreferMinimalOmitsNotFound(): void
    {
        $body = '<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:nope/></d:prop></d:propfind>';
        $response = $this->request('PROPFIND', '/dav/principals/alice/', $body, ['Depth' => '0', 'Prefer' => 'return=minimal']);

        $this->assertSame(0, $this->xpathCount($response, '//d:nope'));
        $this->assertSame('return=minimal', $response->getHeader('Preference-Applied'));
    }

    public function testHomeIsAutoProvisioned(): void
    {
        $response = $this->propfind('/dav/calendars/alice/', '<d:resourcetype/><d:displayname/><cal:supported-calendar-component-set/><cs:getctag/>', '1');

        $this->assertSame('/dav/calendars/alice/default/', $this->xpathValue($response, '//d:response[d:propstat/d:prop/d:resourcetype/cal:calendar]/d:href'));
        $this->assertSame('Calendar', $this->xpathValue($response, '//d:response[d:href="/dav/calendars/alice/default/"]//d:displayname'));
        $this->assertSame(2, $this->xpathCount($response, '//cal:supported-calendar-component-set/cal:comp'));

        $response = $this->propfind('/dav/addressbooks/alice/', '<d:resourcetype/>', '1');
        $this->assertSame('/dav/addressbooks/alice/default/', $this->xpathValue($response, '//d:response[d:propstat/d:prop/d:resourcetype/card:addressbook]/d:href'));
    }

    public function testOtherUsersDataIsForbidden(): void
    {
        $this->request('PROPFIND', '/dav/calendars/bob/', '', ['Depth' => '0'], 'bob');

        $this->assertSame(403, $this->propfind('/dav/calendars/bob/', '<d:displayname/>')->getStatus());
        $this->assertSame(403, $this->request('GET', '/dav/calendars/bob/default/')->getStatus());
        $this->assertSame(403, $this->request('PUT', '/dav/addressbooks/bob/default/x.vcf', self::vcard('x', 'X'))->getStatus());
    }

    public function testUnknownPathIs404(): void
    {
        $this->assertSame(404, $this->request('PROPFIND', '/dav/nothing/')->getStatus());
        $this->assertSame(404, $this->request('PROPFIND', '/elsewhere/')->getStatus());
        $this->assertSame(404, $this->propfind('/dav/calendars/alice/missing/', '<d:displayname/>')->getStatus());
    }

    public function testRejectsDoctype(): void
    {
        $body = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY x "y">]><d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>';
        $this->assertSame(400, $this->request('PROPFIND', '/dav/', $body)->getStatus());
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->request('PUT', '/dav/principals/alice/', 'x');
        $this->assertSame(405, $response->getStatus());
        $this->assertNotNull($response->getHeader('Allow'));
    }
}
