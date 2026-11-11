<?php

declare(strict_types=1);

namespace App\Tests\Upstream;

use App\DTO\GameDetail;
use App\DTO\GameExtra;
use App\DTO\GameInfo;
use App\DTO\GameInstaller;
use App\DTO\OwnedItemInfo;
use App\DTO\SearchFilter;
use App\Enum\MediaType;
use App\Exception\AuthorizationException;
use App\Tests\Support\FakeGogApi;
use App\Tests\Support\UpstreamServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Characterization of upstream's catalog path: owned-products paging, game details and the normalizers
 * (src/Service/OwnedItemsManager.php, src/Service/Serializer/Game*Normalizer.php).
 *
 * The synthetic library has one game with a two-part Windows installer, a Mac and a German installer, a patch,
 * a language pack, two DLCs (one owned) and three extras (two owned; one of them has a broken downlink).
 */
#[CoversClass(\App\Service\OwnedItemsManager::class)]
#[CoversClass(\App\Service\Serializer\GameDetailNormalizer::class)]
#[CoversClass(\App\Service\Serializer\GameInstallerNormalizer::class)]
#[CoversClass(\App\Service\Serializer\GameExtraNormalizer::class)]
final class OwnedItemsManagerTest extends TestCase
{
    private const GAME_ID = 1001;

    private const OWNED_DLC_ID = 2001;

    private const UNOWNED_DLC_ID = 2002;

    public function testOwnedProductsArePagedAndDeserialized(): void
    {
        $gog = new FakeGogApi();
        $base = FakeGogApi::ACCOUNT . '/getFilteredProducts?mediaType=1&page=%d&sortBy=title&hiddenFlag=0';
        $gog->onJson('GET', sprintf($base, 1), [
            'totalProducts' => 3,
            'totalPages' => 2,
            'products' => [
                $this->product(1001, 'Synthetic Quest', 'synthetic_quest'),
                $this->product(1002, 'Another Test Game', 'another_test_game'),
            ],
        ]);
        $gog->onJson('GET', sprintf($base, 2), [
            'totalProducts' => 3,
            'totalPages' => 2,
            'products' => [$this->product(1003, 'Third Test Game', 'third_test_game')],
        ]);
        $services = UpstreamServices::withValidToken($gog->client());

        $count = null;
        $items = iterator_to_array($services->ownedItems->getOwnedItems(MediaType::Game, productsCount: $count), false);

        self::assertSame(3, $count);
        self::assertContainsOnlyInstancesOf(GameInfo::class, $items);
        self::assertSame(['Synthetic Quest', 'Another Test Game', 'Third Test Game'], array_map(
            static fn (OwnedItemInfo $info): string => $info->getTitle(),
            $items,
        ));
        self::assertSame('synthetic_quest', $items[0]->getSlug());
        self::assertSame(1001, $items[0]->getId());
        self::assertCount(2, $gog->requests);
    }

    public function testHiddenProductsAreOnlyIncludedOnRequest(): void
    {
        $gog = new FakeGogApi();
        $gog->onJson('GET', FakeGogApi::ACCOUNT . '/getFilteredProducts?mediaType=1&page=1&sortBy=title', [
            'totalProducts' => 0,
            'totalPages' => 1,
            'products' => [],
        ]);
        $services = UpstreamServices::withValidToken($gog->client());

        iterator_to_array($services->ownedItems->getOwnedItems(MediaType::Game, new SearchFilter(includeHidden: true)));

        self::assertStringNotContainsString('hiddenFlag', $gog->requests[0]['url']);
    }

    public function testNonJsonProductListMeansLoggedOut(): void
    {
        $gog = new FakeGogApi();
        $gog->on('GET', FakeGogApi::ACCOUNT . '/getFilteredProducts?mediaType=1&page=1&sortBy=title&hiddenFlag=0', new MockResponse('<html>login</html>'));
        $services = UpstreamServices::withValidToken($gog->client());

        $this->expectException(AuthorizationException::class);
        iterator_to_array($services->ownedItems->getOwnedItems(MediaType::Game));
    }

    public function testGameDetailFlattensInstallersPatchesLanguagePacksAndOwnedDlc(): void
    {
        $gog = $this->syntheticLibrary();
        $services = UpstreamServices::withValidToken($gog->client());

        $detail = $services->ownedItems->getItemDetail($this->gameInfo(), cached: false);

        self::assertInstanceOf(GameDetail::class, $detail);
        self::assertSame(self::GAME_ID, $detail->id);
        self::assertSame('Synthetic Quest', $detail->title);
        self::assertSame('synthetic_quest', $detail->slug);

        $rows = array_map(static fn (GameInstaller $i): array => [
            $i->name, $i->platform, $i->language, (int) $i->size, $i->isPatch, $i->gogGameId, $i->md5,
        ], $detail->downloads);
        self::assertSame([
            // Each part of a multi-part installer becomes its own GameInstaller with the same name.
            ['Synthetic Quest', 'windows', 'en', 1000, false, self::GAME_ID, str_repeat('a', 32)],
            ['Synthetic Quest', 'windows', 'en', 2000, false, self::GAME_ID, str_repeat('b', 32)],
            ['Synthetic Quest', 'mac', 'en', 3000, false, self::GAME_ID, str_repeat('c', 32)],
            // No checksum XML available: md5 becomes an empty string, not null.
            ['Synthetic Quest', 'windows', 'de', 1500, false, self::GAME_ID, ''],
            ['Patch 1.1 to 1.2', 'windows', 'en', 100, true, self::GAME_ID, str_repeat('d', 32)],
            // Language packs are flattened into the installer list without a marker.
            ['French language pack', 'windows', 'fr', 50, false, self::GAME_ID, str_repeat('e', 32)],
            // Only owned DLC is kept; its installers carry the DLC's product ID.
            ['Synthetic Quest - Owned DLC', 'windows', 'en', 500, false, self::OWNED_DLC_ID, str_repeat('f', 32)],
        ], $rows);
    }

    public function testNormalizersDropVersionAndFileIdentity(): void
    {
        // Only the raw product payload still has these (docs/fork/findings.md, F5).
        self::assertFalse(property_exists(GameInstaller::class, 'version'));
        self::assertFalse(property_exists(GameInstaller::class, 'id'));
        self::assertFalse(property_exists(GameExtra::class, 'type'));
    }

    public function testExtrasAreFilteredToOwnedOnesAndDroppedWhenTheirDownlinkFails(): void
    {
        $gog = $this->syntheticLibrary();
        $services = UpstreamServices::withValidToken($gog->client());

        $detail = $services->ownedItems->getItemDetail($this->gameInfo(), cached: false);
        self::assertInstanceOf(GameDetail::class, $detail);

        $extras = array_map(static fn (GameExtra $e): array => [$e->id, $e->name, $e->size, $e->gogGameId, $e->md5], $detail->extras);
        self::assertSame([
            // Owned, checksum XML missing → md5 ''.
            [5001, 'Manual', 10, self::GAME_ID, ''],
            // "Soundtrack" is owned too, but its downlink answers 404, so the normalizer silently drops it.
            // "Not owned extra" is filtered out by title.
        ], $extras);
    }

    public function testChecksumLookupCostsSeveralRequestsPerFile(): void
    {
        $gog = $this->syntheticLibrary();
        $services = UpstreamServices::withValidToken($gog->client());

        $services->ownedItems->getItemDetail($this->gameInfo(), cached: false);

        $counts = array_count_values($gog->requestLines());
        // Product payload re-fetched for every file's checksum: 6 game installers + 3 extra lookups
        // (Manual twice: once in the normalizer, once more because its md5 stayed null; Soundtrack once).
        self::assertSame(9, $counts['GET ' . FakeGogApi::API . '/products/1001?expand=downloads']);
        self::assertSame(1, $counts['GET ' . FakeGogApi::API . '/products/2001?expand=downloads']);
        self::assertSame(1, $counts['GET ' . FakeGogApi::API . '/products/1001?expand=downloads%2Cexpanded_dlcs']);
        self::assertSame(1, $counts['GET ' . FakeGogApi::ACCOUNT . '/gameDetails/1001.json']);
    }

    public function testGameDetailIsCachedBetweenCalls(): void
    {
        $gog = $this->syntheticLibrary();
        $services = UpstreamServices::withValidToken($gog->client());

        $first = $services->ownedItems->getItemDetail($this->gameInfo());
        $requestsAfterFirstCall = count($gog->requests);
        $second = $services->ownedItems->getItemDetail($this->gameInfo());

        self::assertEquals($first, $second);
        self::assertCount($requestsAfterFirstCall, $gog->requests);
    }

    private function syntheticLibrary(): FakeGogApi
    {
        $gog = new FakeGogApi();
        $gog->onJson('GET', FakeGogApi::ACCOUNT . '/gameDetails/1001.json', [
            'dlcs' => [['title' => 'Synthetic Quest - Owned DLC']],
            'extras' => [['name' => 'Manual'], ['name' => 'Soundtrack']],
        ]);

        $game = $this->gameProduct();
        $gog->onJson('GET', FakeGogApi::API . '/products/1001?expand=downloads%2Cexpanded_dlcs', $game);
        $gog->onJson('GET', FakeGogApi::API . '/products/1001?expand=downloads', array_diff_key($game, ['expanded_dlcs' => true]));
        $gog->onJson('GET', FakeGogApi::API . '/products/2001?expand=downloads', [
            'id' => self::OWNED_DLC_ID,
            'title' => 'Synthetic Quest - Owned DLC',
            'downloads' => $game['expanded_dlcs'][0]['downloads'],
        ]);

        $files = [
            ['installer/en1installer0', 'setup_synthetic_quest_1.2.0_(1).exe', str_repeat('a', 32), 1000],
            ['installer/en1installer1', 'setup_synthetic_quest_1.2.0_(1)-1.bin', str_repeat('b', 32), 2000],
            ['installer/en2installer0', 'synthetic_quest_1_2_0.pkg', str_repeat('c', 32), 3000],
            ['installer/de1installer0', 'setup_synthetic_quest_1.2.0_de.exe', null, 1500],
            ['patch/en1patch0', 'patch_synthetic_quest_1.1_to_1.2.exe', str_repeat('d', 32), 100],
            ['language_pack/fr1langpack0', 'setup_synthetic_quest_french.exe', str_repeat('e', 32), 50],
            ['product_bonus_content/5001', 'synthetic_quest_manual.pdf', null, 10],
        ];
        foreach ($files as [$path, $name, $md5, $size]) {
            $gog->addFile(FakeGogApi::API . "/products/1001/downlink/{$path}", $name, $md5, $size);
        }
        $gog->addFile(FakeGogApi::API . '/products/2001/downlink/installer/en1installer0', 'setup_synthetic_quest_dlc.exe', str_repeat('f', 32), 500);
        $gog->on('GET', FakeGogApi::API . '/products/1001/downlink/product_bonus_content/5002', new MockResponse('', ['http_code' => 404]));

        return $gog;
    }

    /**
     * @return array<string, mixed>
     */
    private function gameProduct(): array
    {
        $installer = static fn (string $id, string $name, string $os, string $language, array $files): array => [
            'id' => $id,
            'name' => $name,
            'os' => $os,
            'language' => $language,
            'language_full' => $language,
            'version' => '1.2.0',
            'total_size' => array_sum(array_column($files, 'size')),
            'files' => $files,
        ];
        $file = static fn (int $productId, string $path, int $size): array => [
            'id' => basename($path),
            'size' => $size,
            'downlink' => FakeGogApi::API . "/products/{$productId}/downlink/{$path}",
        ];
        $bonus = static fn (int $id, string $name, string $type, int $size): array => [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'count' => 1,
            'total_size' => $size,
            'files' => [['id' => $id, 'size' => $size, 'downlink' => FakeGogApi::API . "/products/1001/downlink/product_bonus_content/{$id}"]],
        ];

        return [
            'id' => self::GAME_ID,
            'title' => 'Synthetic Quest',
            'slug' => 'synthetic_quest',
            'downloads' => [
                'installers' => [
                    $installer('installer_windows_en', 'Synthetic Quest', 'windows', 'en', [
                        $file(1001, 'installer/en1installer0', 1000),
                        $file(1001, 'installer/en1installer1', 2000),
                    ]),
                    $installer('installer_mac_en', 'Synthetic Quest', 'mac', 'en', [$file(1001, 'installer/en2installer0', 3000)]),
                    $installer('installer_windows_de', 'Synthetic Quest', 'windows', 'de', [$file(1001, 'installer/de1installer0', 1500)]),
                ],
                'patches' => [
                    $installer('patch_windows_en', 'Patch 1.1 to 1.2', 'windows', 'en', [$file(1001, 'patch/en1patch0', 100)]),
                ],
                'language_packs' => [
                    $installer('langpack_windows_fr', 'French language pack', 'windows', 'fr', [$file(1001, 'language_pack/fr1langpack0', 50)]),
                ],
                'bonus_content' => [
                    $bonus(5001, 'Manual', 'manuals', 10),
                    $bonus(5002, 'Soundtrack', 'audio', 20),
                    $bonus(5003, 'Not owned extra', 'artworks', 30),
                ],
            ],
            'expanded_dlcs' => [
                [
                    'id' => self::OWNED_DLC_ID,
                    'title' => 'Synthetic Quest - Owned DLC',
                    'downloads' => [
                        'installers' => [
                            $installer('installer_windows_en', 'Synthetic Quest - Owned DLC', 'windows', 'en', [$file(2001, 'installer/en1installer0', 500)]),
                        ],
                        'patches' => [],
                        'language_packs' => [],
                        'bonus_content' => [],
                    ],
                ],
                [
                    'id' => self::UNOWNED_DLC_ID,
                    'title' => 'Synthetic Quest - Unowned DLC',
                    'downloads' => [
                        'installers' => [
                            $installer('installer_windows_en', 'Synthetic Quest - Unowned DLC', 'windows', 'en', [$file(2002, 'installer/en1installer0', 700)]),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function gameInfo(): GameInfo
    {
        $services = UpstreamServices::withValidToken((new FakeGogApi())->client());
        $info = $services->serializer->deserialize($this->product(self::GAME_ID, 'Synthetic Quest', 'synthetic_quest'), GameInfo::class);
        self::assertInstanceOf(GameInfo::class, $info);

        return $info;
    }

    /**
     * @return array<string, mixed>
     */
    private function product(int $id, string $title, string $slug): array
    {
        return ['id' => $id, 'title' => $title, 'slug' => $slug, 'updates' => 0, 'isNew' => false, 'isGame' => true, 'isMovie' => false];
    }
}
