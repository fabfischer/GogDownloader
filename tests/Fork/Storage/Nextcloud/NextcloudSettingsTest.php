<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage\Nextcloud;

use App\Storage\Nextcloud\NextcloudSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NextcloudSettings::class)]
final class NextcloudSettingsTest extends TestCase
{
    public function testDefaultsFollowNextcloudsChunkingLimits(): void
    {
        $settings = new NextcloudSettings('https://cloud.example.com/', 'archiver', 'app-password-0000');

        self::assertSame('https://cloud.example.com', $settings->baseUrl);
        self::assertSame('archiver', $settings->userId, 'The user ID defaults to the login name');
        self::assertSame('', $settings->prefix);
        self::assertSame(5 * 1048576, $settings->minPartSize);
        self::assertSame(5_000_000_000, $settings->maxPartSize);
        self::assertSame(10000, $settings->maxParts);
        self::assertSame(86400, $settings->sessionIdleTimeoutSeconds);
        self::assertTrue($settings->verifyTls);
    }

    public function testNextcloudInASubdirectoryAndAnOwnUserId(): void
    {
        $settings = new NextcloudSettings('https://example.com/nextcloud', 'archiver@example.com', 'app-password-0000', '/GOG Archive/Ü/', userId: 'a1b2c3');

        self::assertSame('https://example.com/nextcloud', $settings->baseUrl);
        self::assertSame('a1b2c3', $settings->userId);
        self::assertSame('GOG Archive/Ü', $settings->prefix);
        self::assertSame(['baseUrl' => 'https://example.com/nextcloud', 'userId' => 'a1b2c3', 'prefix' => 'GOG Archive/Ü'], $settings->targetIdentity());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function badBaseUrls(): iterable
    {
        yield 'plain http' => ['http://cloud.example.com', false];
        yield 'no scheme' => ['cloud.example.com', false];
        yield 'credentials' => ['https://user:secret@cloud.example.com', false];
        yield 'query' => ['https://cloud.example.com/?x=1', false];
        yield 'fragment' => ['https://cloud.example.com/#x', false];
        yield 'the WebDAV address instead of the server' => ['https://cloud.example.com/remote.php/dav', false];
        yield 'ftp, even with plain http allowed' => ['ftp://cloud.example.com', true];
    }

    #[DataProvider('badBaseUrls')]
    public function testBadBaseUrlsAreRefused(string $url, bool $allowPlainHttp): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NextcloudSettings($url, 'archiver', 'app-password-0000', allowPlainHttp: $allowPlainHttp);
    }

    public function testPlainHttpOnlyWhenAllowed(): void
    {
        self::assertSame('http://nextcloud', (new NextcloudSettings('http://nextcloud', 'archiver', 'app-password-0000', allowPlainHttp: true))->baseUrl);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badValues(): iterable
    {
        yield 'empty login' => [['loginName' => '']];
        yield 'empty password' => [['appPassword' => '']];
        yield 'user ID with a slash' => [['userId' => 'a/b']];
        yield 'prefix with ..' => [['prefix' => 'archive/../other']];
        yield 'prefix not in NFC' => [['prefix' => "Spie\u{0308}le"]];
        yield 'empty CA bundle' => [['caBundle' => '']];
        yield 'maximum below minimum' => [['minPartSize' => 10, 'maxPartSize' => 5]];
        yield 'more than 10,000 parts' => [['maxParts' => 10001]];
        yield 'idle timeout of a second' => [['sessionIdleTimeoutSeconds' => 1]];
        yield 'zero timeout' => [['idleTimeoutSeconds' => 0.0]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('badValues')]
    public function testBadValuesAreRefused(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NextcloudSettings(...$values + ['baseUrl' => 'https://cloud.example.com', 'loginName' => 'archiver', 'appPassword' => 'app-password-0000']);
    }

    public function testDebugOutputHidesTheAppPassword(): void
    {
        $settings = new NextcloudSettings('https://cloud.example.com', 'archiver', 'app-password-secret-value');

        self::assertStringNotContainsString('app-password-secret-value', print_r($settings, true));
        self::assertStringNotContainsString('app-password-secret-value', var_export($settings->__debugInfo(), true));
    }
}
