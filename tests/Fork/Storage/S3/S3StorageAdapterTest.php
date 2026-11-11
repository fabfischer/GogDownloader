<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage\S3;

use App\Storage\Exception\AccessDenied;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\InvalidObjectKey;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\QuotaExceeded;
use App\Storage\Exception\StorageException;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UnexpectedStorageResponse;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\S3\S3ChecksumMode;
use App\Storage\S3\S3Settings;
use App\Storage\S3\S3StorageAdapter;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Closure;
use DateTimeImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The S3 adapter's requests and error handling, against the SDK's mock handler: the SDK builds, checksums and signs
 * every request as for a real server, only the network is replaced. Behaviour against a real S3-compatible server is
 * covered by tests/Integration/S3.
 */
#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterTest extends TestCase
{
    private const MIB = 1048576;

    private MockHandler $mock;

    /** @var list<array{command: CommandInterface, request: RequestInterface}> */
    private array $calls = [];

    public function testBeginOpensAnUploadUnderThePrefixAndRemembersTheTarget(): void
    {
        $adapter = $this->adapter();
        $this->queue('HeadObject', self::error('NotFound', 404));
        $this->queue('CreateMultipartUpload', new Result(['UploadId' => 'upload-1']));

        $session = $adapter->begin(new UploadRequest(ObjectKey::fromString('objects/a.bin'), 12 * self::MIB, 5 * self::MIB, 'application/octet-stream'));

        $create = $this->calls[1]['command'];
        self::assertSame('archive/objects/a.bin', $create['Key']);
        self::assertSame('bucket', $create['Bucket']);
        self::assertSame('application/octet-stream', $create['ContentType']);
        self::assertArrayNotHasKey('ChecksumAlgorithm', $create->toArray());
        self::assertArrayNotHasKey('StorageClass', $create->toArray());
        self::assertSame('upload-1', $session->sessionId);
        self::assertSame(['endpoint' => 'https://s3.example.test', 'bucket' => 'bucket', 'prefix' => 'archive', 'checksums' => 'off'], $session->extra);
    }

    public function testBeginRefusesAKeyThatHoldsAnObject(): void
    {
        $adapter = $this->adapter();
        $this->queue('HeadObject', new Result(['ContentLength' => 4]));

        $this->expectException(ObjectAlreadyExists::class);
        $adapter->begin(new UploadRequest(ObjectKey::fromString('a.bin'), 4, 4));
    }

    public function testBeginRejectsAPlanOutsideThePartLimitsWithoutAnyRequest(): void
    {
        $adapter = $this->adapter();

        $this->expectException(PartRejected::class);
        $adapter->begin(new UploadRequest(ObjectKey::fromString('a.bin'), 3 * self::MIB, self::MIB));
    }

    public function testAKeyTooLongWithThePrefixIsRefused(): void
    {
        $adapter = $this->adapter(prefix: str_repeat('p', 200));
        $key = ObjectKey::fromString(implode('/', array_fill(0, 4, str_repeat('k', 210))));

        $this->expectException(InvalidObjectKey::class);
        $adapter->begin(new UploadRequest($key, 4, 4));
    }

    public function testStorageClassIsSentOnlyWhenConfigured(): void
    {
        $adapter = $this->adapter(storageClass: 'STANDARD_IA');
        $this->queue('HeadObject', self::error('NotFound', 404));
        $this->queue('CreateMultipartUpload', new Result(['UploadId' => 'upload-1']));

        $adapter->begin(new UploadRequest(ObjectKey::fromString('a.bin'), 4, 4));

        self::assertSame('STANDARD_IA', $this->calls[1]['command']['StorageClass']);
    }

    public function testChecksumModeDeclaresSendsAndRepeatsTheCrc32(): void
    {
        $adapter = $this->adapter(checksums: S3ChecksumMode::Crc32, conditionalCreate: true);
        $this->queue('HeadObject', self::error('NotFound', 404));
        $this->queue('CreateMultipartUpload', new Result(['UploadId' => 'upload-1']));
        $session = $adapter->begin(new UploadRequest(ObjectKey::fromString('a.bin'), 6, 6));
        $crc = base64_encode(hash('crc32b', 'abcdef', true));
        $this->queue('UploadPart', new Result(['ETag' => '"etag-1"', 'ChecksumCRC32' => $crc]));
        $this->queue('CompleteMultipartUpload', new Result(['ETag' => '"final-1"']));

        $part = $adapter->writePart($session, 1, PartBody::fromString('abcdef'));
        $adapter->complete($session, [$part]);

        self::assertSame('CRC32', $this->calls[1]['command']['ChecksumAlgorithm']);
        self::assertSame($crc, $this->calls[2]['request']->getHeaderLine('x-amz-checksum-crc32'));
        self::assertSame('crc32:' . $crc, $part->checksum);
        self::assertSame([['PartNumber' => 1, 'ETag' => '"etag-1"', 'ChecksumCRC32' => $crc]], $this->calls[3]['command']['MultipartUpload']['Parts']);
        self::assertSame('*', $this->calls[3]['command']['IfNoneMatch']);
    }

    public function testOffModeSendsNoChecksumHeaders(): void
    {
        $adapter = $this->adapter();
        $session = $this->session();
        $this->queue('UploadPart', new Result(['ETag' => '"etag-1"']));

        $part = $adapter->writePart($session, 1, PartBody::fromString(str_repeat('a', 5 * self::MIB)));

        $headers = array_map(strtolower(...), array_keys($this->calls[0]['request']->getHeaders()));
        self::assertSame([], array_values(array_filter($headers, static fn (string $name): bool => str_starts_with($name, 'x-amz-checksum') || str_starts_with($name, 'x-amz-sdk-checksum'))));
        self::assertSame(hash('sha256', str_repeat('a', 5 * self::MIB)), $this->calls[0]['request']->getHeaderLine('x-amz-content-sha256'));
        self::assertSame('etag-1', $part->remoteId);
        self::assertNull($part->checksum);
        // No "Expect: 100-continue", which costs a one-second wait per part on servers that never answer it.
        self::assertFalse($this->calls[0]['command']['@http']['expect']);
    }

    public function testThePartBufferStaysOpenSoTheSamePartCanBeSentAgain(): void
    {
        $adapter = $this->adapter();
        $session = $this->session();
        $body = PartBody::fromString(str_repeat('b', 5 * self::MIB));
        // If the SDK retried on its own, it would use the second answer and the first call would not fail.
        $this->queue('UploadPart', self::error('SlowDown', 503, ['Retry-After' => '2']));
        $this->queue('UploadPart', new Result(['ETag' => '"etag-2"']));

        try {
            $adapter->writePart($session, 1, $body);
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertSame(2, $e->retryAfterSeconds);
        }
        self::assertIsResource($body->stream);
        $part = $adapter->writePart($session, 1, $body);

        self::assertSame('etag-2', $part->remoteId);
        self::assertIsResource($body->stream);
        self::assertSame(5 * self::MIB, strlen($body->contents()));
    }

    public function testThePartStreamSurvivesTheSdkLettingGoOfTheRequest(): void
    {
        // A handler that keeps no reference to the request, like the real HTTP handler: the SDK's stream wrapper is
        // freed right after the call, and a wrapper that still owned the resource would close it.
        $settings = new S3Settings('bucket', 'us-east-1', 'access', 'secret', endpoint: 'https://s3.example.test', prefix: 'archive');
        $adapter = S3StorageAdapter::create($settings, ['handler' => static fn (): PromiseInterface => Create::promiseFor(new Result(['ETag' => '"e1"']))]);
        $body = PartBody::fromString(str_repeat('s', 5 * self::MIB));

        $adapter->writePart($this->session(), 1, $body);
        gc_collect_cycles();

        self::assertIsResource($body->stream);
        self::assertTrue(fclose($body->stream));
    }

    public function testAPartDroppedWithoutAnswerIsExplainedByAListing(): void
    {
        $adapter = $this->adapter();
        // The server refused the part early and closed the connection while the body was still being sent.
        $this->queue('UploadPart', self::connectionReset());
        $this->queue('ListParts', self::error('NoSuchUpload', 404));

        $this->expectException(UploadSessionNotFound::class);
        $adapter->writePart($this->session(), 1, PartBody::fromString(str_repeat('a', 5 * self::MIB)));
    }

    public function testAPartDroppedWithoutAnswerStaysTemporaryWhenTheUploadIsFine(): void
    {
        $adapter = $this->adapter();
        $this->queue('UploadPart', self::connectionReset());
        $this->queue('ListParts', new Result(['Parts' => [], 'IsTruncated' => false]));

        try {
            $adapter->writePart($this->session(), 1, PartBody::fromString(str_repeat('a', 5 * self::MIB)));
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertStringContainsString('connection failed', $e->getMessage());
        }
        self::assertSame(1, $this->calls[1]['command']['MaxParts']);
    }

    public function testAPartOfTheWrongSizeIsRejectedBeforeSending(): void
    {
        $adapter = $this->adapter();

        $this->expectException(PartRejected::class);
        $adapter->writePart($this->session(), 1, PartBody::fromString('short'));
    }

    public function testListingPagesThroughEveryPage(): void
    {
        $adapter = $this->adapter();
        $session = $this->session(totalSize: 12 * self::MIB);
        $this->queue('ListParts', new Result(['Parts' => [['PartNumber' => 2, 'ETag' => '"e2"', 'Size' => 5 * self::MIB], ['PartNumber' => 1, 'ETag' => '"e1"', 'Size' => 5 * self::MIB]], 'IsTruncated' => true, 'NextPartNumberMarker' => 2]));
        $this->queue('ListParts', new Result(['Parts' => [['PartNumber' => 3, 'ETag' => '"e3"', 'Size' => 2 * self::MIB]], 'IsTruncated' => false]));

        $parts = $adapter->listCompletedParts($session);

        self::assertSame([1, 2, 3], array_map(static fn (UploadedPart $part): int => $part->number, $parts));
        self::assertSame([0, 5 * self::MIB, 10 * self::MIB], array_map(static fn (UploadedPart $part): int => $part->offset, $parts));
        self::assertSame(['e1', 'e2', 'e3'], array_map(static fn (UploadedPart $part): string => $part->remoteId, $parts));
        self::assertArrayNotHasKey('PartNumberMarker', $this->calls[0]['command']->toArray());
        self::assertSame(2, $this->calls[1]['command']['PartNumberMarker']);
    }

    public function testAListingThatNeverEndsIsRefused(): void
    {
        $adapter = $this->adapter();
        $this->queue('ListParts', new Result(['Parts' => [], 'IsTruncated' => true]));

        $this->expectException(UnexpectedStorageResponse::class);
        $adapter->listCompletedParts($this->session());
    }

    public function testCompleteChecksThePartListBeforeContactingTheServer(): void
    {
        $adapter = $this->adapter();
        $session = $this->session(totalSize: 10 * self::MIB);
        $first = new UploadedPart(1, 0, 5 * self::MIB, 'e1');

        foreach ([[$first], [$first, $first], [$first, new UploadedPart(2, 5 * self::MIB, 4 * self::MIB, 'e2')]] as $parts) {
            try {
                $adapter->complete($session, $parts);
                self::fail('Expected IncompleteUpload');
            } catch (IncompleteUpload) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testCompleteWithoutConditionalCreateLooksAtTheKeyFirst(): void
    {
        $adapter = $this->adapter();
        $session = $this->session();
        $this->queue('HeadObject', self::error('NotFound', 404));
        $this->queue('CompleteMultipartUpload', new Result(['ETag' => '"final-1"']));

        $stored = $adapter->complete($session, [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);

        self::assertArrayNotHasKey('IfNoneMatch', $this->calls[1]['command']->toArray());
        self::assertSame([['PartNumber' => 1, 'ETag' => '"e1"']], $this->calls[1]['command']['MultipartUpload']['Parts']);
        self::assertSame('final-1', $stored->remoteETag);
        self::assertSame(5 * self::MIB, $stored->size);
    }

    public function testARepeatedCompleteAfterALostResponseReturnsTheStoredObject(): void
    {
        $adapter = $this->adapter();
        $session = $this->session();
        $this->queue('HeadObject', self::error('NotFound', 404));
        $this->queue('CompleteMultipartUpload', self::error('NoSuchUpload', 404));
        $this->queue('HeadObject', new Result(['ContentLength' => 5 * self::MIB, 'ETag' => '"final-1"', 'LastModified' => new DateTimeResult('2026-11-11T12:00:00Z')]));

        $stored = $adapter->complete($session, [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);

        self::assertSame('final-1', $stored->remoteETag);
        self::assertEquals(new DateTimeImmutable('2026-11-11T12:00:00Z'), $stored->completedAt);
    }

    public function testAnUploadThatIsGoneWithoutAnObjectStaysNotFound(): void
    {
        $adapter = $this->adapter();
        $this->queue('HeadObject', self::error('NotFound', 404));
        $this->queue('CompleteMultipartUpload', self::error('NoSuchUpload', 404));
        $this->queue('HeadObject', self::error('NotFound', 404));

        $this->expectException(UploadSessionNotFound::class);
        $adapter->complete($this->session(), [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);
    }

    public function testAPreconditionFailureWhileTheUploadStillExistsMeansAnotherObject(): void
    {
        $adapter = $this->adapter(conditionalCreate: true);
        $this->queue('CompleteMultipartUpload', self::error('PreconditionFailed', 412));
        $this->queue('ListParts', new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"', 'Size' => 5 * self::MIB]], 'IsTruncated' => false]));

        $this->expectException(ObjectAlreadyExists::class);
        $adapter->complete($this->session(), [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);
    }

    public function testAPreconditionFailureAfterTheUploadFinishedIsALostResponse(): void
    {
        $adapter = $this->adapter(conditionalCreate: true);
        $this->queue('CompleteMultipartUpload', self::error('PreconditionFailed', 412));
        $this->queue('ListParts', self::error('NoSuchUpload', 404));
        $this->queue('HeadObject', new Result(['ContentLength' => 5 * self::MIB, 'ETag' => '"final-1"']));

        $stored = $adapter->complete($this->session(), [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);

        self::assertSame(5 * self::MIB, $stored->size);
    }

    public function testCompletingWithPartsTheServerDoesNotHoldIsIncomplete(): void
    {
        $adapter = $this->adapter(conditionalCreate: true);
        $this->queue('CompleteMultipartUpload', self::error('InvalidPart', 400));

        $this->expectException(IncompleteUpload::class);
        $adapter->complete($this->session(), [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);
    }

    /**
     * @return iterable<string, array{Closure(CommandInterface): S3Exception, class-string<StorageException>}>
     */
    public static function errors(): iterable
    {
        yield 'access denied' => [self::error('AccessDenied', 403), AccessDenied::class];
        yield 'wrong secret' => [self::error('SignatureDoesNotMatch', 403), AccessDenied::class];
        yield 'expired session token' => [self::error('ExpiredToken', 400), AccessDenied::class];
        yield 'missing bucket' => [self::error('NoSuchBucket', 404), AccessDenied::class];
        yield 'unknown upload' => [self::error('NoSuchUpload', 404), UploadSessionNotFound::class];
        yield 'conflicting conditional write' => [self::error('ConditionalRequestConflict', 409), UploadSessionNotFound::class];
        yield 'quota by code' => [self::error('QuotaExceeded', 403), QuotaExceeded::class];
        yield 'quota by status' => [self::error('', 507), QuotaExceeded::class];
        yield 'slow down' => [self::error('SlowDown', 503), TransientStorageFailure::class];
        yield 'internal error' => [self::error('InternalError', 500), TransientStorageFailure::class];
        yield 'internal error in a 200 response' => [self::error('InternalError', 200), TransientStorageFailure::class];
        yield 'bad gateway without code' => [self::error('', 502), TransientStorageFailure::class];
        yield 'too many requests' => [self::error('', 429), TransientStorageFailure::class];
        yield 'connection refused' => [static fn (CommandInterface $command): S3Exception => new S3Exception('injected', $command, ['connection_error' => true], new RuntimeException('cURL error 7: connection refused')), TransientStorageFailure::class];
        yield 'connection reset mid-request' => [self::connectionReset(), TransientStorageFailure::class];
        yield 'unknown client error' => [self::error('SomethingNew', 400), UnexpectedStorageResponse::class];
    }

    /**
     * @param Closure(CommandInterface): S3Exception $error
     * @param class-string<StorageException>         $expected
     */
    #[DataProvider('errors')]
    public function testErrorsBecomeTheContractsFailures(Closure $error, string $expected): void
    {
        $adapter = $this->adapter();
        $this->queue('ListParts', $error);

        try {
            $adapter->listCompletedParts($this->session());
            self::fail('Expected ' . $expected);
        } catch (StorageException $e) {
            self::assertSame($expected, $e::class, $e->getMessage());
            self::assertStringStartsWith('S3 ListParts failed (', $e->getMessage());
        }
    }

    public function testATooSmallPartIsRejectedWhileUploadingAndIncompleteWhileCompleting(): void
    {
        $adapter = $this->adapter(conditionalCreate: true);
        $this->queue('UploadPart', self::error('EntityTooSmall', 400));
        $this->queue('CompleteMultipartUpload', self::error('EntityTooSmall', 400));

        try {
            $adapter->writePart($this->session(), 1, PartBody::fromString(str_repeat('a', 5 * self::MIB)));
            self::fail('Expected PartRejected');
        } catch (PartRejected) {
        }

        $this->expectException(IncompleteUpload::class);
        $adapter->complete($this->session(), [new UploadedPart(1, 0, 5 * self::MIB, 'e1')]);
    }

    public function testFailuresNameNoCredentialsAndCarryNoSdkException(): void
    {
        $adapter = S3StorageAdapter::create(new S3Settings('bucket', 'us-east-1', 'AKIDEXAMPLEONLY', 'secret-value-example', 'session-token-example', endpoint: 'https://s3.example.test'), ['handler' => $this->mock = new MockHandler()]);
        $this->queue('ListParts', self::error('AccessDenied', 403));

        try {
            $adapter->listCompletedParts($this->session(endpoint: 'https://s3.example.test', prefix: ''));
            self::fail('Expected AccessDenied');
        } catch (AccessDenied $e) {
            self::assertNull($e->getPrevious());
            foreach (['AKIDEXAMPLEONLY', 'secret-value-example', 'session-token-example'] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
        // The signed request did carry the session token; it just never reaches the exception.
        self::assertSame('session-token-example', $this->calls[0]['request']->getHeaderLine('x-amz-security-token'));
    }

    public function testSessionsOfAnotherTargetAreRefused(): void
    {
        $adapter = $this->adapter();
        $foreign = $this->session(bucket: 'other-bucket');

        try {
            $adapter->writePart($foreign, 1, PartBody::fromString(str_repeat('a', 5 * self::MIB)));
            self::fail('Expected UploadSessionNotFound');
        } catch (UploadSessionNotFound) {
        }

        $this->expectException(InvalidArgumentException::class);
        $adapter->abort($foreign);
    }

    public function testAbortIgnoresAnUploadThatIsAlreadyGone(): void
    {
        $adapter = $this->adapter();
        $this->queue('AbortMultipartUpload', self::error('NoSuchUpload', 404));

        $adapter->abort($this->session());

        self::assertCount(1, $this->calls);
    }

    public function testStatReportsSizeAndVersionButNoContentChecksum(): void
    {
        $adapter = $this->adapter();
        $this->queue('HeadObject', new Result(['ContentLength' => 42, 'ETag' => '"abc-3"', 'LastModified' => new DateTimeResult('2026-11-11T12:00:00Z')]));
        $this->queue('HeadObject', self::error('NotFound', 404));

        $info = $adapter->stat(ObjectKey::fromString('a.bin'));

        self::assertNotNull($info);
        self::assertSame(42, $info->size);
        self::assertSame('abc-3', $info->remoteETag);
        self::assertNull($info->contentChecksum);
        self::assertNull($adapter->stat(ObjectKey::fromString('b.bin')));
        self::assertSame('archive/a.bin', $this->calls[0]['command']['Key']);
    }

    public function testOpenReadStreamsInBoundedChunks(): void
    {
        $adapter = $this->adapter();
        $this->queue('GetObject', new Result(['Body' => Utils::streamFor('abcdefghij'), 'ContentLength' => 10]));

        $chunks = iterator_to_array($adapter->openRead(ObjectKey::fromString('a.bin'), 4), false);

        self::assertSame(['abcd', 'efgh', 'ij'], $chunks);
        self::assertTrue($this->calls[0]['command']['@http']['stream']);
    }

    public function testOpenReadNoticesAShortBody(): void
    {
        $adapter = $this->adapter();
        $this->queue('GetObject', new Result(['Body' => Utils::streamFor('abcdefghij'), 'ContentLength' => 12]));

        $this->expectException(TransientStorageFailure::class);
        iterator_to_array($adapter->openRead(ObjectKey::fromString('a.bin'), 4));
    }

    public function testDeletingAMissingObjectIsFine(): void
    {
        $adapter = $this->adapter();
        $this->queue('DeleteObject', self::error('NoSuchKey', 404));

        $adapter->delete(ObjectKey::fromString('a.bin'));

        self::assertSame('archive/a.bin', $this->calls[0]['command']['Key']);
    }

    public function testCapabilitiesFollowTheSettings(): void
    {
        $capabilities = $this->adapter(conditionalCreate: true)->capabilities();

        self::assertSame('s3', $capabilities->backend);
        self::assertSame(5 * self::MIB, $capabilities->minPartSize);
        self::assertSame(10000, $capabilities->maxParts);
        self::assertTrue($capabilities->canListParts);
        self::assertTrue($capabilities->conditionalCreate);
        self::assertFalse($capabilities->contentChecksumOnStat);
        self::assertNull($capabilities->sessionIdleTimeoutSeconds);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return Closure(CommandInterface): S3Exception
     */
    private static function error(string $code, int $status, array $headers = []): Closure
    {
        return static fn (CommandInterface $command): S3Exception => new S3Exception('injected', $command, [
            'code' => $code === '' ? null : $code,
            'response' => new Response($status, $headers),
        ]);
    }

    /**
     * A broken connection after the request started, without any response: what the SDK reports for curl's "send
     * failure" (not a connect error, so connection_error is false).
     *
     * @return Closure(CommandInterface): S3Exception
     */
    private static function connectionReset(): Closure
    {
        return static fn (CommandInterface $command): S3Exception => new S3Exception('injected', $command, [], new RuntimeException('cURL error 55: Send failure: Connection reset by peer'));
    }

    /**
     * @param Result<string, mixed>|Closure(CommandInterface): S3Exception $outcome
     */
    private function queue(string $operation, Result|Closure $outcome): void
    {
        $this->mock->append(function (CommandInterface $command, RequestInterface $request) use ($operation, $outcome): Result|S3Exception {
            self::assertSame($operation, $command->getName());
            $this->calls[] = ['command' => $command, 'request' => $request];

            return $outcome instanceof Closure ? $outcome($command) : $outcome;
        });
    }

    private function adapter(string $prefix = 'archive', ?S3ChecksumMode $checksums = null, ?bool $conditionalCreate = null, ?string $storageClass = null): S3StorageAdapter
    {
        $this->mock = new MockHandler();
        $this->calls = [];
        $settings = new S3Settings('bucket', 'us-east-1', 'access', 'secret', endpoint: 'https://s3.example.test', prefix: $prefix, pathStyle: true, storageClass: $storageClass, checksums: $checksums, conditionalCreate: $conditionalCreate);

        return S3StorageAdapter::create($settings, ['handler' => $this->mock]);
    }

    private function session(int $totalSize = 5 * self::MIB, string $bucket = 'bucket', string $endpoint = 'https://s3.example.test', string $prefix = 'archive'): UploadSession
    {
        return new UploadSession('s3', ObjectKey::fromString('a.bin'), 'upload-1', $totalSize, 5 * self::MIB, new DateTimeImmutable(), [
            'endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $prefix, 'checksums' => 'off',
        ]);
    }
}
