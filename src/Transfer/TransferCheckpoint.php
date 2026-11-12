<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\UploadedPart;
use App\Storage\UploadSession;
use HashContext;
use InvalidArgumentException;

/**
 * Everything needed to continue a transfer in another process:
 * the upload session, the acknowledged parts, the source fingerprint and the running SHA-256 and MD5 state.
 * Written only after the target acknowledged a part.
 */
final readonly class TransferCheckpoint
{
    private const FORMAT = 1;

    /**
     * @param list<UploadedPart> $parts acknowledged parts 1..n, in order
     */
    public function __construct(
        public UploadSession $session,
        public array $parts,
        public SourceFingerprint $fingerprint,
        /** serialize(HashContext) for SHA-256 after the last acknowledged part */
        public string $sha256State,
        /** serialize(HashContext) for MD5 after the last acknowledged part */
        public string $md5State,
    ) {
    }

    public function bytesDone(): int
    {
        return array_sum(array_map(static fn (UploadedPart $part): int => $part->length, $this->parts));
    }

    public function nextPartNumber(): int
    {
        return count($this->parts) + 1;
    }

    /**
     * True when every part of the session is acknowledged and checkpointed, so only completing is left.
     */
    public function hasAllParts(): bool
    {
        return count($this->parts) === $this->session->partCount();
    }

    public function sha256(): HashContext
    {
        return self::context($this->sha256State);
    }

    public function md5(): HashContext
    {
        return self::context($this->md5State);
    }

    /**
     * @return array<string, mixed> JSON-safe
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'session' => $this->session->toArray(),
            'parts' => array_map(static fn (UploadedPart $part): array => $part->toArray(), $this->parts),
            'fingerprint' => $this->fingerprint->toArray(),
            'sha256State' => base64_encode($this->sha256State),
            'md5State' => base64_encode($this->md5State),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT || !is_array($data['session'] ?? null) || !is_array($data['parts'] ?? null) || !is_array($data['fingerprint'] ?? null)) {
            throw new InvalidArgumentException('Unknown transfer checkpoint format.');
        }

        return new self(
            UploadSession::fromArray($data['session']),
            array_values(array_map(static fn (array $part): UploadedPart => UploadedPart::fromArray($part), $data['parts'])),
            SourceFingerprint::fromArray($data['fingerprint']),
            (string) base64_decode((string) $data['sha256State'], true),
            (string) base64_decode((string) $data['md5State'], true),
        );
    }

    private static function context(string $state): HashContext
    {
        $context = @unserialize($state, ['allowed_classes' => [HashContext::class]]);
        if (!$context instanceof HashContext) {
            throw new InvalidArgumentException('The saved hash state cannot be restored.');
        }

        return $context;
    }
}
