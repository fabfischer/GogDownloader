<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Transfer\Exception\SourceChanged;
use App\Transfer\SourceFingerprint;
use App\Transfer\TransferSource;
use Throwable;

/**
 * An in-memory TransferSource with scripted failures, for engine tests.
 */
final class FakeSource implements TransferSource
{
    /** @var list<array{int, int}> every readPart() call as [offset, length] */
    public array $reads = [];

    /** @var array<int, list<Throwable>> failures per offset, thrown after the first chunk */
    private array $failures = [];

    public function __construct(
        private readonly string $bytes,
        private readonly bool $resumable = true,
        private readonly string $etag = '"etag-1"',
        private readonly int $chunkSize = 3,
    ) {
    }

    public function failReadAt(int $offset, Throwable ...$failures): void
    {
        $this->failures[$offset] = array_values([...($this->failures[$offset] ?? []), ...$failures]);
    }

    public function open(): SourceFingerprint
    {
        return new SourceFingerprint(strlen($this->bytes), $this->etag, 'Thu, 13 Aug 2026 14:14:23 GMT');
    }

    public function isResumable(): bool
    {
        return $this->resumable;
    }

    public function readPart(int $offset, int $length, SourceFingerprint $expected): iterable
    {
        $this->reads[] = [$offset, $length];
        if (!$expected->matches($this->open())) {
            throw new SourceChanged('fake source changed');
        }
        $part = substr($this->bytes, $offset, $length);
        foreach (str_split($part, max(1, $this->chunkSize)) as $index => $chunk) {
            yield $chunk;
            if ($index === 0 && ($this->failures[$offset] ?? []) !== []) {
                throw array_shift($this->failures[$offset]);
            }
        }
    }
}
