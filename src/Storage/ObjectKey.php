<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Exception\InvalidObjectKey;
use Normalizer;
use Stringable;

/**
 * A validated, backend-neutral object key relative to the storage target's configured prefix, for example
 * "objects/games/1207658658-example/artifacts/<uuid>/revisions/<uuid>/setup.exe".
 *
 * Every key an adapter receives has passed these checks, so adapters never see traversal segments, absolute paths,
 * control characters or keys that are too long for S3 (1,024 bytes of UTF-8).
 */
final readonly class ObjectKey implements Stringable
{
    /** S3 limit for the whole key; the configured prefix of a target must fit in what is left. */
    public const MAX_BYTES = 1024;

    public const MAX_SEGMENT_BYTES = 255;

    private function __construct(
        public string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $key): self
    {
        if ($key === '') {
            throw new InvalidObjectKey('An object key must not be empty.');
        }
        if (strlen($key) > self::MAX_BYTES) {
            throw new InvalidObjectKey(sprintf('An object key must not be longer than %d bytes.', self::MAX_BYTES));
        }
        if (!mb_check_encoding($key, 'UTF-8')) {
            throw new InvalidObjectKey('An object key must be valid UTF-8.');
        }
        if (Normalizer::normalize($key, Normalizer::FORM_C) !== $key) {
            throw new InvalidObjectKey('An object key must be in Unicode normalization form C.');
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $key) === 1) {
            throw new InvalidObjectKey('An object key must not contain control characters or backslashes.');
        }
        if (str_starts_with($key, '/') || str_ends_with($key, '/')) {
            throw new InvalidObjectKey('An object key must be relative and must not end with a slash.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidObjectKey('An object key must not contain empty, "." or ".." segments.');
            }
            if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
                throw new InvalidObjectKey(sprintf('An object key segment must not be longer than %d bytes.', self::MAX_SEGMENT_BYTES));
            }
        }

        return new self($key);
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return explode('/', $this->value);
    }

    public function fileName(): string
    {
        $segments = $this->segments();

        return $segments[count($segments) - 1];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
