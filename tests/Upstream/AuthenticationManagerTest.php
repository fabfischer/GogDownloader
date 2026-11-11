<?php

declare(strict_types=1);

namespace App\Tests\Upstream;

use App\DTO\Authorization;
use App\Exception\AuthenticationException;
use App\Tests\Support\FakeGogApi;
use App\Tests\Support\InMemoryPersistenceManager;
use App\Tests\Support\UpstreamServices;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Characterization of upstream's GOG login and token refresh (src/Service/AuthenticationManager.php).
 * Pins current behaviour, including flaws, so later refactors notice changes.
 */
#[CoversClass(\App\Service\AuthenticationManager::class)]
final class AuthenticationManagerTest extends TestCase
{
    public function testValidTokenIsReturnedWithoutAnyRequest(): void
    {
        $gog = new FakeGogApi();
        $services = UpstreamServices::withValidToken($gog->client());

        $authorization = $services->authentication->getAuthorization();

        self::assertSame('test-access-token', (string) $authorization);
        self::assertSame([], $gog->requests);
    }

    public function testMissingAuthorizationThrows(): void
    {
        $services = new UpstreamServices((new FakeGogApi())->client(), new InMemoryPersistenceManager());

        $this->expectException(AuthenticationException::class);
        $services->authentication->getAuthorization();
    }

    public function testExpiredTokenIsRefreshedAndSaved(): void
    {
        $gog = new FakeGogApi();
        $gog->onPrefix('GET', FakeGogApi::AUTH . '/token?', static fn (): ResponseInterface => new MockResponse(json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR)));
        $persistence = new InMemoryPersistenceManager(
            new Authorization('old-access-token', 'old-refresh-token', new DateTimeImmutable('-1 minute')),
        );
        $services = new UpstreamServices($gog->client(), $persistence);

        $authorization = $services->authentication->getAuthorization();

        self::assertSame('new-access-token', $authorization->token);
        self::assertSame('new-refresh-token', $authorization->refreshToken);
        self::assertGreaterThan(new DateTimeImmutable('+59 minutes'), $authorization->validUntil);
        self::assertSame([$authorization], $persistence->savedAuthorizations);

        // The refresh is a GET with the refresh token in the query string.
        self::assertCount(1, $gog->requests);
        self::assertSame('GET', $gog->requests[0]['method']);
        parse_str((string) parse_url($gog->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('refresh_token', $query['grant_type']);
        self::assertSame('old-refresh-token', $query['refresh_token']);
        self::assertSame(UpstreamServices::CLIENT_ID, $query['client_id']);
    }

    public function testFailedRefreshThrowsAuthenticationException(): void
    {
        $gog = new FakeGogApi();
        $gog->onPrefix('GET', FakeGogApi::AUTH . '/token?', static fn (): ResponseInterface => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400]));
        $persistence = new InMemoryPersistenceManager(
            new Authorization('old-access-token', 'old-refresh-token', new DateTimeImmutable('-1 minute')),
        );
        $services = new UpstreamServices($gog->client(), $persistence);

        try {
            $services->authentication->getAuthorization();
            self::fail('Expected an AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('login again', $e->getMessage());
        }
        self::assertSame([], $persistence->savedAuthorizations);
    }

    public function testCodeLoginExchangesTheCodeAndSavesTokens(): void
    {
        $gog = new FakeGogApi();
        $gog->onPrefix('GET', FakeGogApi::AUTH . '/token?', static fn (): ResponseInterface => new MockResponse(json_encode([
            'access_token' => 'code-access-token',
            'refresh_token' => 'code-refresh-token',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR)));
        $persistence = new InMemoryPersistenceManager();
        $services = new UpstreamServices($gog->client(), $persistence);

        $services->authentication->codeLogin('fake-authorization-code');

        self::assertCount(1, $persistence->savedAuthorizations);
        self::assertSame('code-access-token', $persistence->getAuthorization()?->token);
        parse_str((string) parse_url($gog->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('authorization_code', $query['grant_type']);
        self::assertSame('fake-authorization-code', $query['code']);
    }

    public function testFailedCodeLoginThrowsAndSavesNothing(): void
    {
        $gog = new FakeGogApi();
        $gog->onPrefix('GET', FakeGogApi::AUTH . '/token?', static fn (): ResponseInterface => new MockResponse('{}', ['http_code' => 401]));
        $persistence = new InMemoryPersistenceManager();
        $services = new UpstreamServices($gog->client(), $persistence);

        $this->expectException(AuthenticationException::class);

        try {
            $services->authentication->codeLogin('wrong-code');
        } finally {
            self::assertSame([], $persistence->savedAuthorizations);
        }
    }
}
