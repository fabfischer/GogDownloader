<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage\Nextcloud;

use App\Storage\Exception\UnexpectedStorageResponse;
use App\Storage\Nextcloud\DavEntry;
use App\Storage\Nextcloud\MultiStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MultiStatus::class)]
final class MultiStatusTest extends TestCase
{
    public function testAnUploadFolderListing(): void
    {
        $entries = MultiStatus::parse(<<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns">
              <d:response><d:href>/remote.php/dav/uploads/archiver/gog-archive-0123/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype><d:getetag>"abc"</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
                <d:propstat><d:prop><d:getcontentlength/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>
              </d:response>
              <d:response><d:href>/remote.php/dav/uploads/archiver/gog-archive-0123/00001</d:href>
                <d:propstat><d:prop><d:resourcetype/><d:getcontentlength>5242880</d:getcontentlength><d:getetag>&quot;e1&quot;</d:getetag><d:getlastmodified>Wed, 11 Nov 2026 22:14:40 GMT</d:getlastmodified></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
              </d:response>
            </d:multistatus>
            XML);

        self::assertCount(2, $entries);
        self::assertEquals(new DavEntry('gog-archive-0123', true, null, 'abc', null), $entries[0]);
        self::assertSame('00001', $entries[1]->name);
        self::assertFalse($entries[1]->collection);
        self::assertSame(5242880, $entries[1]->size);
        self::assertSame('e1', $entries[1]->etag);
        self::assertSame('2026-11-11T22:14:40+00:00', $entries[1]->lastModified?->format(DATE_ATOM));
    }

    public function testOtherNamespacePrefixesAndEncodedNames(): void
    {
        $entries = MultiStatus::parse(<<<'XML'
            <?xml version="1.0"?>
            <D:multistatus xmlns:D="DAV:">
              <D:response><D:href>https://cloud.example.com/remote.php/dav/files/a/Spiel%20%C3%9C%20%231.bin</D:href>
                <D:propstat><D:prop><D:getcontentlength>7</D:getcontentlength></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>
              </D:response>
            </D:multistatus>
            XML);

        self::assertSame('Spiel Ü #1.bin', $entries[0]->name);
        self::assertSame(7, $entries[0]->size);
    }

    public function testPropertiesWithAnErrorStatusDoNotCount(): void
    {
        $entries = MultiStatus::parse(<<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:"><d:response><d:href>/f</d:href>
              <d:propstat><d:prop><d:getcontentlength>99</d:getcontentlength></d:prop><d:status>HTTP/1.1 403 Forbidden</d:status></d:propstat>
            </d:response></d:multistatus>
            XML);

        self::assertNull($entries[0]->size);
    }

    public function testInvalidXmlIsAnUnexpectedAnswer(): void
    {
        $this->expectException(UnexpectedStorageResponse::class);
        MultiStatus::parse('<html>Login</html');
    }

    public function testExternalEntitiesAreNotLoaded(): void
    {
        $entries = MultiStatus::parse(<<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>
            <d:multistatus xmlns:d="DAV:"><d:response><d:href>/a&x;</d:href></d:response></d:multistatus>
            XML);

        self::assertStringNotContainsString('root:', $entries[0]->name);
    }
}
