<?php

declare(strict_types=1);

namespace App\Storage\InMemory;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\ObjectNotFound;
use App\Storage\Exception\PartRejected;
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
use Throwable;

/**
 * A complete ArchiveStorageAdapter in memory, for tests of the transfer engine and of callers. It enforces the same
 * rules as the real backends (part limits, immutable objects, exact part coverage) and can inject failures.
 * Not for production use: everything lives in PHP memory.
 */
final class InMemoryStorageAdapter implements ArchiveStorageAdapter
{
    /** @var array<string, array{session: UploadSession, parts: array<int, array{part: UploadedPart, bytes: string}>}> */
    private array $sessions = [];

    /** @var array<string, array{bytes: string, etag: string, at: DateTimeImmutable}> */
    private array $objects = [];

    /** @var list<Throwable> */
    private array $writeFailures = [];

    private int $sessionCounter = 0;

    private readonly StorageCapabilities $capabilities;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        ?StorageCapabilities $capabilities = null,
        private readonly ?Closure $clock = null,
    ) {
        $this->capabilities = $capabilities ?? new StorageCapabilities(
            backend: 'memory',
            minPartSize: 1,
            maxPartSize: 64 * 1024 * 1024,
            maxParts: 10000,
            canListParts: true,
            sessionIdleTimeoutSeconds: null,
            conditionalCreate: true,
            contentChecksumOnStat: true,
        );
    }

    public function capabilities(): StorageCapabilities
    {
        return $this->capabilities;
    }

    public function begin(UploadRequest $request): UploadSession
    {
        if (isset($this->objects[$request->key->value])) {
            throw new ObjectAlreadyExists('An object already exists at the requested key.');
        }
        if ($request->partCount() > $this->capabilities->maxParts) {
            throw new PartRejected(sprintf('%d parts exceed the limit of %d.', $request->partCount(), $this->capabilities->maxParts));
        }
        if ($request->partCount() > 1) {
            $this->capabilities->assertPartAllowed(1, $request->partSize, false);
        }

        $session = new UploadSession(
            backend: $this->capabilities->backend,
            key: $request->key,
            sessionId: sprintf('memory-%d-%s', ++$this->sessionCounter, bin2hex(random_bytes(4))),
            totalSize: $request->totalSize,
            partSize: $request->partSize,
            startedAt: $this->now(),
        );
        $this->sessions[$session->sessionId] = ['session' => $session, 'parts' => []];

        return $session;
    }

    public function listCompletedParts(UploadSession $session): array
    {
        $parts = array_map(static fn (array $entry): UploadedPart => $entry['part'], $this->openSession($session)['parts']);
        ksort($parts);

        return array_values($parts);
    }

    public function writePart(UploadSession $session, int $number, PartBody $body): UploadedPart
    {
        $this->openSession($session);
        if ($this->writeFailures !== []) {
            throw array_shift($this->writeFailures);
        }

        $count = $session->partCount();
        if ($number < 1 || $number > $count) {
            throw new PartRejected(sprintf('Part %d is outside 1..%d for this session.', $number, $count));
        }
        $expected = $session->lengthOf($number);
        if ($body->length !== $expected) {
            throw new PartRejected(sprintf('Part %d must have %d bytes, got %d.', $number, $expected, $body->length));
        }
        $this->capabilities->assertPartAllowed($number, $body->length, $number === $count);

        $bytes = $body->contents();
        if (strlen($bytes) !== $expected) {
            throw new PartRejected(sprintf('Part %d stream delivered %d bytes instead of %d.', $number, strlen($bytes), $expected));
        }

        $part = new UploadedPart($number, $session->offsetOf($number), $expected, md5($bytes));
        // Writing the same part number again replaces it, like S3 UploadPart.
        $this->sessions[$session->sessionId]['parts'][$number] = ['part' => $part, 'bytes' => $bytes];

        return $part;
    }

    public function complete(UploadSession $session, array $parts): StoredObject
    {
        $stored = $this->openSession($session)['parts'];
        $count = $session->partCount();

        $numbers = array_map(static fn (UploadedPart $part): int => $part->number, $parts);
        if (count($parts) !== $count || array_values(array_unique($numbers)) !== range(1, $count)) {
            throw new IncompleteUpload(sprintf('Completing needs parts 1..%d exactly once, in order.', $count));
        }

        $bytes = '';
        foreach ($parts as $part) {
            $held = $stored[$part->number] ?? null;
            if ($held === null || $held['part']->remoteId !== $part->remoteId) {
                throw new IncompleteUpload(sprintf('Part %d is not held by the session in the given version.', $part->number));
            }
            $bytes .= $held['bytes'];
        }
        if (strlen($bytes) !== $session->totalSize) {
            throw new IncompleteUpload(sprintf('Parts add up to %d bytes, expected %d.', strlen($bytes), $session->totalSize));
        }
        if (isset($this->objects[$session->key->value])) {
            throw new ObjectAlreadyExists('An object already exists at the requested key.');
        }

        $etag = md5($bytes) . '-' . $count;
        $now = $this->now();
        $this->objects[$session->key->value] = ['bytes' => $bytes, 'etag' => $etag, 'at' => $now];
        unset($this->sessions[$session->sessionId]);

        return new StoredObject($session->key, strlen($bytes), $etag, $now);
    }

    public function abort(UploadSession $session): void
    {
        unset($this->sessions[$session->sessionId]);
    }

    public function stat(ObjectKey $key): ?RemoteObjectInfo
    {
        $object = $this->objects[$key->value] ?? null;
        if ($object === null) {
            return null;
        }

        return new RemoteObjectInfo($key, strlen($object['bytes']), $object['etag'], $object['at'], 'sha256:' . hash('sha256', $object['bytes']));
    }

    public function openRead(ObjectKey $key, int $chunkSize = 1048576): iterable
    {
        $object = $this->objects[$key->value] ?? null;
        if ($object === null) {
            throw new ObjectNotFound('No object exists at the requested key.');
        }
        $bytes = $object['bytes'];

        return (static function () use ($bytes, $chunkSize): iterable {
            for ($offset = 0, $size = strlen($bytes); $offset < $size; $offset += $chunkSize) {
                yield substr($bytes, $offset, $chunkSize);
            }
        })();
    }

    public function delete(ObjectKey $key): void
    {
        unset($this->objects[$key->value]);
    }

    /**
     * Makes the next writePart() calls fail with these exceptions, one per call, before anything is stored.
     */
    public function failNextWrites(Throwable ...$failures): void
    {
        array_push($this->writeFailures, ...$failures);
    }

    /**
     * Drops a session as if the backend had expired it after inactivity.
     */
    public function expireSession(UploadSession $session): void
    {
        unset($this->sessions[$session->sessionId]);
    }

    /**
     * @return list<string> keys of all stored objects, sorted
     */
    public function objectKeys(): array
    {
        $keys = array_map(strval(...), array_keys($this->objects));
        sort($keys);

        return $keys;
    }

    public function openSessionCount(): int
    {
        return count($this->sessions);
    }

    /**
     * @return array{session: UploadSession, parts: array<int, array{part: UploadedPart, bytes: string}>}
     */
    private function openSession(UploadSession $session): array
    {
        $entry = $this->sessions[$session->sessionId] ?? null;
        if ($entry === null || $entry['session']->backend !== $session->backend) {
            throw new UploadSessionNotFound('The upload session does not exist (aborted, expired or unknown).');
        }

        return $entry;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }
}
