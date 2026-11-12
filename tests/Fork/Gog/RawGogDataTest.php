<?php

declare(strict_types=1);

namespace App\Tests\Fork\Gog;

use App\DTO\RawProductPayload;
use App\Exception\AuthorizationException;
use App\Service\DownloadManager;
use App\Service\OwnedItemsManager;
use App\Tests\Support\FakeGogApi;
use App\Tests\Support\UpstreamServices;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The fork's raw access to GOG's data: the owned-products list and the product payloads unchanged (upstream's
 * normalizers drop versions, file IDs, parts, language packs, DLC parents and extra types, finding F5), and the
 * download link and checksum file of one file.
 */
#[CoversClass(OwnedItemsManager::class)]
#[CoversClass(DownloadManager::class)]
#[CoversClass(RawProductPayload::class)]
final class RawGogDataTest extends TestCase
{
    private const LIST = FakeGogApi::ACCOUNT . '/getFilteredProducts?mediaType=1&page=%d&sortBy=title';

    public function testTheOwnedProductsKeepEveryFieldAcrossPages(): void
    {
        $gog = new FakeGogApi();
        $gog->onJson('GET', sprintf(self::LIST, 1), ['totalPages' => 2, 'totalProducts' => 3, 'products' => [
            ['id' => 1, 'title' => 'First', 'image' => '//images.gog.test/first', 'worksOn' => ['Windows' => true, 'Mac' => false], 'isGame' => true],
            ['id' => 2, 'title' => 'Second', 'image' => '//images.gog.test/second', 'worksOn' => ['Windows' => true, 'Mac' => true], 'isGame' => true],
        ]]);
        $gog->onJson('GET', sprintf(self::LIST, 2), ['totalPages' => 2, 'totalProducts' => 3, 'products' => [
            ['id' => 3, 'title' => 'Third', 'image' => '//images.gog.test/third', 'worksOn' => ['Windows' => false, 'Mac' => true], 'isGame' => true],
        ]]);

        $products = iterator_to_array(UpstreamServices::withValidToken($gog->client())->ownedItems->getRawOwnedProducts(), false);

        self::assertSame([1, 2, 3], array_column($products, 'id'));
        self::assertSame('//images.gog.test/third', $products[2]['image']);
        self::assertSame(['Windows' => false, 'Mac' => true], $products[2]['worksOn']);
        self::assertSame('Authorization: Bearer test-access-token', FakeGogApi::authorizationOf($gog->requests[0]));
    }

    public function testHiddenProductsCanBeLeftOut(): void
    {
        $gog = new FakeGogApi();
        $gog->onJson('GET', sprintf(self::LIST, 1) . '&hiddenFlag=0', ['totalPages' => 1, 'totalProducts' => 0, 'products' => []]);

        $products = iterator_to_array(UpstreamServices::withValidToken($gog->client())->ownedItems->getRawOwnedProducts(includeHidden: false), false);

        self::assertSame([], $products);
        self::assertSame(['GET ' . sprintf(self::LIST, 1) . '&hiddenFlag=0'], $gog->requestLines());
    }

    public function testAnAnswerThatIsNotJsonMeansLoggedOut(): void
    {
        $gog = new FakeGogApi();
        $gog->on('GET', sprintf(self::LIST, 1), new MockResponse('<html>login</html>'));

        $this->expectException(AuthorizationException::class);
        iterator_to_array(UpstreamServices::withValidToken($gog->client())->ownedItems->getRawOwnedProducts());
    }

    public function testTheProductPayloadKeepsWhatTheNormalizersDrop(): void
    {
        $gog = new FakeGogApi();
        $owned = ['dlcs' => [['title' => 'Quest - Owned DLC']], 'extras' => [['name' => 'Manual']]];
        $product = [
            'id' => 1001,
            'title' => 'Quest',
            'downloads' => [
                'installers' => [[
                    'id' => 'installer_windows_en', 'os' => 'windows', 'language' => 'en', 'version' => '1.2.0',
                    'files' => [
                        ['id' => 'en1installer0', 'size' => 1048576, 'downlink' => FakeGogApi::API . '/products/1001/downlink/installer/en1installer0'],
                        ['id' => 'en1installer1', 'size' => 2097152, 'downlink' => FakeGogApi::API . '/products/1001/downlink/installer/en1installer1'],
                    ],
                ]],
                'patches' => [],
                'language_packs' => [['id' => 'langpack_windows_fr', 'os' => 'windows', 'language' => 'fr', 'version' => null, 'files' => []]],
                'bonus_content' => [
                    ['id' => 5001, 'name' => 'Manual', 'type' => 'manuals', 'files' => []],
                    ['id' => 5003, 'name' => 'Not owned', 'type' => 'artworks', 'files' => []],
                ],
            ],
            'expanded_dlcs' => [
                ['id' => 2001, 'title' => 'Quest - Owned DLC', 'downloads' => ['installers' => []]],
                ['id' => 2002, 'title' => 'Quest - Unowned DLC', 'downloads' => ['installers' => []]],
            ],
        ];
        $gog->onJson('GET', FakeGogApi::ACCOUNT . '/gameDetails/1001.json', $owned);
        $gog->onJson('GET', FakeGogApi::API . '/products/1001?expand=downloads%2Cexpanded_dlcs', $product);

        $payload = UpstreamServices::withValidToken($gog->client())->ownedItems->getRawProductPayload(1001);

        self::assertSame(1001, $payload->productId);
        self::assertSame($owned, $payload->owned);
        self::assertSame($product, $payload->product, 'Nothing filtered: unowned DLC and extras, versions, parts and language packs stay');
        self::assertCount(2, $gog->requests, 'No checksum lookups');
        foreach ($gog->requests as $request) {
            self::assertSame('Authorization: Bearer test-access-token', FakeGogApi::authorizationOf($request));
        }
    }

    public function testADownloadLinkResolvesToTheSignedUrlAndTheChecksumFile(): void
    {
        $gog = new FakeGogApi();
        $link = FakeGogApi::API . '/products/1001/downlink/installer/en1installer0';
        $gog->addFile($link, 'setup_quest.exe', str_repeat('a', 32), 1000);

        $resolved = UpstreamServices::withValidToken($gog->client())->downloads->resolveDownlink($link);

        self::assertSame(['downlink' => FakeGogApi::cdnUrl('setup_quest.exe'), 'checksum' => FakeGogApi::cdnUrl('setup_quest.exe.xml')], $resolved);
        self::assertSame('Authorization: Bearer test-access-token', FakeGogApi::authorizationOf($gog->requests[0]));
    }

    public function testAnEmptyChecksumUrlMeansNone(): void
    {
        $gog = new FakeGogApi();
        $link = FakeGogApi::API . '/products/1001/downlink/product_bonus_content/5001';
        $gog->onJson('GET', $link, ['downlink' => FakeGogApi::cdnUrl('manual.pdf'), 'checksum' => '']);

        self::assertNull(UpstreamServices::withValidToken($gog->client())->downloads->resolveDownlink($link)['checksum']);
    }

    public function testTheTokenNeverGoesToAnotherHost(): void
    {
        $gog = new FakeGogApi();

        try {
            UpstreamServices::withValidToken($gog->client())->downloads->resolveDownlink('https://api.gog.com.example.net/products/1/downlink/x');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([], $gog->requests);
    }

    public function testTheChecksumFileOrNullWhenGogHasNone(): void
    {
        $gog = new FakeGogApi();
        $gog->addFile(FakeGogApi::API . '/products/1001/downlink/installer/en1installer0', 'setup_quest.exe', str_repeat('a', 32), 1000);
        $gog->addFile(FakeGogApi::API . '/products/1001/downlink/product_bonus_content/5001', 'manual.pdf', null, 10);
        $downloads = UpstreamServices::withValidToken($gog->client())->downloads;

        $xml = $downloads->getChecksumXml(FakeGogApi::cdnUrl('setup_quest.exe.xml'));

        self::assertNotNull($xml);
        self::assertStringContainsString('md5="' . str_repeat('a', 32) . '"', $xml);
        self::assertNull($downloads->getChecksumXml(FakeGogApi::cdnUrl('manual.pdf.xml')));
        self::assertNull(FakeGogApi::authorizationOf($gog->requests[0]), 'The checksum file needs no token');

        $this->expectException(InvalidArgumentException::class);
        $downloads->getChecksumXml('http://cdn.gog.test/file.xml');
    }
}
