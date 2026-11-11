<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\Authorization;
use App\Service\AuthenticationManager;
use App\Service\DownloadManager;
use App\Service\OwnedItemsManager;
use App\Service\Serializer;
use App\Service\Serializer\GameDetailNormalizer;
use App\Service\Serializer\GameExtraNormalizer;
use App\Service\Serializer\GameInstallerNormalizer;
use ArrayObject;
use DateTimeImmutable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wires the real upstream services the way config/services.yaml does, but against a fake HTTP client
 * and in-memory persistence. No kernel, no files, no network.
 */
final class UpstreamServices
{
    /** Public Galaxy client ID from config/services.yaml (not a secret). */
    public const CLIENT_ID = '46899977096215655';

    public readonly Serializer $serializer;

    public readonly AuthenticationManager $authentication;

    public readonly OwnedItemsManager $ownedItems;

    public readonly DownloadManager $downloads;

    public function __construct(
        public readonly HttpClientInterface $http,
        public readonly InMemoryPersistenceManager $persistence,
    ) {
        // Serializer and GameExtraNormalizer depend on each other through OwnedItemsManager. The container
        // solves this with a lazy tagged iterator; an ArrayObject filled afterwards does the same here.
        $normalizers = new ArrayObject();
        $this->serializer = new Serializer($normalizers);
        $this->authentication = new AuthenticationManager($persistence, $http, self::CLIENT_ID);
        $this->ownedItems = new OwnedItemsManager($http, $this->authentication, $this->serializer, $persistence, new ArrayAdapter());
        $this->downloads = new DownloadManager($http, $this->authentication);

        $normalizers->append(new GameDetailNormalizer());
        $normalizers->append(new GameInstallerNormalizer());
        $normalizers->append(new GameExtraNormalizer($this->ownedItems));
    }

    public static function withValidToken(HttpClientInterface $http): self
    {
        return new self($http, new InMemoryPersistenceManager(self::validAuthorization()));
    }

    public static function validAuthorization(): Authorization
    {
        return new Authorization('test-access-token', 'test-refresh-token', new DateTimeImmutable('+1 hour'));
    }
}
