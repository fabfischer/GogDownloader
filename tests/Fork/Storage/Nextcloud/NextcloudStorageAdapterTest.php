<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage\Nextcloud;

use App\Storage\Exception\AccessDenied;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\ObjectNotFound;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\QuotaExceeded;
use App\Storage\Exception\StorageException;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UnexpectedStorageResponse;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\Nextcloud\NextcloudSettings;
use App\Storage\Nextcloud\NextcloudStorageAdapter;
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(NextcloudStorageAdapter::class)]
final class NextcloudStorageAdapterTest extends TestCase
{
    private const BASE = 'https://cloud.example.com/nc';

    private const FILES = self::BASE . '/remote.php/dav/files/archiver';

    private const UPLOADS = self::BASE . '/remote.php/dav/uploads/archiver';

    private const SESSION_ID = 'gog-archive-0123456789abcdef0123456789abcdef';

    private const PASSWORD = 'app-password-very-secret';

    /** @var list<MockResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    private NextcloudStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->adapterFor(new NextcloudSettings(self::BASE, 'archiver', self::PASSWORD, 'GOG Archive', minPartSize: 4, maxPartSize: 100, maxParts: 50));
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->adapter->capabilities();

        self::assertSame('nextcloud', $capabilities->backend);
        self::assertTrue($capabilities->canListParts);
        self::assertTrue($capabilities->conditionalCreate);
        self::assertFalse($capabilities->contentChecksumOnStat);
        self::assertSame(86400, $capabilities->sessionIdleTimeoutSeconds);
        self::assertSame([4, 100, 50], [$capabilities->minPartSize, $capabilities->maxPartSize, $capabilities->maxParts]);
    }

    public function testBeginCreatesTheNewFolderAndTheUploadFolder(): void
    {
        $this->queue(201, 201);

        $session = $this->adapter->begin(new UploadRequest(ObjectKey::fromString('games/Spiel Ü #1/50% off.bin'), 10, 4));

        self::assertSame([
            ['MKCOL', self::FILES . '/GOG%20Archive/games/Spiel%20%C3%9C%20%231'],
            ['MKCOL', self::UPLOADS . '/' . $session->sessionId],
        ], $this->calls());
        self::assertSame(self::FILES . '/GOG%20Archive/games/Spiel%20%C3%9C%20%231/50%25%20off.bin', $this->requests[1]['headers']['destination']);
        self::assertMatchesRegularExpression('/^gog-archive-[0-9a-f]{32}$/', $session->sessionId);
        self::assertSame('nextcloud', $session->backend);
        self::assertSame(['baseUrl' => self::BASE, 'userId' => 'archiver', 'prefix' => 'GOG Archive'], $session->extra);
        self::assertSame(3, $session->partCount());
    }

    public function testBeginWalksUpToTheFirstFolderThatExists(): void
    {
        $this->queue(409, 409, 201, 201, 201, 201);

        $this->adapter->begin(new UploadRequest(ObjectKey::fromString('a/b/c/file.bin'), 4, 4));

        self::assertSame([
            ['MKCOL', self::FILES . '/GOG%20Archive/a/b/c'],
            ['MKCOL', self::FILES . '/GOG%20Archive/a/b'],
            ['MKCOL', self::FILES . '/GOG%20Archive/a'],
            ['MKCOL', self::FILES . '/GOG%20Archive/a/b'],
            ['MKCOL', self::FILES . '/GOG%20Archive/a/b/c'],
            ['MKCOL', self::UPLOADS . '/' . $this->sessionIdOfLastMkcol()],
        ], $this->calls());
    }

    public function testBeginLooksForAnExistingFileWhenTheFolderExisted(): void
    {
        $this->queue(405, [207, $this->listing(['' => null])], [207, $this->listing(['file.bin' => 4])]);

        try {
            $this->adapter->begin(new UploadRequest(ObjectKey::fromString('a/file.bin'), 4, 4));
            self::fail('Expected ObjectAlreadyExists');
        } catch (ObjectAlreadyExists) {
        }

        self::assertSame([
            ['MKCOL', self::FILES . '/GOG%20Archive/a'],
            ['PROPFIND', self::FILES . '/GOG%20Archive/a'],
            ['PROPFIND', self::FILES . '/GOG%20Archive/a/file.bin'],
        ], $this->calls(), 'No upload folder for a key that is taken');
    }

    public function testBeginRefusesAFileWhereAFolderIsNeeded(): void
    {
        $this->queue(405, [207, $this->listing(['a' => 3])]);

        $this->expectException(UnexpectedStorageResponse::class);
        $this->adapter->begin(new UploadRequest(ObjectKey::fromString('a/file.bin'), 4, 4));
    }

    public function testBeginRefusesTooManyPartsWithoutAskingTheServer(): void
    {
        try {
            $this->adapter->begin(new UploadRequest(ObjectKey::fromString('a/file.bin'), 51 * 4, 4));
            self::fail('Expected PartRejected');
        } catch (PartRejected) {
        }
        self::assertSame([], $this->requests);
    }

    public function testWritePartSendsANumberedChunkAndKeepsTheBufferOpen(): void
    {
        $this->queue(201, 201);
        $body = PartBody::fromString('pqrs');

        $first = $this->adapter->writePart($this->session(), 1, $body);
        $last = $this->adapter->writePart($this->session(), 3, PartBody::fromString('uv'));

        self::assertEquals(new UploadedPart(1, 0, 4, '00001'), $first);
        self::assertEquals(new UploadedPart(3, 8, 2, '00003'), $last);
        self::assertSame(['PUT', self::UPLOADS . '/' . self::SESSION_ID . '/00001'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(self::FILES . '/GOG%20Archive/games/x/file.bin', $this->requests[0]['headers']['destination']);
        self::assertSame('10', $this->requests[0]['headers']['oc-total-length']);
        self::assertSame('4', $this->requests[0]['headers']['content-length']);
        self::assertSame('application/octet-stream', $this->requests[0]['headers']['content-type']);
        self::assertSame('Basic ' . base64_encode('archiver:' . self::PASSWORD), $this->requests[0]['headers']['authorization']);
        self::assertSame('pqrs', $this->requests[0]['body']);
        self::assertSame([self::UPLOADS . '/' . self::SESSION_ID . '/00003', 'uv'], [$this->requests[1]['url'], $this->requests[1]['body']]);
        self::assertIsResource($body->stream, 'The engine sends the same buffer again after a temporary failure');

        $this->queue(201);
        $this->adapter->writePart($this->session(), 1, $body);
        self::assertSame('pqrs', $this->requests[2]['body'], 'A repeated part is sent from its start');
    }

    public function testAChunkReplacedWithObjectStorageAnswers200(): void
    {
        $this->queue(200);

        self::assertSame('00001', $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'))->remoteId);
    }

    public function testAChunkForAGoneUploadFolderReportsTheSessionGone(): void
    {
        $this->queue(404, 404);

        $this->expectException(UploadSessionNotFound::class);
        $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
    }

    public function testAChunkRefusedForAMissingDestinationFolderCreatesItAgain(): void
    {
        // Nextcloud checks the destination folder's quota before it accepts a chunk, so a deleted destination folder
        // also answers 404, while the upload folder is still there.
        $this->queue([404, $this->sabreError('NotFound', 'File with name /games/x could not be located')], [207, $this->listing(['' => null])], 201);

        try {
            $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertStringContainsString('created again', $e->getMessage());
        }
        self::assertSame(['MKCOL', self::FILES . '/GOG%20Archive/games/x'], [$this->requests[2]['method'], $this->requests[2]['url']]);
    }

    public function testAChunkRefusedBecauseTheServerLostItsUploadStateRestartsTheUpload(): void
    {
        $this->queue([412, $this->sabreError('PreconditionFailed', 'Missing metadata for chunked upload. The distributed cache does not hold the information of previous requests.')]);

        try {
            $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
            self::fail('Expected UploadSessionNotFound');
        } catch (UploadSessionNotFound $e) {
            self::assertStringContainsString('Missing metadata', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, class-string<StorageException>, array<string, string>}>
     */
    public static function chunkFailures(): iterable
    {
        yield '507 quota' => [507, QuotaExceeded::class, []];
        yield '413 from a proxy' => [413, PartRejected::class, []];
        yield '400' => [400, PartRejected::class, []];
        yield '401' => [401, AccessDenied::class, []];
        yield '403' => [403, AccessDenied::class, []];
        yield '423 locked' => [423, TransientStorageFailure::class, []];
        yield '429' => [429, TransientStorageFailure::class, ['Retry-After' => '7']];
        yield '502 from a proxy' => [502, TransientStorageFailure::class, []];
        yield '503 maintenance' => [503, TransientStorageFailure::class, ['Retry-After' => '7']];
        yield '504 proxy timeout' => [504, TransientStorageFailure::class, []];
        yield '302 to a login page' => [302, UnexpectedStorageResponse::class, ['Location' => 'https://cloud.example.com/login']];
        yield '418' => [418, UnexpectedStorageResponse::class, []];
    }

    /**
     * @param class-string<StorageException> $expected
     * @param array<string, string>          $headers
     */
    #[DataProvider('chunkFailures')]
    public function testChunkFailuresMapToWhatTheCallerShouldDo(int $status, string $expected, array $headers): void
    {
        $this->queue([$status, '', $headers]);

        try {
            $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
            self::fail('Expected ' . $expected);
        } catch (StorageException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertStringContainsString('HTTP ' . $status, $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            if (isset($headers['Retry-After'])) {
                self::assertInstanceOf(TransientStorageFailure::class, $e);
                self::assertSame(7, $e->retryAfterSeconds);
            }
        }
        self::assertCount(1, $this->requests, 'No retries inside the adapter, no redirects followed');
    }

    public function testErrorMessagesNameTheRequestAndTheServersReason(): void
    {
        $this->queue([507, $this->sabreError('InsufficientStorage', 'Insufficient space in /GOG Archive'), ['X-Request-Id' => 'req-42']]);

        try {
            $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
            self::fail('Expected QuotaExceeded');
        } catch (QuotaExceeded $e) {
            self::assertSame('Nextcloud PUT chunk failed (HTTP 507, request req-42) "Insufficient space in /GOG Archive": the account has no space left.', $e->getMessage());
        }
    }

    public function testABrokenConnectionWhileTheUploadFolderExistsIsTemporary(): void
    {
        $this->queue('connection reset', [207, $this->listing(['' => null])]);

        $this->expectException(TransientStorageFailure::class);
        $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
    }

    public function testABrokenConnectionToAGoneUploadFolderReportsTheSessionGone(): void
    {
        $this->queue('connection reset', 404);

        $this->expectException(UploadSessionNotFound::class);
        $this->adapter->writePart($this->session(), 1, PartBody::fromString('abcd'));
    }

    public function testPartsAreCheckedBeforeAnythingIsSent(): void
    {
        foreach ([[0, 'abcd'], [4, 'abcd'], [1, 'abc'], [3, 'abcd']] as [$number, $bytes]) {
            try {
                $this->adapter->writePart($this->session(), $number, PartBody::fromString($bytes));
                self::fail('Expected PartRejected');
            } catch (PartRejected) {
            }
        }
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{UploadSession}>
     */
    public static function foreignSessions(): iterable
    {
        $key = ObjectKey::fromString('games/x/file.bin');
        $started = new DateTimeImmutable('2026-11-11T00:00:00Z');
        $identity = ['baseUrl' => self::BASE, 'userId' => 'archiver', 'prefix' => 'GOG Archive'];

        yield 'another backend' => [new UploadSession('s3', $key, self::SESSION_ID, 10, 4, $started, $identity)];
        yield 'another server' => [new UploadSession('nextcloud', $key, self::SESSION_ID, 10, 4, $started, ['baseUrl' => 'https://other.example.com'] + $identity)];
        yield 'another folder' => [new UploadSession('nextcloud', $key, self::SESSION_ID, 10, 4, $started, ['prefix' => 'Other'] + $identity)];
        yield 'an upload folder name that leaves the uploads area' => [new UploadSession('nextcloud', $key, '../../files/archiver/Photos', 10, 4, $started, $identity)];
    }

    #[DataProvider('foreignSessions')]
    public function testForeignSessionsAreNeverTouched(UploadSession $session): void
    {
        foreach ([
            fn () => $this->adapter->writePart($session, 1, PartBody::fromString('abcd')),
            fn () => $this->adapter->listCompletedParts($session),
            fn () => $this->adapter->complete($session, []),
        ] as $call) {
            try {
                $call();
                self::fail('Expected UploadSessionNotFound');
            } catch (UploadSessionNotFound) {
            }
        }

        try {
            $this->adapter->abort($session);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $this->requests);
    }

    public function testListingKeepsOnlyChunksWithTheirExpectedSize(): void
    {
        // With object storage, Nextcloud lists ".target" and part numbers without leading zeros.
        $this->queue([207, $this->listing(['' => null, '.file' => 10, '.target' => 10, '00001' => 4, '2' => 4, '00003' => 9, 'notes.txt' => 4, '00004' => 4])]);

        $parts = $this->adapter->listCompletedParts($this->session());

        self::assertEquals([new UploadedPart(1, 0, 4, '00001'), new UploadedPart(2, 4, 4, '00002')], $parts);
        self::assertSame(['PROPFIND', '1'], [$this->requests[0]['method'], $this->requests[0]['headers']['depth']]);
    }

    public function testListingAGoneUploadFolderReportsTheSessionGone(): void
    {
        $this->queue(404);

        $this->expectException(UploadSessionNotFound::class);
        $this->adapter->listCompletedParts($this->session());
    }

    public function testCompleteChecksTheChunksAndMovesWithoutOverwriting(): void
    {
        $this->queue([207, $this->listing(['' => null, '.file' => 10, '00001' => 4, '00002' => 4, '00003' => 2])], [201, '', ['ETag' => '"f00"']]);

        $stored = $this->adapter->complete($this->session(), $this->parts());

        self::assertSame(['MOVE', self::UPLOADS . '/' . self::SESSION_ID . '/.file'], [$this->requests[1]['method'], $this->requests[1]['url']]);
        self::assertSame(self::FILES . '/GOG%20Archive/games/x/file.bin', $this->requests[1]['headers']['destination']);
        self::assertSame('F', $this->requests[1]['headers']['overwrite']);
        self::assertSame('10', $this->requests[1]['headers']['oc-total-length']);
        self::assertSame(10, $stored->size);
        self::assertSame('f00', $stored->remoteETag);
    }

    /**
     * @return iterable<string, array{array<string, int|null>}>
     */
    public static function wrongChunkSets(): iterable
    {
        yield 'a chunk missing' => [['00001' => 4, '00003' => 2]];
        yield 'a chunk with the wrong size' => [['00001' => 4, '00002' => 3, '00003' => 2]];
        yield 'a chunk beyond the last part' => [['00001' => 4, '00002' => 4, '00003' => 2, '00004' => 4]];
    }

    /**
     * With object storage Nextcloud assembles whatever chunks are there, without checking the total size.
     *
     * @param array<string, int|null> $chunks
     */
    #[DataProvider('wrongChunkSets')]
    public function testCompleteRefusesAnythingButExactlyTheExpectedChunks(array $chunks): void
    {
        $this->queue([207, $this->listing(['' => null] + $chunks)]);

        $this->expectException(IncompleteUpload::class);

        try {
            $this->adapter->complete($this->session(), $this->parts());
        } finally {
            self::assertCount(1, $this->requests, 'No MOVE');
        }
    }

    public function testCompleteRefusesAWrongPartListWithoutAskingTheServer(): void
    {
        $parts = $this->parts();
        foreach ([array_slice($parts, 0, 2), [$parts[1], $parts[0], $parts[2]], [...$parts, $parts[2]]] as $wrong) {
            try {
                $this->adapter->complete($this->session(), $wrong);
                self::fail('Expected IncompleteUpload');
            } catch (IncompleteUpload) {
            }
        }
        self::assertSame([], $this->requests);
    }

    public function testARepeatedCompleteAfterALostAnswerReturnsTheStoredFile(): void
    {
        // The first MOVE succeeded but its answer got lost: the upload folder is gone, the file is there.
        $this->queue(404, [207, $this->listing(['file.bin' => 10], etag: 'e9')]);

        $stored = $this->adapter->complete($this->session(), $this->parts());

        self::assertSame(10, $stored->size);
        self::assertSame('e9', $stored->remoteETag);
    }

    public function testCompleteForAGoneUploadWithoutTheFileReportsTheSessionGone(): void
    {
        $this->queue(404, 404);

        $this->expectException(UploadSessionNotFound::class);
        $this->adapter->complete($this->session(), $this->parts());
    }

    public function testAnExistingFileIsNeverReplaced(): void
    {
        $this->queue($this->fullListing(), [412, $this->sabreError('PreconditionFailed', 'The destination node already exists, and the overwrite header is set to false')], [207, $this->listing(['' => null])]);

        $this->expectException(ObjectAlreadyExists::class);
        $this->adapter->complete($this->session(), $this->parts());
    }

    public function testA412ForThisUploadsOwnEarlierMoveReturnsTheStoredFile(): void
    {
        // A MOVE repeated after a lost answer meets the file it created: "Overwrite: F" refuses, the upload folder is
        // gone and the file has the expected size.
        $this->queue($this->fullListing(), 412, 404, [207, $this->listing(['file.bin' => 10])]);

        self::assertSame(10, $this->adapter->complete($this->session(), $this->parts())->size);
    }

    public function testA412WithAFileOfAnotherSizeIsAnExistingObject(): void
    {
        $this->queue($this->fullListing(), 412, 404, [207, $this->listing(['file.bin' => 99])]);

        $this->expectException(ObjectAlreadyExists::class);
        $this->adapter->complete($this->session(), $this->parts());
    }

    public function testAMissingDestinationFolderIsCreatedAgainBeforeTheSecondMove(): void
    {
        $this->queue($this->fullListing(), 409, 201, 201);

        $this->adapter->complete($this->session(), $this->parts());

        self::assertSame(['PROPFIND', 'MOVE', 'MKCOL', 'MOVE'], array_column($this->requests, 'method'));
    }

    /**
     * @return iterable<string, array{int|string, class-string<StorageException>}>
     */
    public static function moveFailures(): iterable
    {
        yield '400: sizes do not add up' => [400, IncompleteUpload::class];
        yield '423: still assembling' => [423, TransientStorageFailure::class];
        yield '507: no space for the file' => [507, QuotaExceeded::class];
        yield '504: proxy gave up waiting' => [504, TransientStorageFailure::class];
        yield 'timeout' => ['idle timeout reached', TransientStorageFailure::class];
    }

    /**
     * @param class-string<StorageException> $expected
     */
    #[DataProvider('moveFailures')]
    public function testMoveFailures(int|string $answer, string $expected): void
    {
        $this->queue($this->fullListing(), $answer);

        try {
            $this->adapter->complete($this->session(), $this->parts());
            self::fail('Expected ' . $expected);
        } catch (StorageException $e) {
            self::assertInstanceOf($expected, $e);
            if (is_string($answer)) {
                self::assertStringContainsString('completing again later finds it', $e->getMessage());
            }
        }
    }

    public function testAbortDeletesOnlyTheUploadFolder(): void
    {
        $this->queue(204, 404);

        $this->adapter->abort($this->session());
        $this->adapter->abort($this->session());

        self::assertSame([['DELETE', self::UPLOADS . '/' . self::SESSION_ID], ['DELETE', self::UPLOADS . '/' . self::SESSION_ID]], $this->calls());
    }

    public function testAbortReportsRealFailures(): void
    {
        $this->queue(401);

        $this->expectException(AccessDenied::class);
        $this->adapter->abort($this->session());
    }

    public function testStat(): void
    {
        $this->queue([207, $this->listing(['file.bin' => 10], etag: 'e1')], 404);

        $info = $this->adapter->stat(ObjectKey::fromString('games/x/file.bin'));

        self::assertSame(10, $info?->size);
        self::assertSame('e1', $info->remoteETag);
        self::assertSame('2026-11-11', $info->lastModified?->format('Y-m-d'));
        self::assertNull($info->contentChecksum);
        self::assertSame(['PROPFIND', '0'], [$this->requests[0]['method'], $this->requests[0]['headers']['depth']]);
        self::assertNull($this->adapter->stat(ObjectKey::fromString('games/x/other.bin')));
    }

    public function testStatOfAFolderIsUnexpected(): void
    {
        $this->queue([207, $this->listing(['file.bin' => null])]);

        $this->expectException(UnexpectedStorageResponse::class);
        $this->adapter->stat(ObjectKey::fromString('games/x/file.bin'));
    }

    public function testOpenReadYieldsExactChunks(): void
    {
        $this->queue([200, ['abcde', 'fghij', 'k'], ['Content-Length' => '11']]);

        $chunks = iterator_to_array($this->adapter->openRead(ObjectKey::fromString('games/x/file.bin'), 4), false);

        self::assertSame(['abcd', 'efgh', 'ijk'], $chunks);
        self::assertSame(['GET', self::FILES . '/GOG%20Archive/games/x/file.bin'], [$this->requests[0]['method'], $this->requests[0]['url']]);
    }

    public function testOpenReadOfAMissingFile(): void
    {
        $this->queue(404);

        $this->expectException(ObjectNotFound::class);
        $this->adapter->openRead(ObjectKey::fromString('games/x/file.bin'));
    }

    public function testAShortBodyIsATemporaryFailure(): void
    {
        $this->queue([200, ['abc'], ['Content-Length' => '5']]);

        $this->expectException(TransientStorageFailure::class);
        iterator_to_array($this->adapter->openRead(ObjectKey::fromString('games/x/file.bin'), 2), false);
    }

    public function testDeleteRemovesOneFile(): void
    {
        $this->queue([207, $this->listing(['file.bin' => 10])], 204, 404);

        $this->adapter->delete(ObjectKey::fromString('games/x/file.bin'));
        $this->adapter->delete(ObjectKey::fromString('games/x/gone.bin'));

        self::assertSame(['PROPFIND', 'DELETE', 'PROPFIND'], array_column($this->requests, 'method'));
    }

    public function testDeleteRefusesAFolder(): void
    {
        $this->queue([207, $this->listing(['file.bin' => null])]);

        try {
            $this->adapter->delete(ObjectKey::fromString('games/x/file.bin'));
            self::fail('Expected UnexpectedStorageResponse');
        } catch (UnexpectedStorageResponse) {
        }
        self::assertSame(['PROPFIND'], array_column($this->requests, 'method'), 'A folder is never deleted');
    }

    private function adapterFor(NextcloudSettings $settings): NextcloudStorageAdapter
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['normalized_headers'] as $name => $values) {
                $headers[$name] = substr($values[0], strlen($name) + 2);
            }
            $body = $options['body'] ?? '';
            if (is_resource($body)) {
                $body = (string) stream_get_contents($body);
            } elseif (!is_string($body)) {
                $body = '';
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

            return array_shift($this->queue) ?? self::fail(sprintf('Unexpected request: %s %s', $method, $url));
        });

        return NextcloudStorageAdapter::create($settings, $client, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-11T12:00:00Z'));
    }

    /**
     * Queues answers: a status, [status, body, headers], or a string for a connection failure.
     *
     * @param int|string|array{0: int, 1?: string|list<string>, 2?: array<string, string>} ...$answers
     */
    private function queue(int|string|array ...$answers): void
    {
        foreach ($answers as $answer) {
            if (is_string($answer)) {
                $this->queue[] = new MockResponse('', ['error' => $answer]);

                continue;
            }
            [$status, $body, $headers] = is_int($answer) ? [$answer, '', []] : $answer + [1 => '', 2 => []];
            $this->queue[] = new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private function calls(): array
    {
        return array_map(static fn (array $request): array => [$request['method'], $request['url']], $this->requests);
    }

    private function sessionIdOfLastMkcol(): string
    {
        return basename($this->requests[count($this->requests) - 1]['url']);
    }

    private function session(): UploadSession
    {
        return new UploadSession('nextcloud', ObjectKey::fromString('games/x/file.bin'), self::SESSION_ID, 10, 4, new DateTimeImmutable('2026-11-11T00:00:00Z'), ['baseUrl' => self::BASE, 'userId' => 'archiver', 'prefix' => 'GOG Archive']);
    }

    /**
     * @return list<UploadedPart>
     */
    private function parts(): array
    {
        return [new UploadedPart(1, 0, 4, '00001'), new UploadedPart(2, 4, 4, '00002'), new UploadedPart(3, 8, 2, '00003')];
    }

    /**
     * @return array{int, string}
     */
    private function fullListing(): array
    {
        return [207, $this->listing(['' => null, '.file' => 10, '00001' => 4, '00002' => 4, '00003' => 2])];
    }

    /**
     * A PROPFIND answer; a null size marks a folder, the name '' the listed folder itself.
     *
     * @param array<int|string, int|null> $entries numeric names like "2" become integer keys
     */
    private function listing(array $entries, string $etag = 'e1'): string
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
        foreach ($entries as $name => $size) {
            $type = $size === null ? '<d:collection/>' : '';
            $length = $size === null ? '' : "<d:getcontentlength>{$size}</d:getcontentlength>";
            $xml .= '<d:response><d:href>/nc/remote.php/dav/x/' . rawurlencode((string) $name) . '</d:href><d:propstat><d:prop>'
                . "<d:resourcetype>{$type}</d:resourcetype>{$length}<d:getetag>\"{$etag}\"</d:getetag><d:getlastmodified>Wed, 11 Nov 2026 10:00:00 GMT</d:getlastmodified>"
                . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }

        return $xml . '</d:multistatus>';
    }

    private function sabreError(string $exception, string $message): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">'
            . "<s:exception>Sabre\\DAV\\Exception\\{$exception}</s:exception><s:message>{$message}</s:message></d:error>";
    }
}
