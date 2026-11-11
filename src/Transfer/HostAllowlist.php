<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Transfer\Exception\RedirectNotAllowed;

/**
 * Hosts a source may contact, as exact names ("cdn.example.com") or suffix patterns ("*.gog.com"). Only https unless
 * plain http is explicitly allowed (local test servers).
 */
final readonly class HostAllowlist
{
    /**
     * @param list<string> $patterns
     */
    public function __construct(
        private array $patterns,
        private bool $allowPlainHttp = false,
    ) {
    }

    public static function gog(): self
    {
        return new self(['*.gog.com', 'gog.com', '*.gog-statics.com']);
    }

    /**
     * @throws RedirectNotAllowed
     */
    public function assertAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' && !($this->allowPlainHttp && $scheme === 'http')) {
            throw new RedirectNotAllowed(sprintf('Scheme "%s" is not allowed for source URLs.', $scheme));
        }
        foreach ($this->patterns as $pattern) {
            $pattern = strtolower($pattern);
            if ($host === $pattern || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return;
            }
        }

        throw new RedirectNotAllowed(sprintf('Host "%s" is not on the source allowlist.', $host));
    }
}
