<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Exception\InvalidObjectKey;
use Normalizer;

/**
 * Builds immutable, revision-addressed keys:
 *
 *   objects/games/{gogGameId}-{slug}/artifacts/{artifactUuid}/revisions/{revisionUuid}/{fileName}
 *
 * The caller keeps the original file name and title separately; the key only has to be safe and unique. Uniqueness
 * comes from the revision UUID (a new one per upload attempt), not from the file name. Names from GOG are never
 * used as paths as they are: every segment is normalized to NFC, stripped of separators, control characters and
 * characters that break on common file systems, and shortened to a byte limit with a hash suffix so that two long
 * names stay distinct.
 */
final readonly class ObjectKeyBuilder
{
    public const MAX_FILE_NAME_BYTES = 180;

    public const MAX_SLUG_BYTES = 80;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Names Windows refuses for files, relevant when a Nextcloud folder is synced to a Windows client. */
    private const RESERVED_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    public function revisionKey(int $gogGameId, string $title, string $artifactUuid, string $revisionUuid, string $originalFileName): ObjectKey
    {
        if ($gogGameId <= 0) {
            throw new InvalidObjectKey('The GOG game ID must be a positive integer.');
        }

        return ObjectKey::fromString(implode('/', [
            'objects',
            'games',
            $gogGameId . '-' . $this->slug($title),
            'artifacts',
            $this->uuid($artifactUuid),
            'revisions',
            $this->uuid($revisionUuid),
            $this->fileName($originalFileName),
        ]));
    }

    /**
     * A safe file name that keeps the extension. Never empty, never "." or "..", at most MAX_FILE_NAME_BYTES.
     */
    public function fileName(string $originalFileName): string
    {
        $clean = $this->clean($originalFileName);
        if ($clean === '') {
            return 'file';
        }

        $extension = '';
        $dot = strrpos($clean, '.');
        if ($dot !== false && $dot > 0 && strlen($clean) - $dot <= 16) {
            $extension = substr($clean, $dot);
            $clean = substr($clean, 0, $dot);
        }

        if (in_array(strtoupper($clean), self::RESERVED_NAMES, true)) {
            $clean = '_' . $clean;
        }

        return $this->fit($clean, $extension, self::MAX_FILE_NAME_BYTES, $originalFileName);
    }

    /**
     * A lowercase, readable directory name for a game title. Only for humans browsing the target: the GOG game ID
     * in front of it is what identifies the game, and a renamed title never moves existing objects.
     */
    public function slug(string $title): string
    {
        $slug = mb_strtolower($this->clean($title), 'UTF-8');
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'game';
        }

        return $this->fit($slug, '', self::MAX_SLUG_BYTES, $title);
    }

    private function uuid(string $uuid): string
    {
        if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new InvalidObjectKey('Artifact and revision IDs must be lowercase canonical UUIDs.');
        }

        return $uuid;
    }

    /**
     * NFC, no separators or control characters, a conservative character set, no leading dots, no trailing dots or
     * underscores.
     */
    private function clean(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = (string) Normalizer::normalize($value, Normalizer::FORM_C);
        // Control and format characters (incl. NUL, bidi overrides, zero-width), then whitespace runs.
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $value);
        $value = (string) preg_replace('/\s+/u', '_', $value);
        // Everything outside letters, marks, digits and a few safe punctuation characters becomes "_".
        $value = (string) preg_replace('/[^\p{L}\p{M}\p{N}._\-()+]/u', '_', $value);
        $value = (string) preg_replace('/_{2,}/', '_', $value);
        $value = (string) preg_replace('/\.{2,}/', '.', $value);
        $value = ltrim($value, '._');

        return rtrim($value, '._ ');
    }

    /**
     * Shortens $base so that base + extension fit into $maxBytes. A shortened name gets a short hash of the original
     * so that two long names with the same beginning stay different.
     */
    private function fit(string $base, string $extension, int $maxBytes, string $original): string
    {
        if (strlen($base . $extension) <= $maxBytes) {
            return $base . $extension;
        }

        $suffix = '~' . substr(hash('sha256', $original), 0, 10);
        $room = $maxBytes - strlen($extension) - strlen($suffix);
        $short = rtrim(mb_strcut($base, 0, max(1, $room), 'UTF-8'), '._-');

        return $short . $suffix . $extension;
    }
}
