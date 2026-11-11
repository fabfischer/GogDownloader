<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Tests\Support\RecordingHttpClient;
use App\Transfer\Exception\RangeNotSupported;
use App\Transfer\Exception\SourceSizeUnknown;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\HostAllowlist;
use App\Transfer\Source\SequentialSource;
use App\Transfer\SourceFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SequentialSource::class)]
final class SequentialSourceTest extends TestCase
{
    public function testPartsAreCutFromOneStreamAcrossChunkBoundaries(): void
    {
        $bytes = implode('', range('a', 'y'));
        $source = new SequentialSource(static fn (): array => [new SourceFingerprint(25), str_split($bytes, 7)]);

        $fingerprint = $source->open();
        $parts = [];
        foreach ([[0, 10], [10, 10], [20, 5]] as [$offset, $length]) {
            $parts[] = implode('', iterator_to_array($source->readPart($offset, $length, $fingerprint), false));
        }

        self::assertFalse($source->isResumable());
        self::assertSame([substr($bytes, 0, 10), substr($bytes, 10, 10), substr($bytes, 20, 5)], $parts);
    }

    public function testPartsMustBeReadInOrder(): void
    {
        $source = new SequentialSource(static fn (): array => [new SourceFingerprint(25), [str_repeat('x', 25)]]);
        $fingerprint = $source->open();

        $this->expectException(RangeNotSupported::class);
        iterator_to_array($source->readPart(10, 10, $fingerprint));
    }

    public function testAStreamThatEndsEarlyIsATransientFailure(): void
    {
        $source = new SequentialSource(static fn (): array => [new SourceFingerprint(25), [str_repeat('x', 12)]]);
        $fingerprint = $source->open();
        iterator_to_array($source->readPart(0, 10, $fingerprint));

        $this->expectException(TransientSourceFailure::class);
        iterator_to_array($source->readPart(10, 10, $fingerprint));
    }

    public function testHttpReadsAPlainGetWithoutBufferingOrToken(): void
    {
        $client = new RecordingHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(str_repeat('z', 30), [
            'http_code' => 200,
            'response_headers' => ['Content-Length' => '30', 'ETag' => '"s1"'],
        ])));
        $source = SequentialSource::http($client, static fn (): string => 'https://cdn.gog.test/f.bin', new HostAllowlist(['*.gog.test']));

        $fingerprint = $source->open();
        $data = implode('', iterator_to_array($source->readPart(0, 30, $fingerprint), false));

        self::assertEquals(new SourceFingerprint(30, '"s1"'), $fingerprint);
        self::assertSame(str_repeat('z', 30), $data);
        self::assertFalse($client->requests[0]['options']['buffer']);
        self::assertArrayNotHasKey('auth_bearer', $client->requests[0]['options']);
    }

    public function testHttpWithoutContentLengthHasAnUnknownSize(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('data', ['http_code' => 200]));
        $source = SequentialSource::http($client, static fn (): string => 'https://cdn.gog.test/f.bin', new HostAllowlist(['*.gog.test']));

        $this->expectException(SourceSizeUnknown::class);
        $source->open();
    }
}
