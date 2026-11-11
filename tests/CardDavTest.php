<?php

declare(strict_types=1);

namespace GhostParse\Dav\Tests;

final class CardDavTest extends ServerTestCase
{
    private const BOOK = '/dav/addressbooks/alice/friends/';

    protected function setUp(): void
    {
        parent::setUp();

        $body = '<d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
            . '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>Friends</d:displayname>'
            . '</d:prop></d:set></d:mkcol>';
        $this->assertSame(201, $this->request('MKCOL', self::BOOK, $body)->getStatus());
    }

    public function testAddressBookProperties(): void
    {
        $response = $this->propfind(self::BOOK, '<d:displayname/><d:resourcetype/><card:supported-address-data/><d:sync-token/>');
        $this->assertSame('Friends', $this->xpathValue($response, '//d:displayname'));
        $this->assertSame(1, $this->xpathCount($response, '//d:resourcetype/card:addressbook'));
        $this->assertSame(2, $this->xpathCount($response, '//card:supported-address-data/card:address-data-type'));
    }

    public function testPutGetDeleteCard(): void
    {
        $data = self::vcard('c1', 'Carol Smith', 'carol@example.com');
        $response = $this->request('PUT', self::BOOK . 'c1.vcf', $data, ['If-None-Match' => '*']);
        $this->assertSame(201, $response->getStatus());

        $response = $this->request('GET', self::BOOK . 'c1.vcf');
        $this->assertSame($data, $response->getBody());
        $this->assertSame('text/vcard; charset=utf-8', $response->getHeader('Content-Type'));

        $response = $this->propfind(self::BOOK, '<d:getetag/>', '1');
        $this->assertSame(2, $this->xpathCount($response, '//d:response'));

        $this->assertSame(204, $this->request('DELETE', self::BOOK . 'c1.vcf')->getStatus());
        $this->assertSame(404, $this->request('DELETE', self::BOOK . 'c1.vcf')->getStatus());
    }

    public function testCardValidation(): void
    {
        $response = $this->request('PUT', self::BOOK . 'x.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No UID\r\nEND:VCARD\r\n");
        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('valid-address-data', $response->getBody());

        $this->request('PUT', self::BOOK . 'a.vcf', self::vcard('dup', 'A'));
        $response = $this->request('PUT', self::BOOK . 'b.vcf', self::vcard('dup', 'B'));
        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('no-uid-conflict', $response->getBody());
    }

    public function testAddressBookQuery(): void
    {
        $this->request('PUT', self::BOOK . 'a.vcf', self::vcard('a', 'Ånne Jones', 'anne@example.com'));
        $this->request('PUT', self::BOOK . 'b.vcf', self::vcard('b', 'Bert Brown', 'bert@corp.example'));
        $this->request('PUT', self::BOOK . 'c.vcf', self::vcard('c', 'Cleo Jones'));

        $response = $this->query('<card:prop-filter name="FN"><card:text-match match-type="contains">jones</card:text-match></card:prop-filter>');
        $this->assertSame(2, $this->xpathCount($response, '//d:response'));

        $response = $this->query('<card:prop-filter name="FN"><card:text-match match-type="starts-with">åNNE</card:text-match></card:prop-filter>');
        $this->assertSame(1, $this->xpathCount($response, '//d:response'));
        $this->assertStringContainsString('FN:Ånne Jones', $this->xpathValue($response, '//card:address-data'));

        $response = $this->query('<card:prop-filter name="EMAIL"><card:is-not-defined/></card:prop-filter>');
        $this->assertSame(self::BOOK . 'c.vcf', $this->xpathValue($response, '//d:response/d:href'));

        $response = $this->query(
            '<card:prop-filter name="FN"><card:text-match>jones</card:text-match></card:prop-filter>'
            . '<card:prop-filter name="EMAIL"><card:text-match match-type="ends-with">example.com</card:text-match></card:prop-filter>',
            'allof',
        );
        $this->assertSame(1, $this->xpathCount($response, '//d:response'));

        $response = $this->query('<card:prop-filter name="EMAIL"><card:param-filter name="TYPE"><card:text-match match-type="equals">work</card:text-match></card:param-filter></card:prop-filter>');
        $this->assertSame(2, $this->xpathCount($response, '//d:response'));
    }

    public function testAddressBookMultigetAndSync(): void
    {
        $this->request('PUT', self::BOOK . 'a.vcf', self::vcard('a', 'Anne'));

        $body = '<card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/><card:address-data/></d:prop><d:href>' . self::BOOK . 'a.vcf</d:href></card:addressbook-multiget>';
        $response = $this->request('REPORT', self::BOOK, $body);
        $this->assertStringContainsString('FN:Anne', $this->xpathValue($response, '//card:address-data'));

        $sync = '<d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:prop><d:getetag/></d:prop></d:sync-collection>';
        $response = $this->request('REPORT', self::BOOK, $sync);
        $this->assertSame(1, $this->xpathCount($response, '//d:response'));
        $this->assertSame('urn:ghostparse:sync:2', $this->xpathValue($response, '//d:sync-token'));
    }

    private function query(string $filters, string $test = 'anyof'): \GhostParse\Dav\Http\Response
    {
        $body = '<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/><card:address-data/></d:prop>'
            . '<card:filter test="' . $test . '">' . $filters . '</card:filter></card:addressbook-query>';
        $response = $this->request('REPORT', self::BOOK, $body, ['Depth' => '1']);
        $this->assertSame(207, $response->getStatus(), $response->getBody());

        return $response;
    }
}
