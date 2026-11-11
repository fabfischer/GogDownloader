<?php

declare(strict_types=1);

namespace App\Storage\S3;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Exception\AccessDenied;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\InvalidObjectKey;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\ObjectNotFound;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\QuotaExceeded;
use App\Storage\Exception\StorageException;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UnexpectedStorageResponse;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\RemoteObjectInfo;
use App\Storage\StorageCapabilities;
use App\Storage\StoredObject;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * ArchiveStorageAdapter for S3 and S3-compatible services, built on S3 multipart uploads.
 *
 * - begin() checks that the key is free (HeadObject) and opens a multipart upload. The session keeps the UploadId,
 *   the target (endpoint, bucket, prefix) and the checksum mode, and nothing secret.
 * - writePart() sends one part from the part's memory stream. The SDK reads that stream for the request signature
 *   (and for the CRC32 in checksum mode); it never copies it to a temporary file. The body goes out without waiting
 *   for "100 Continue"; when the connection breaks without an answer, a small ListParts request tells a gone upload or
 *   missing access apart from a network problem.
 * - listCompletedParts() pages through ListParts, for reconciliation after a restart.
 * - complete() refuses an incomplete part list before contacting the server and never replaces an object
 *   (If-None-Match: * where enabled, HeadObject otherwise). A repeated Complete for an upload that already finished
 *   (the first response got lost) returns the stored object instead of failing.
 * - abort() is idempotent; stat() is HeadObject; openRead() streams GetObject in bounded chunks; delete() is
 *   DeleteObject.
 *
 * The SDK's own retries are off. The transfer engine retries parts with backoff and Retry-After; every other temporary
 * failure reaches the caller as TransientStorageFailure. So no retry happens unseen.
 *
 * A multipart ETag is not an MD5 of the file. This adapter uses ETags only to identify parts and stored versions;
 * content is verified with the SHA-256 the transfer computes.
 *
 * Exceptions carry the operation, the S3 error code, the HTTP status and the request ID, but not the SDK exception:
 * that one holds the signed request, including a session token.
 */
final class S3StorageAdapter implements ArchiveStorageAdapter
{
    public const BACKEND = 's3';

    private const MAX_PARTS_PER_LISTING = 1000;

    private const ACCESS_ERRORS = ['AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AllAccessDisabled', 'AccountProblem', 'ExpiredToken', 'InvalidToken', 'TokenRefreshRequired', 'InvalidSecurity'];

    private const PART_ERRORS = ['InvalidPart', 'InvalidPartOrder', 'EntityTooSmall', 'EntityTooLarge'];

    private const TRANSIENT_ERRORS = ['InternalError', 'ServiceUnavailable', 'SlowDown', 'RequestTimeout', 'OperationAborted', 'BadDigest', 'XAmzContentSHA256Mismatch', 'RequestTimeTooSkewed', 'Throttling', 'ThrottlingException', 'RequestLimitExceeded'];

    private readonly StorageCapabilities $capabilities;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    private function __construct(
        private readonly S3ClientInterface $client,
        private readonly S3Settings $settings,
        private readonly ?Closure $clock,
    ) {
        $this->capabilities = new StorageCapabilities(
            backend: self::BACKEND,
            minPartSize: $settings->minPartSize,
            maxPartSize: $settings->maxPartSize,
            maxParts: $settings->maxParts,
            canListParts: true,
            sessionIdleTimeoutSeconds: null,
            conditionalCreate: $settings->conditionalCreate,
            contentChecksumOnStat: false,
        );
    }

    /**
     * @param array<string, mixed>                $clientOptions extra S3Client options for tests, such as "handler"
     *                                                           (an Aws\MockHandler) or "http_handler" (fault injection)
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public static function create(S3Settings $settings, array $clientOptions = [], ?Closure $clock = null): self
    {
        $config = [
            'version' => '2006-03-01',
            'region' => $settings->region,
            'credentials' => new Credentials($settings->accessKeyId, $settings->secretAccessKey, $settings->sessionToken),
            'use_path_style_endpoint' => $settings->pathStyle,
            // Only the settings count: no ~/.aws files, no environment, no instance metadata.
            'use_aws_shared_config_files' => false,
            'retries' => 0,
            // Checksums only where a request asks for one: the checksum mode decides per upload (ChecksumAlgorithm).
            'request_checksum_calculation' => 'when_required',
            // Verifying a streamed download would need the whole body first; the transfer verifies with SHA-256.
            'response_checksum_validation' => 'when_required',
            'http' => [
                'connect_timeout' => $settings->connectTimeoutSeconds,
                'timeout' => $settings->requestTimeoutSeconds,
                'verify' => $settings->caBundle ?? $settings->verifyTls,
                // Guzzle adds "Expect: 100-continue" to bodies over 1 MB, and curl then waits up to one second for a
                // server that never answers it: measured +1 s per part against an S3-compatible server. Sending the
                // body right away only costs one part's bytes when a request is refused.
                'expect' => false,
            ],
        ];
        if ($settings->endpoint !== null) {
            $config['endpoint'] = $settings->endpoint;
        }

        return new self(new S3Client($clientOptions + $config), $settings, $clock);
    }

    public function capabilities(): StorageCapabilities
    {
        return $this->capabilities;
    }

    public function begin(UploadRequest $request): UploadSession
    {
        $count = $request->partCount();
        if ($count > $this->capabilities->maxParts) {
            throw new PartRejected(sprintf('%d parts exceed the limit of %d.', $count, $this->capabilities->maxParts));
        }
        $this->capabilities->assertPartAllowed(1, min($request->partSize, $request->totalSize), $count === 1);

        $remoteKey = $this->remoteKey($request->key);
        if ($this->head($remoteKey) !== null) {
            throw new ObjectAlreadyExists('An object already exists at the requested key.');
        }

        $mode = $this->settings->checksums;
        $params = ['Bucket' => $this->settings->bucket, 'Key' => $remoteKey];
        if ($request->contentType !== null) {
            $params['ContentType'] = $request->contentType;
        }
        if ($this->settings->storageClass !== null) {
            $params['StorageClass'] = $this->settings->storageClass;
        }
        if ($mode === S3ChecksumMode::Crc32) {
            $params['ChecksumAlgorithm'] = 'CRC32';
        }

        $uploadId = self::stringValue($this->call('CreateMultipartUpload', $params), 'UploadId')
            ?? throw new UnexpectedStorageResponse('S3 CreateMultipartUpload returned no UploadId.');

        return new UploadSession(
            backend: self::BACKEND,
            key: $request->key,
            sessionId: $uploadId,
            totalSize: $request->totalSize,
            partSize: $request->partSize,
            startedAt: $this->now(),
            extra: $this->settings->targetIdentity() + ['checksums' => $mode->value],
        );
    }

    public function listCompletedParts(UploadSession $session): array
    {
        $mode = $this->sessionMode($session);
        $parts = [];
        $marker = 0;
        do {
            $params = $this->uploadParams($session) + ['MaxParts' => self::MAX_PARTS_PER_LISTING];
            if ($marker > 0) {
                $params['PartNumberMarker'] = $marker;
            }
            $result = $this->call('ListParts', $params);

            foreach (self::listOf($result, 'Parts') as $listed) {
                $number = (int) ($listed['PartNumber'] ?? 0);
                $etag = self::etag($listed['ETag'] ?? null);
                if ($number < 1 || $etag === null) {
                    throw new UnexpectedStorageResponse('S3 ListParts returned a part without number or ETag.');
                }
                $crc = $listed['ChecksumCRC32'] ?? null;
                $checksum = $mode === S3ChecksumMode::Crc32 && is_string($crc) && $crc !== '' ? 'crc32:' . $crc : null;
                $parts[$number] = new UploadedPart($number, $session->offsetOf($number), (int) ($listed['Size'] ?? 0), $etag, $checksum);
            }

            $truncated = (bool) ($result['IsTruncated'] ?? false);
            $next = (int) ($result['NextPartNumberMarker'] ?? 0);
            if ($truncated && $next <= $marker) {
                throw new UnexpectedStorageResponse('S3 ListParts reported more parts but no next marker.');
            }
            $marker = $next;
        } while ($truncated);

        ksort($parts);

        return array_values($parts);
    }

    public function writePart(UploadSession $session, int $number, PartBody $body): UploadedPart
    {
        $mode = $this->sessionMode($session);
        $count = $session->partCount();
        if ($number < 1 || $number > $count) {
            throw new PartRejected(sprintf('Part %d is outside 1..%d for this session.', $number, $count));
        }
        $expected = $session->lengthOf($number);
        if ($body->length !== $expected) {
            throw new PartRejected(sprintf('Part %d must have %d bytes, got %d.', $number, $expected, $body->length));
        }
        $this->capabilities->assertPartAllowed($number, $expected, $number === $count);

        $params = $this->uploadParams($session) + ['PartNumber' => $number, 'ContentLength' => $expected];
        if ($mode === S3ChecksumMode::Crc32) {
            $params['ChecksumAlgorithm'] = 'CRC32';
        }

        // A PSR-7 stream closes its resource when it is freed. Detaching it afterwards keeps the caller's buffer open,
        // so the same part can be sent again after a temporary failure.
        rewind($body->stream);
        $stream = Utils::streamFor($body->stream);

        try {
            $result = $this->client->execute($this->client->getCommand('UploadPart', $params + ['Body' => $stream]));
        } catch (AwsException $e) {
            $failure = self::translate('UploadPart', $e);

            throw self::gotNoAnswer($e) ? ($this->reasonForDroppedPart($session) ?? $failure) : $failure;
        } finally {
            $stream->detach();
        }

        $etag = self::etag($result['ETag'] ?? null) ?? throw new UnexpectedStorageResponse('S3 UploadPart returned no ETag.');
        $checksum = null;
        if ($mode === S3ChecksumMode::Crc32) {
            $checksum = 'crc32:' . (self::stringValue($result, 'ChecksumCRC32')
                ?? throw new UnexpectedStorageResponse('S3 UploadPart did not confirm the part\'s CRC32; use checksum mode "off" for this target.'));
        }

        return new UploadedPart($number, $session->offsetOf($number), $expected, $etag, $checksum);
    }

    public function complete(UploadSession $session, array $parts): StoredObject
    {
        $mode = $this->sessionMode($session);
        $count = $session->partCount();
        $list = [];
        foreach ($parts as $index => $part) {
            $number = $index + 1;
            if ($number > $count || $part->number !== $number || $part->offset !== $session->offsetOf($number) || $part->length !== $session->lengthOf($number)) {
                throw new IncompleteUpload(sprintf('Completing needs parts 1..%d exactly once, in order, with the session\'s offsets and lengths.', $count));
            }
            $entry = ['PartNumber' => $number, 'ETag' => '"' . $part->remoteId . '"'];
            if ($mode === S3ChecksumMode::Crc32) {
                if ($part->checksum === null || !str_starts_with($part->checksum, 'crc32:')) {
                    throw new IncompleteUpload(sprintf('Part %d has no CRC32, which this upload needs.', $number));
                }
                $entry['ChecksumCRC32'] = substr($part->checksum, strlen('crc32:'));
            }
            $list[] = $entry;
        }
        if (count($list) !== $count) {
            throw new IncompleteUpload(sprintf('Completing needs parts 1..%d; got %d.', $count, count($list)));
        }

        $params = $this->uploadParams($session) + ['MultipartUpload' => ['Parts' => $list]];
        if ($this->settings->conditionalCreate) {
            $params['IfNoneMatch'] = '*';
        } elseif ($this->head($this->remoteKey($session->key)) !== null) {
            return $this->finishedEarlier($session, new ObjectAlreadyExists('An object already exists at the requested key.'), uploadKnownGone: false);
        }

        try {
            $result = $this->call('CompleteMultipartUpload', $params);
        } catch (UploadSessionNotFound $e) {
            return $this->finishedEarlier($session, $e, uploadKnownGone: true);
        } catch (ObjectAlreadyExists $e) {
            return $this->finishedEarlier($session, $e, uploadKnownGone: false);
        }

        return new StoredObject($session->key, $session->totalSize, self::etag($result['ETag'] ?? null), $this->now());
    }

    public function abort(UploadSession $session): void
    {
        if (!$this->isOwnSession($session)) {
            throw new InvalidArgumentException('The upload session belongs to another storage target.');
        }

        try {
            $this->call('AbortMultipartUpload', $this->uploadParams($session));
        } catch (UploadSessionNotFound) {
            // Already aborted, completed or removed by a lifecycle rule: nothing left to drop.
        }
    }

    public function stat(ObjectKey $key): ?RemoteObjectInfo
    {
        $result = $this->head($this->remoteKey($key));
        if ($result === null) {
            return null;
        }
        $size = $result['ContentLength'] ?? null;
        if (!is_numeric($size)) {
            throw new UnexpectedStorageResponse('S3 HeadObject returned no size.');
        }
        $modified = $result['LastModified'] ?? null;

        return new RemoteObjectInfo(
            $key,
            (int) $size,
            self::etag($result['ETag'] ?? null),
            $modified instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($modified) : null,
        );
    }

    public function openRead(ObjectKey $key, int $chunkSize = 1048576): iterable
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be positive.');
        }
        $result = $this->call('GetObject', [
            'Bucket' => $this->settings->bucket,
            'Key' => $this->remoteKey($key),
            // Stream the body instead of letting the HTTP client download it into a temporary stream first. No limit
            // for the whole download, only for each read.
            '@http' => ['stream' => true, 'timeout' => 0, 'read_timeout' => $this->settings->requestTimeoutSeconds],
        ]);
        $body = $result['Body'] ?? null;
        if (!$body instanceof StreamInterface) {
            throw new UnexpectedStorageResponse('S3 GetObject returned no body.');
        }
        $size = $result['ContentLength'] ?? null;

        return self::chunks($body, $chunkSize, is_numeric($size) ? (int) $size : null);
    }

    public function delete(ObjectKey $key): void
    {
        try {
            $this->call('DeleteObject', ['Bucket' => $this->settings->bucket, 'Key' => $this->remoteKey($key)]);
        } catch (ObjectNotFound) {
            // Already gone.
        }
    }

    /**
     * Complete failed because the upload is gone or an object is in the way. If the upload no longer exists and the
     * object at its key has the expected size, an earlier Complete of this upload succeeded and only its response got
     * lost: S3 removes an UploadId only on Complete or Abort, and every upload gets a fresh revision key.
     */
    private function finishedEarlier(UploadSession $session, ObjectAlreadyExists|UploadSessionNotFound $failure, bool $uploadKnownGone): StoredObject
    {
        if (!$uploadKnownGone && !$this->uploadIsGone($session)) {
            throw $failure;
        }

        $info = $this->stat($session->key);
        if ($info === null || $info->size !== $session->totalSize) {
            throw $failure;
        }

        return new StoredObject($session->key, $info->size, $info->remoteETag, $info->lastModified ?? $this->now());
    }

    /**
     * A part upload ended without an answer. The body goes out without waiting for "100 Continue", so a server that
     * refuses the request early (unknown upload, no access) may close the connection while the body is still being
     * sent, and the client only sees a reset. A small ListParts request gets the real answer: null when the upload is
     * fine (the failure was a network problem), otherwise the failure that explains it.
     */
    private function reasonForDroppedPart(UploadSession $session): ?StorageException
    {
        try {
            $this->call('ListParts', $this->uploadParams($session) + ['MaxParts' => 1]);

            return null;
        } catch (TransientStorageFailure) {
            return null;
        } catch (StorageException $e) {
            return $e;
        }
    }

    private function uploadIsGone(UploadSession $session): bool
    {
        try {
            $this->call('ListParts', $this->uploadParams($session) + ['MaxParts' => 1]);

            return false;
        } catch (UploadSessionNotFound) {
            return true;
        }
    }

    private function head(string $remoteKey): ?ResultInterface
    {
        try {
            return $this->call('HeadObject', ['Bucket' => $this->settings->bucket, 'Key' => $remoteKey]);
        } catch (ObjectNotFound) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $operation, array $params): ResultInterface
    {
        try {
            return $this->client->execute($this->client->getCommand($operation, $params));
        } catch (AwsException $e) {
            throw self::translate($operation, $e);
        }
    }

    private static function translate(string $operation, AwsException $e): StorageException
    {
        $code = (string) $e->getAwsErrorCode();
        $status = (int) $e->getStatusCode();
        $details = array_filter([$code, $status > 0 ? 'HTTP ' . $status : '', $e->getAwsRequestId() !== null ? 'request ' . $e->getAwsRequestId() : '']);
        $what = sprintf('S3 %s failed (%s)', $operation, $details === [] ? 'no response' : implode(', ', $details));

        if (self::gotNoAnswer($e)) {
            $cause = $e->getPrevious()?->getMessage();

            return new TransientStorageFailure($what . ': the connection failed' . ($cause !== null ? ': ' . self::shorten($cause) : '.'));
        }

        return match (true) {
            $code === 'NoSuchUpload' => new UploadSessionNotFound($what . ': the upload no longer exists (completed, aborted or expired).'),
            // AWS: after 409 ConditionalRequestConflict the multipart upload has to be started again.
            $code === 'ConditionalRequestConflict' => new UploadSessionNotFound($what . ': a conflicting write; the upload has to start again.'),
            $code === 'NoSuchBucket' => new AccessDenied($what . ': the bucket does not exist.'),
            $code === 'QuotaExceeded' => new QuotaExceeded($what . ': the target has no space left.'),
            in_array($code, self::ACCESS_ERRORS, true) => new AccessDenied($what . ': check the credentials and the bucket policy.'),
            $code === 'PreconditionFailed' => new ObjectAlreadyExists($what . ': an object already exists at the key.'),
            in_array($code, ['NoSuchKey', 'NotFound'], true) => new ObjectNotFound($what . ': no object at the key.'),
            in_array($code, self::PART_ERRORS, true) => $operation === 'CompleteMultipartUpload'
                ? new IncompleteUpload($what . ': the server does not hold the parts as listed.')
                : new PartRejected($what . '.'),
            in_array($code, self::TRANSIENT_ERRORS, true) => new TransientStorageFailure($what . '.', self::retryAfter($e)),
            $status === 412 => new ObjectAlreadyExists($what . ': an object already exists at the key.'),
            $status === 404 => new ObjectNotFound($what . ': no object at the key.'),
            $status === 401 || $status === 403 => new AccessDenied($what . ': check the credentials and the bucket policy.'),
            $status === 507 => new QuotaExceeded($what . ': the target has no space left.'),
            $status === 429 || $status >= 500 => new TransientStorageFailure($what . '.', self::retryAfter($e)),
            default => new UnexpectedStorageResponse($what . '.'),
        };
    }

    /**
     * @return Generator<int, string>
     */
    private static function chunks(StreamInterface $body, int $chunkSize, ?int $expectedSize): Generator
    {
        $received = 0;

        try {
            $buffer = '';
            while (true) {
                $read = $body->read($chunkSize - strlen($buffer));
                if ($read === '') {
                    if ($body->eof()) {
                        break;
                    }

                    throw new TransientStorageFailure('Reading the object from S3 stalled.');
                }
                $buffer .= $read;
                if (strlen($buffer) === $chunkSize) {
                    $received += $chunkSize;

                    yield $buffer;
                    $buffer = '';
                }
            }
            if ($buffer !== '') {
                $received += strlen($buffer);

                yield $buffer;
            }
        } catch (TransientStorageFailure $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new TransientStorageFailure('Reading the object from S3 failed midway: ' . self::shorten($e->getMessage()));
        } finally {
            $body->close();
        }

        if ($expectedSize !== null && $received !== $expectedSize) {
            throw new TransientStorageFailure(sprintf('S3 delivered %d of %d bytes.', $received, $expectedSize));
        }
    }

    /**
     * @return array{Bucket: string, Key: string, UploadId: string}
     */
    private function uploadParams(UploadSession $session): array
    {
        return ['Bucket' => $this->settings->bucket, 'Key' => $this->remoteKey($session->key), 'UploadId' => $session->sessionId];
    }

    private function remoteKey(ObjectKey $key): string
    {
        $remote = $this->settings->prefix === '' ? $key->value : $this->settings->prefix . '/' . $key->value;
        if (strlen($remote) > S3Settings::MAX_KEY_BYTES) {
            throw new InvalidObjectKey(sprintf('With the prefix the key has %d bytes; S3 allows %d.', strlen($remote), S3Settings::MAX_KEY_BYTES));
        }

        return $remote;
    }

    private function sessionMode(UploadSession $session): S3ChecksumMode
    {
        if (!$this->isOwnSession($session)) {
            throw new UploadSessionNotFound('The upload session belongs to another storage target.');
        }

        return S3ChecksumMode::tryFrom($session->extra['checksums'] ?? '')
            ?? throw new UploadSessionNotFound('The upload session has no known checksum mode.');
    }

    private function isOwnSession(UploadSession $session): bool
    {
        if ($session->backend !== self::BACKEND) {
            return false;
        }
        foreach ($this->settings->targetIdentity() as $name => $value) {
            if (($session->extra[$name] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }

    private static function etag(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $etag = trim($value, " \t\"");

        return $etag === '' ? null : $etag;
    }

    private static function stringValue(ResultInterface $result, string $name): ?string
    {
        $value = $result[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function listOf(ResultInterface $result, string $name): array
    {
        $value = $result[$name] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * No HTTP answer at all: the connection failed or broke (refused, reset, timed out).
     */
    private static function gotNoAnswer(AwsException $e): bool
    {
        return $e->isConnectionError() || ((int) $e->getStatusCode() === 0 && (string) $e->getAwsErrorCode() === '');
    }

    private static function retryAfter(AwsException $e): ?int
    {
        $value = trim($e->getResponse()?->getHeaderLine('Retry-After') ?? '');

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private static function shorten(string $message): string
    {
        return strlen($message) > 300 ? substr($message, 0, 300) . '…' : $message;
    }
}
