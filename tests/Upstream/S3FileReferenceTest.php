<?php

declare(strict_types=1);

namespace App\Tests\Upstream;

use App\DTO\FileWriter\S3FileReference;
use App\Enum\S3StorageClass;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of upstream's S3 multipart writer (src/DTO/FileWriter/S3FileReference.php).
 *
 * Pins why it cannot back resumable archiving (docs/fork/findings.md): the UploadId lives only in the object and is
 * aborted in __destruct, so an upload cannot be resumed by another process.
 */
#[CoversClass(S3FileReference::class)]
final class S3FileReferenceTest extends TestCase
{
    /** @var list<array{name: string, args: array<string, mixed>}> */
    private array $calls = [];

    public function testSmallWritesAreBufferedWithoutAnyRequest(): void
    {
        [$client] = $this->client();
        $file = new S3FileReference('archive-bucket', 'games/test.bin', S3StorageClass::Standard);

        $file->writeChunk($client, str_repeat('x', 4), 10);
        $file->writeChunk($client, str_repeat('x', 5), 10);

        self::assertSame([], $this->calls);
    }

    public function testFullChunksBecomeNumberedPartsAndFinalizeCompletesAndTags(): void
    {
        [$client] = $this->client();
        $file = new S3FileReference('archive-bucket', 'games/test.bin', S3StorageClass::Standard);

        $file->writeChunk($client, str_repeat('a', 6), 10);
        $file->writeChunk($client, str_repeat('b', 6), 10); // 12 buffered bytes → part 1 (10), 2 kept
        $file->writeChunk($client, str_repeat('c', 9), 10); // 11 → part 2 (10), 1 kept
        $file->finalize('0123456789abcdef0123456789abcdef');

        self::assertSame([
            'CreateMultipartUpload',
            'UploadPart',
            'UploadPart',
            'UploadPart',
            'CompleteMultipartUpload',
            'HeadObject',
            'PutObjectTagging',
        ], array_column($this->calls, 'name'));

        $parts = array_values(array_filter($this->calls, static fn (array $c): bool => $c['name'] === 'UploadPart'));
        self::assertSame([1, 2, 3], array_map(static fn (array $c): int => $c['args']['PartNumber'], $parts));
        // UPSTREAM BUG (pinned on purpose): finalize() passes the leftover buffer to writeChunk(), which prepends
        // the buffer again, so the last part carries the remaining bytes twice. 21 bytes written, 22 uploaded.
        // The MD5 tag is computed from the stream, so later runs treat the corrupt object as valid.
        // See docs/fork/findings.md (F1).
        $bodies = array_map(static fn (array $c): string => (string) $c['args']['Body'], $parts);
        self::assertSame([str_repeat('a', 6) . str_repeat('b', 4), str_repeat('b', 2) . str_repeat('c', 8), 'cc'], $bodies);
        self::assertSame(22, strlen(implode('', $bodies)));

        self::assertSame('STANDARD', $this->calls[0]['args']['StorageClass']);
        self::assertSame(
            [['PartNumber' => 1, 'ETag' => '"etag-1"'], ['PartNumber' => 2, 'ETag' => '"etag-2"'], ['PartNumber' => 3, 'ETag' => '"etag-3"']],
            $this->calls[4]['args']['MultipartUpload']['Parts'],
        );
        // The MD5 of the whole file is stored as an object tag, not verified by S3.
        self::assertSame(
            [['Key' => 'md5_hash', 'Value' => '0123456789abcdef0123456789abcdef']],
            $this->calls[6]['args']['Tagging']['TagSet'],
        );
    }

    public function testAnOpenUploadIsAbortedWhenTheObjectIsDestroyed(): void
    {
        [$client] = $this->client();
        $file = new S3FileReference('archive-bucket', 'games/test.bin', S3StorageClass::Standard);
        $file->writeChunk($client, str_repeat('a', 10), 10);

        unset($file);

        self::assertSame(['CreateMultipartUpload', 'UploadPart', 'AbortMultipartUpload'], array_column($this->calls, 'name'));
        self::assertSame('upload-1', $this->calls[2]['args']['UploadId']);
    }

    /**
     * @return array{S3Client}
     */
    private function client(): array
    {
        $this->calls = [];
        $partNumber = 0;
        $handler = new MockHandler();
        $record = function (CommandInterface $command) use (&$partNumber): Result {
            $this->calls[] = ['name' => $command->getName(), 'args' => $command->toArray()];

            return match ($command->getName()) {
                'CreateMultipartUpload' => new Result(['UploadId' => 'upload-1']),
                'UploadPart' => new Result(['ETag' => '"etag-' . ++$partNumber . '"']),
                default => new Result([]),
            };
        };
        for ($i = 0; $i < 20; ++$i) {
            $handler->append($record);
        }

        return [new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ])];
    }
}
