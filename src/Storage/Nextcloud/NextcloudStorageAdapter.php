<?php

declare(strict_types=1);

namespace App\Storage\Nextcloud;

use App\Storage\ArchiveStorageAdapter;
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
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\RemoteObjectInfo;
use App\Storage\StorageCapabilities;
use App\Storage\StoredObject;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use Closure;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * ArchiveStorageAdapter for Nextcloud, built on its WebDAV chunked upload (version 2): an upload folder per object,
 * numbered chunk files in it, and a final MOVE that assembles them at the object's path.
 *
 * - begin() checks that nothing is at the object's path, creates its missing parent folders and the upload folder.
 *   Nextcloud refuses chunks while the destination folder is missing, and with object storage as primary storage an
 *   upload aimed at an existing file changes that file's recorded size, so both happen before the first chunk.
 * - writePart() sends one chunk from the part's memory stream, named with five digits (00001…10000).
 * - listCompletedParts() lists the upload folder: only chunks with the expected size count.
 * - complete() checks the folder holds exactly the chunks 1…n with their sizes (with object storage Nextcloud
 *   assembles whatever chunks exist, without checking the total), then MOVEs with "Overwrite: F", so the server
 *   refuses to replace an existing file. A repeated complete() after a lost answer returns the stored file.
 * - abort() deletes the upload folder; stat() is PROPFIND; openRead() streams GET in bounded chunks; delete() removes
 *   one file and refuses folders.
 *
 * Nothing outside the configured folder and the adapter's own upload folders is ever written or deleted, and nothing
 * is listed and then deleted. Upload folders are named "gog-archive-" plus 32 hex digits; sessions with other names
 * are refused.
 *
 * Requests are never retried here and never follow redirects. Exceptions carry the operation, the HTTP status, the
 * server's request ID and its error message, but no credentials.
 */
final class NextcloudStorageAdapter implements ArchiveStorageAdapter
{
    public const BACKEND = 'nextcloud';

    private const SESSION_PATTERN = '/^gog-archive-[0-9a-f]{32}$/';

    private const CHUNK_NAME = '/^[0-9]{1,5}$/';

    private const PROPFIND = '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/><d:getetag/><d:getlastmodified/></d:prop></d:propfind>';

    private readonly StorageCapabilities $capabilities;

    /** @var array<string, mixed> */
    private readonly array $defaults;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    private function __construct(
        private readonly HttpClientInterface $http,
        private readonly NextcloudSettings $settings,
        private readonly ?Closure $clock,
    ) {
        $this->capabilities = new StorageCapabilities(
            backend: self::BACKEND,
            minPartSize: $settings->minPartSize,
            maxPartSize: $settings->maxPartSize,
            maxParts: $settings->maxParts,
            canListParts: true,
            sessionIdleTimeoutSeconds: $settings->sessionIdleTimeoutSeconds,
            conditionalCreate: true,
            contentChecksumOnStat: false,
        );
        $defaults = [
            'auth_basic' => [$settings->loginName, $settings->appPassword],
            'max_redirects' => 0,
            'timeout' => $settings->idleTimeoutSeconds,
            'verify_peer' => $settings->verifyTls,
            'verify_host' => $settings->verifyTls,
        ];
        if ($settings->caBundle !== null) {
            $defaults['cafile'] = $settings->caBundle;
        }
        $this->defaults = $defaults;
    }

    /**
     * @param HttpClientInterface|null            $http  for tests (MockHttpClient, fault injection); defaults to Symfony's
     *                                                   client, which never retries on its own and sends no
     *                                                   "Expect: 100-continue"
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public static function create(NextcloudSettings $settings, ?HttpClientInterface $http = null, ?Closure $clock = null): self
    {
        return new self($http ?? HttpClient::create(), $settings, $clock);
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

        $segments = $this->remoteSegments($request->key);
        // A folder created just now holds nothing yet; otherwise look. Revision keys put every upload in a new folder.
        if (!$this->ensureFolder(array_slice($segments, 0, -1)) && $this->entry('PROPFIND file', $this->filesUrl($segments)) !== null) {
            throw new ObjectAlreadyExists('A file or folder already exists at the requested key.');
        }

        $sessionId = 'gog-archive-' . bin2hex(random_bytes(16));
        $response = $this->send('MKCOL upload folder', 'MKCOL', $this->uploadsUrl($sessionId), ['Destination' => $this->filesUrl($segments)]);
        if ($response['status'] !== 201) {
            throw $this->failure('MKCOL upload folder', $response);
        }

        return new UploadSession(
            backend: self::BACKEND,
            key: $request->key,
            sessionId: $sessionId,
            totalSize: $request->totalSize,
            partSize: $request->partSize,
            startedAt: $this->now(),
            extra: $this->settings->targetIdentity(),
        );
    }

    public function listCompletedParts(UploadSession $session): array
    {
        $this->assertOwnSession($session);

        return array_values($this->chunks($session, $this->sessionEntries($session))['parts']);
    }

    public function writePart(UploadSession $session, int $number, PartBody $body): UploadedPart
    {
        $this->assertOwnSession($session);
        $count = $session->partCount();
        if ($number < 1 || $number > $count) {
            throw new PartRejected(sprintf('Part %d is outside 1..%d for this session.', $number, $count));
        }
        $expected = $session->lengthOf($number);
        if ($body->length !== $expected) {
            throw new PartRejected(sprintf('Part %d must have %d bytes, got %d.', $number, $expected, $body->length));
        }
        $this->capabilities->assertPartAllowed($number, $expected, $number === $count);

        rewind($body->stream);

        try {
            $response = $this->send('PUT chunk', 'PUT', $this->uploadsUrl($session->sessionId) . '/' . self::chunkName($number), [
                'Destination' => $this->filesUrl($this->remoteSegments($session->key)),
                'OC-Total-Length' => (string) $session->totalSize,
                'Content-Length' => (string) $expected,
                'Content-Type' => 'application/octet-stream',
            ], $body->stream);
        } catch (TransientStorageFailure $e) {
            // The connection broke without an answer. If the upload folder is gone or the account is locked out, say
            // so: retrying the chunk would not help.
            throw $this->reasonForFailedChunk($session) ?? $e;
        }

        if (in_array($response['status'], [200, 201, 204], true)) {
            return new UploadedPart($number, $session->offsetOf($number), $expected, self::chunkName($number));
        }

        throw match ($response['status']) {
            404 => $this->missingFolderBehind($session, $response),
            // With object storage as primary storage, Nextcloud keeps upload state in its distributed cache; without it
            // the upload cannot go on ("Missing metadata for chunked upload").
            412 => new UploadSessionNotFound($this->describe('PUT chunk', $response) . ': the server no longer knows this upload; it has to start again.'),
            400 => new PartRejected($this->describe('PUT chunk', $response) . '.'),
            default => $this->failure('PUT chunk', $response),
        };
    }

    public function complete(UploadSession $session, array $parts): StoredObject
    {
        $this->assertOwnSession($session);
        $count = $session->partCount();
        foreach ($parts as $index => $part) {
            $number = $index + 1;
            if ($number > $count || $part->number !== $number || $part->offset !== $session->offsetOf($number) || $part->length !== $session->lengthOf($number)) {
                throw new IncompleteUpload(sprintf('Completing needs parts 1..%d exactly once, in order, with the session\'s offsets and lengths.', $count));
            }
        }
        if (count($parts) !== $count) {
            throw new IncompleteUpload(sprintf('Completing needs parts 1..%d; got %d.', $count, count($parts)));
        }

        try {
            $entries = $this->sessionEntries($session);
        } catch (UploadSessionNotFound $e) {
            return $this->finishedEarlier($session, $e, uploadKnownGone: true);
        }
        $chunks = $this->chunks($session, $entries);
        $missing = array_values(array_diff(range(1, $count), array_keys($chunks['parts'])));
        if ($missing !== [] || $chunks['foreign'] !== []) {
            throw new IncompleteUpload(sprintf(
                'The upload folder does not hold exactly the chunks 1..%d with their sizes (missing: %s; unexpected: %s).',
                $count,
                self::listing($missing),
                self::listing($chunks['foreign']),
            ));
        }

        $segments = $this->remoteSegments($session->key);
        for ($attempt = 1; ; ++$attempt) {
            try {
                $response = $this->send('MOVE upload', 'MOVE', $this->uploadsUrl($session->sessionId) . '/.file', [
                    'Destination' => $this->filesUrl($segments),
                    'OC-Total-Length' => (string) $session->totalSize,
                    'Overwrite' => 'F',
                ], timeout: $this->settings->assemblyTimeoutSeconds);
            } catch (TransientStorageFailure $e) {
                throw new TransientStorageFailure($e->getMessage() . ' The server may still be assembling the file; completing again later finds it.');
            }

            switch ($response['status']) {
                case 201:
                case 204:
                    return new StoredObject($session->key, $session->totalSize, self::etag($response['headers']['etag'][0] ?? null), $this->now());
                case 412:
                    // "Overwrite: F" and something is at the destination: another object, or this upload's own result
                    // when an earlier MOVE succeeded and only its answer got lost.
                    return $this->finishedEarlier($session, new ObjectAlreadyExists($this->describe('MOVE upload', $response) . ': a file already exists at the key.'), uploadKnownGone: false);
                case 404:
                    return $this->finishedEarlier($session, new UploadSessionNotFound($this->describe('MOVE upload', $response) . ': the upload folder no longer exists.'), uploadKnownGone: true);
                case 409:
                    if ($attempt === 1) {
                        // The destination folder disappeared since begin().
                        $this->ensureFolder(array_slice($segments, 0, -1));

                        continue 2;
                    }

                    throw $this->failure('MOVE upload', $response);
                case 400:
                    throw new IncompleteUpload($this->describe('MOVE upload', $response) . '.');
                case 423:
                    throw new TransientStorageFailure($this->describe('MOVE upload', $response) . ': the file is locked, probably because the server is still assembling it.', self::retryAfter($response));
                default:
                    throw $this->failure('MOVE upload', $response);
            }
        }
    }

    /**
     * @throws InvalidArgumentException if the session belongs to another storage target: its folder is never deleted
     * @throws StorageException
     */
    public function abort(UploadSession $session): void
    {
        if (!$this->isOwnSession($session)) {
            throw new InvalidArgumentException('The upload session belongs to another storage target.');
        }

        $response = $this->send('DELETE upload folder', 'DELETE', $this->uploadsUrl($session->sessionId));
        if (!in_array($response['status'], [200, 204, 404], true)) {
            // 404: already completed, aborted or removed by Nextcloud's cleanup job; nothing left to drop.
            throw $this->failure('DELETE upload folder', $response);
        }
    }

    public function stat(ObjectKey $key): ?RemoteObjectInfo
    {
        $entry = $this->entry('PROPFIND file', $this->filesUrl($this->remoteSegments($key)));
        if ($entry === null) {
            return null;
        }
        if ($entry->collection) {
            throw new UnexpectedStorageResponse('Nextcloud has a folder where the object should be.');
        }
        if ($entry->size === null) {
            throw new UnexpectedStorageResponse('Nextcloud reported no size for the object.');
        }

        return new RemoteObjectInfo($key, $entry->size, $entry->etag, $entry->lastModified);
    }

    public function openRead(ObjectKey $key, int $chunkSize = 1048576): iterable
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be positive.');
        }

        try {
            // Stream the body instead of collecting it: no limit for the whole download, only for each pause.
            $response = $this->http->request('GET', $this->filesUrl($this->remoteSegments($key)), ['buffer' => false, 'max_duration' => 0] + $this->defaults);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw self::noAnswer('GET file', $e);
        }
        if ($status !== 200) {
            $failure = ['status' => $status, 'headers' => $response->getHeaders(false), 'body' => self::bodyOf($response)];

            throw $status === 404 ? new ObjectNotFound($this->describe('GET file', $failure) . ': no file at the key.') : $this->failure('GET file', $failure);
        }
        $length = $response->getHeaders(false)['content-length'][0] ?? null;

        return $this->chunked($response, $chunkSize, is_numeric($length) ? (int) $length : null);
    }

    public function delete(ObjectKey $key): void
    {
        $url = $this->filesUrl($this->remoteSegments($key));
        $entry = $this->entry('PROPFIND file', $url);
        if ($entry === null) {
            return;
        }
        if ($entry->collection) {
            // Deleting a folder in WebDAV deletes everything below it.
            throw new UnexpectedStorageResponse('Refusing to delete: Nextcloud has a folder where the object should be.');
        }

        $response = $this->send('DELETE file', 'DELETE', $url);
        if (!in_array($response['status'], [200, 204, 404], true)) {
            throw $this->failure('DELETE file', $response);
        }
    }

    /**
     * Complete failed because the upload folder is gone or a file is in the way. If the folder no longer exists and
     * the file at the key has the expected size, an earlier MOVE of this upload succeeded and only its answer got lost:
     * Nextcloud removes the upload folder when a MOVE succeeds, and every upload goes to a fresh revision key.
     */
    private function finishedEarlier(UploadSession $session, ObjectAlreadyExists|UploadSessionNotFound $failure, bool $uploadKnownGone): StoredObject
    {
        if (!$uploadKnownGone && $this->entry('PROPFIND upload folder', $this->uploadsUrl($session->sessionId)) !== null) {
            throw $failure;
        }

        $info = $this->stat($session->key);
        if ($info === null || $info->size !== $session->totalSize) {
            throw $failure;
        }

        return new StoredObject($session->key, $info->size, $info->remoteETag, $info->lastModified ?? $this->now());
    }

    /**
     * A chunk PUT answered 404: either the upload folder is gone (expired, aborted, completed) or the destination
     * folder is, because Nextcloud checks the quota of the destination's folder before it accepts a chunk.
     *
     * @param array{status: int, headers: array<string, list<string>>, body: string} $response
     */
    private function missingFolderBehind(UploadSession $session, array $response): StorageException
    {
        if ($this->entry('PROPFIND upload folder', $this->uploadsUrl($session->sessionId)) === null) {
            return new UploadSessionNotFound($this->describe('PUT chunk', $response) . ': the upload folder no longer exists (completed, aborted or expired).');
        }
        $this->ensureFolder(array_slice($this->remoteSegments($session->key), 0, -1));

        return new TransientStorageFailure($this->describe('PUT chunk', $response) . ': the destination folder was missing and has been created again.');
    }

    /**
     * A chunk upload ended without an answer. Asks the server about the upload folder: null when it still exists (the
     * failure was a network problem), otherwise the failure that explains it.
     */
    private function reasonForFailedChunk(UploadSession $session): ?StorageException
    {
        try {
            return $this->entry('PROPFIND upload folder', $this->uploadsUrl($session->sessionId)) === null
                ? new UploadSessionNotFound('Nextcloud PUT chunk failed: the upload folder no longer exists (completed, aborted or expired).')
                : null;
        } catch (TransientStorageFailure) {
            return null;
        } catch (StorageException $e) {
            return $e;
        }
    }

    /**
     * Creates the folders that are missing along a path below the account's files root. Tries the deepest folder
     * first, because usually only that one is new, and walks upwards while Nextcloud reports a missing parent (409);
     * then creates the rest downwards. A folder another worker creates in between is fine.
     *
     * @param list<string> $segments
     *
     * @return bool whether the deepest folder was created by this call, so that nothing can be inside it yet
     */
    private function ensureFolder(array $segments): bool
    {
        $missing = [];
        $status = 0;
        for ($depth = count($segments); $depth > 0; --$depth) {
            $status = $this->createFolder(array_slice($segments, 0, $depth));
            if ($status === 201) {
                break;
            }
            if ($status === 405) {
                // Something exists there already: it has to be a folder.
                if ($this->entry('PROPFIND folder', $this->filesUrl(array_slice($segments, 0, $depth)))?->collection !== true) {
                    throw new UnexpectedStorageResponse('Nextcloud has a file where the archive needs a folder.');
                }

                break;
            }
            $missing[] = $depth;
        }
        if ($segments === [] || $depth === 0) {
            // Nothing to create, or not even the first folder below the files root could be created.
            if ($missing !== []) {
                throw new UnexpectedStorageResponse('Nextcloud refused to create the archive folder below the account\'s files root.');
            }

            return false;
        }

        $created = $status === 201;
        foreach (array_reverse($missing) as $depth) {
            $status = $this->createFolder(array_slice($segments, 0, $depth));
            if ($status === 409) {
                throw new UnexpectedStorageResponse('Nextcloud has a file where the archive needs a folder, or a folder disappeared while the path was created.');
            }
            $created = $status === 201;
        }

        return $created;
    }

    /**
     * @param list<string> $segments
     *
     * @return 201|405|409 created, exists already, parent missing
     */
    private function createFolder(array $segments): int
    {
        $response = $this->send('MKCOL folder', 'MKCOL', $this->filesUrl($segments));

        return match ($response['status']) {
            201 => 201,
            405 => 405,
            409 => 409,
            default => throw $this->failure('MKCOL folder', $response),
        };
    }

    /**
     * @return list<DavEntry>
     */
    private function sessionEntries(UploadSession $session): array
    {
        $response = $this->send('PROPFIND upload folder', 'PROPFIND', $this->uploadsUrl($session->sessionId), ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], self::PROPFIND);
        if ($response['status'] === 404) {
            throw new UploadSessionNotFound($this->describe('PROPFIND upload folder', $response) . ': the upload folder no longer exists (completed, aborted or expired).');
        }
        if ($response['status'] !== 207) {
            throw $this->failure('PROPFIND upload folder', $response);
        }

        return MultiStatus::parse($response['body']);
    }

    /**
     * The chunks of an upload folder: those with a valid number and the expected size, and the numbers of anything
     * else that looks like a chunk. The folder itself, ".file" (the assembled view) and ".target" (Nextcloud's own
     * placeholder with object storage) are no chunks. With object storage Nextcloud lists chunks without the leading
     * zeros.
     *
     * @param list<DavEntry> $entries
     *
     * @return array{parts: array<int, UploadedPart>, foreign: list<int|string>}
     */
    private function chunks(UploadSession $session, array $entries): array
    {
        $parts = [];
        $foreign = [];
        foreach ($entries as $entry) {
            if ($entry->collection || preg_match(self::CHUNK_NAME, $entry->name) !== 1) {
                continue;
            }
            $number = (int) $entry->name;
            if ($number < 1 || $number > $session->partCount() || $entry->size !== $session->lengthOf($number) || isset($parts[$number])) {
                $foreign[] = $entry->name;

                continue;
            }
            $parts[$number] = new UploadedPart($number, $session->offsetOf($number), $entry->size, self::chunkName($number));
        }
        ksort($parts);

        return ['parts' => $parts, 'foreign' => $foreign];
    }

    private function entry(string $operation, string $url): ?DavEntry
    {
        $response = $this->send($operation, 'PROPFIND', $url, ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'], self::PROPFIND);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 207) {
            throw $this->failure($operation, $response);
        }

        return MultiStatus::parse($response['body'])[0] ?? throw new UnexpectedStorageResponse(sprintf('Nextcloud %s returned an empty listing.', $operation));
    }

    /**
     * @param array<string, string> $headers
     * @param resource|string|null  $body
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function send(string $operation, string $method, string $url, array $headers = [], mixed $body = null, ?float $timeout = null): array
    {
        $options = ['headers' => $headers] + $this->defaults;
        if ($body !== null) {
            $options['body'] = $body;
        }
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        try {
            $response = $this->http->request($method, $url, $options);

            return ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders(false), 'body' => $response->getContent(false)];
        } catch (TransportExceptionInterface $e) {
            throw self::noAnswer($operation, $e);
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function chunked(ResponseInterface $response, int $chunkSize, ?int $expectedSize): Generator
    {
        $received = 0;
        $buffer = '';

        try {
            foreach ($this->http->stream($response) as $chunk) {
                $buffer .= $chunk->getContent();
                while (strlen($buffer) >= $chunkSize) {
                    $received += $chunkSize;

                    yield substr($buffer, 0, $chunkSize);
                    $buffer = substr($buffer, $chunkSize);
                }
            }
            if ($buffer !== '') {
                $received += strlen($buffer);

                yield $buffer;
            }
        } catch (TransportExceptionInterface $e) {
            throw new TransientStorageFailure('Reading the file from Nextcloud failed midway: ' . self::shorten($e->getMessage()));
        } finally {
            $response->cancel();
        }

        if ($expectedSize !== null && $received !== $expectedSize) {
            throw new TransientStorageFailure(sprintf('Nextcloud delivered %d of %d bytes.', $received, $expectedSize));
        }
    }

    /**
     * @param array{status: int, headers: array<string, list<string>>, body: string} $response
     */
    private function failure(string $operation, array $response): StorageException
    {
        $what = $this->describe($operation, $response);
        $status = $response['status'];

        return match (true) {
            $status === 401 => new AccessDenied($what . ': check the login name and the app password.'),
            $status === 403 => new AccessDenied($what . ': the account may not do this here.'),
            $status === 404 => new ObjectNotFound($what . '.'),
            $status === 413 => new PartRejected($what . ': the server or a proxy in front of it refuses requests this large; lower the maximum part size.'),
            $status === 507 => new QuotaExceeded($what . ': the account has no space left.'),
            in_array($status, [408, 423, 425, 429], true) || $status >= 500 => new TransientStorageFailure($what . '.', self::retryAfter($response)),
            $status >= 300 && $status < 400 => new UnexpectedStorageResponse($what . ': redirected; check the base URL (https, path).'),
            default => new UnexpectedStorageResponse($what . '.'),
        };
    }

    /**
     * @param array{status: int, headers: array<string, list<string>>, body: string} $response
     */
    private function describe(string $operation, array $response): string
    {
        $details = ['HTTP ' . $response['status']];
        $requestId = $response['headers']['x-request-id'][0] ?? null;
        if ($requestId !== null && $requestId !== '') {
            $details[] = 'request ' . $requestId;
        }
        $what = sprintf('Nextcloud %s failed (%s)', $operation, implode(', ', $details));
        if (preg_match('#<(?:[A-Za-z0-9]+:)?message[^>]*>(.*?)</(?:[A-Za-z0-9]+:)?message>#s', $response['body'], $match) === 1) {
            $message = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1));
            if ($message !== '') {
                $what .= ' "' . self::shorten($message) . '"';
            }
        }

        return $what;
    }

    private static function noAnswer(string $operation, TransportExceptionInterface $e): TransientStorageFailure
    {
        return new TransientStorageFailure(sprintf('Nextcloud %s failed (no response): the connection failed: %s', $operation, self::shorten($e->getMessage())));
    }

    private function assertOwnSession(UploadSession $session): void
    {
        if (!$this->isOwnSession($session)) {
            throw new UploadSessionNotFound('The upload session belongs to another storage target.');
        }
    }

    private function isOwnSession(UploadSession $session): bool
    {
        if ($session->backend !== self::BACKEND || preg_match(self::SESSION_PATTERN, $session->sessionId) !== 1) {
            return false;
        }
        foreach ($this->settings->targetIdentity() as $name => $value) {
            if (($session->extra[$name] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function remoteSegments(ObjectKey $key): array
    {
        return $this->settings->prefix === '' ? $key->segments() : [...explode('/', $this->settings->prefix), ...$key->segments()];
    }

    /**
     * @param list<string> $segments
     */
    private function filesUrl(array $segments): string
    {
        return $this->settings->baseUrl . '/remote.php/dav/files/' . rawurlencode($this->settings->userId)
            . ($segments === [] ? '' : '/' . implode('/', array_map(rawurlencode(...), $segments)));
    }

    private function uploadsUrl(string $sessionId): string
    {
        return $this->settings->baseUrl . '/remote.php/dav/uploads/' . rawurlencode($this->settings->userId) . '/' . $sessionId;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }

    private static function chunkName(int $number): string
    {
        return sprintf('%05d', $number);
    }

    private static function etag(?string $value): ?string
    {
        $etag = trim($value ?? '', " \t\"");

        return $etag === '' ? null : $etag;
    }

    /**
     * @param list<int|string> $values
     */
    private static function listing(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', array_slice($values, 0, 10)) . (count($values) > 10 ? ', …' : '');
    }

    /**
     * @param array{status: int, headers: array<string, list<string>>, body: string} $response
     */
    private static function retryAfter(array $response): ?int
    {
        $value = trim($response['headers']['retry-after'][0] ?? '');

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private static function bodyOf(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (TransportExceptionInterface) {
            return '';
        }
    }

    private static function shorten(string $message): string
    {
        return strlen($message) > 300 ? substr($message, 0, 300) . '…' : $message;
    }
}
