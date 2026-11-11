<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage;

use App\Storage\Exception\InvalidObjectKey;
use App\Storage\ObjectKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectKey::class)]
final class ObjectKeyTest extends TestCase
{
    public function testAValidKeyKeepsItsValueAndSegments(): void
    {
        $key = ObjectKey::fromString('objects/games/1-brütal/file.zip');

        self::assertSame('objects/games/1-brütal/file.zip', (string) $key);
        self::assertSame(['objects', 'games', '1-brütal', 'file.zip'], $key->segments());
        self::assertSame('file.zip', $key->fileName());
        self::assertTrue($key->equals(ObjectKey::fromString('objects/games/1-brütal/file.zip')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'trailing slash' => ['objects/'];
        yield 'empty segment' => ['objects//file'];
        yield 'parent segment' => ['objects/../secret'];
        yield 'only parent' => ['..'];
        yield 'current segment' => ['objects/./file'];
        yield 'NUL byte' => ["objects/fi\0le"];
        yield 'newline' => ["objects/fi\nle"];
        yield 'backslash' => ['objects\\..\\file'];
        yield 'not NFC (decomposed umlaut)' => ["objects/Bru\u{0308}tal"];
        yield 'invalid UTF-8' => ["objects/\xFF\xFE"];
        yield 'too long' => [str_repeat('a/', 512) . 'b'];
        yield 'segment too long' => ['objects/' . str_repeat('x', 256)];
    }

    #[DataProvider('unsafeKeys')]
    public function testUnsafeKeysAreRejected(string $key): void
    {
        $this->expectException(InvalidObjectKey::class);
        ObjectKey::fromString($key);
    }

    public function testTheMaximumLengthIsAllowed(): void
    {
        // Five segments: 4 × 255-or-less bytes plus a short one and four slashes = 1,024 bytes.
        $key = implode('/', [str_repeat('k', 255), str_repeat('k', 255), str_repeat('k', 255), str_repeat('k', 254), 'k']);

        self::assertSame(ObjectKey::MAX_BYTES, strlen(ObjectKey::fromString($key)->value));
    }
}
