<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage;

use App\Storage\ObjectKey;
use App\Storage\UploadSession;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadSession::class)]
final class UploadSessionTest extends TestCase
{
    public function testPartLayoutFollowsSizeAndPartSize(): void
    {
        $session = $this->session(totalSize: 25, partSize: 10);

        self::assertSame(3, $session->partCount());
        self::assertSame([0, 10, 20], [$session->offsetOf(1), $session->offsetOf(2), $session->offsetOf(3)]);
        self::assertSame([10, 10, 5], [$session->lengthOf(1), $session->lengthOf(2), $session->lengthOf(3)]);
    }

    public function testItSurvivesAJsonRoundTrip(): void
    {
        $session = $this->session(totalSize: 25, partSize: 10, extra: ['uploadDir' => 'web-file-upload-abc']);

        $restored = UploadSession::fromArray(json_decode((string) json_encode($session->toArray()), true));

        self::assertEquals($session, $restored);
    }

    public function testAnUnknownFormatIsRejected(): void
    {
        $data = $this->session(totalSize: 1, partSize: 1)->toArray();
        $data['format'] = 99;

        $this->expectException(InvalidArgumentException::class);
        UploadSession::fromArray($data);
    }

    /**
     * @param array<string, string> $extra
     */
    private function session(int $totalSize, int $partSize, array $extra = []): UploadSession
    {
        return new UploadSession('memory', ObjectKey::fromString('objects/test.bin'), 'session-1', $totalSize, $partSize, new DateTimeImmutable('2026-11-11T12:00:00+00:00'), $extra);
    }
}
