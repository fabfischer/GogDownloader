<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\Authorization;
use App\DTO\GameDetail;
use App\Enum\Setting;
use App\Service\Persistence\PersistenceManager;

/**
 * Test double for upstream's PersistenceManager: keeps everything in memory, touches no files.
 */
final class InMemoryPersistenceManager implements PersistenceManager
{
    /** @var list<Authorization|null> every value passed to saveAuthorization(), in order */
    public array $savedAuthorizations = [];

    /** @var array<int, GameDetail> */
    private array $games = [];

    /** @var array<string, int|string|float|bool|null> */
    private array $settings = [];

    /** @var array<string, string> */
    private array $hashes = [];

    public function __construct(
        private ?Authorization $authorization = null,
    ) {
    }

    public function getAuthorization(): ?Authorization
    {
        return $this->authorization;
    }

    public function saveAuthorization(?Authorization $authorization): self
    {
        $this->authorization = $authorization;
        $this->savedAuthorizations[] = $authorization;

        return $this;
    }

    public function storeLocalGameData(array $details): void
    {
        $this->games = [];
        foreach ($details as $detail) {
            $this->storeSingleGameDetail($detail);
        }
    }

    /**
     * @return list<GameDetail>
     */
    public function getLocalGameData(): array
    {
        return array_values($this->games);
    }

    public function storeSingleGameDetail(GameDetail $detail): void
    {
        $this->games[$detail->id] = $detail;
    }

    public function storeSetting(Setting $setting, int|string|float|bool|null $value): void
    {
        $this->settings[$setting->value] = $value;
    }

    public function getSetting(Setting $setting): int|string|float|bool|null
    {
        return $this->settings[$setting->value] ?? null;
    }

    public function storeUncompressedHash(string $compressedHash, string $uncompressedHash): void
    {
        $this->hashes[$uncompressedHash] = $compressedHash;
    }

    public function getCompressedHash(string $uncompressedHash): ?string
    {
        return $this->hashes[$uncompressedHash] ?? null;
    }

    public function needsMigrating(bool $excludeEmpty = false): bool
    {
        return false;
    }
}
