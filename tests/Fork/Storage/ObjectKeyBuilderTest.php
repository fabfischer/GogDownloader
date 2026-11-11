<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage;

use App\Storage\Exception\InvalidObjectKey;
use App\Storage\ObjectKey;
use App\Storage\ObjectKeyBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectKeyBuilder::class)]
final class ObjectKeyBuilderTest extends TestCase
{
    private const ARTIFACT = '0190f5e2-7c1a-7d3e-9a4b-1c2d3e4f5a6b';

    private const REVISION = '0190f5e2-8d2b-7e4f-8b5c-2d3e4f5a6b7c';

    private ObjectKeyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ObjectKeyBuilder();
    }

    public function testTheKeyFollowsTheRevisionLayout(): void
    {
        $key = $this->builder->revisionKey(1207658658, 'The Witcher 3: Wild Hunt', self::ARTIFACT, self::REVISION, 'setup_witcher3_4.04.exe');

        self::assertSame(
            'objects/games/1207658658-the-witcher-3-wild-hunt/artifacts/' . self::ARTIFACT . '/revisions/' . self::REVISION . '/setup_witcher3_4.04.exe',
            $key->value,
        );
    }

    public function testUnicodeIsNormalizedToNfc(): void
    {
        $decomposed = "Bru\u{0308}tal Legend";

        self::assertSame('brütal-legend', $this->builder->slug($decomposed));
        self::assertSame('Brütal_Legend_Manual.pdf', $this->builder->fileName($decomposed . ' Manual.pdf'));
        self::assertSame("Br\u{00FC}tal_Legend_Manual.pdf", $this->builder->fileName($decomposed . ' Manual.pdf'));
        self::assertSame('アークナイツ_サウンドトラック.zip', $this->builder->fileName('アークナイツ サウンドトラック.zip'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileFileNames(): iterable
    {
        yield 'relative traversal' => ['../../etc/passwd', 'etc_passwd'];
        yield 'absolute path' => ['/etc/passwd', 'etc_passwd'];
        yield 'windows path' => ['C:\\Windows\\system32\\cmd.exe', 'C_Windows_system32_cmd.exe'];
        yield 'only dots' => ['..', 'file'];
        yield 'single dot' => ['.', 'file'];
        yield 'NUL byte' => ["evil\0.exe", 'evil.exe'];
        yield 'hidden file' => ['.htaccess', 'htaccess'];
        yield 'trailing dot and space' => ['name. ', 'name'];
        yield 'bidi override' => ["invoice\u{202E}gpj.exe", 'invoicegpj.exe'];
        yield 'zero-width space' => ["set\u{200B}up.exe", 'setup.exe'];
        yield 'shell and URL characters' => ['a?b*c|d<e>f"g#h%i&j.zip', 'a_b_c_d_e_f_g_h_i_j.zip'];
        yield 'empty' => ['', 'file'];
        yield 'reserved Windows name' => ['CON.txt', '_CON.txt'];
        yield 'reserved Windows name, lowercase' => ['nul', '_nul'];
    }

    #[DataProvider('hostileFileNames')]
    public function testHostileFileNamesBecomeSafe(string $original, string $expected): void
    {
        $fileName = $this->builder->fileName($original);

        self::assertSame($expected, $fileName);
        $key = $this->builder->revisionKey(1, 'Game', self::ARTIFACT, self::REVISION, $original);
        self::assertSame($fileName, $key->fileName());
        self::assertCount(8, $key->segments());
    }

    public function testVeryLongNamesAreShortenedButKeepTheirExtension(): void
    {
        $fileName = $this->builder->fileName(str_repeat('very_long_name_', 30) . '.zip');

        self::assertLessThanOrEqual(ObjectKeyBuilder::MAX_FILE_NAME_BYTES, strlen($fileName));
        self::assertStringEndsWith('.zip', $fileName);
        self::assertMatchesRegularExpression('/~[0-9a-f]{10}\.zip$/', $fileName);
    }

    public function testMultibyteNamesAreCutOnCharacterBoundaries(): void
    {
        $fileName = $this->builder->fileName(str_repeat('ü', 200) . '.pdf');

        self::assertTrue(mb_check_encoding($fileName, 'UTF-8'));
        self::assertLessThanOrEqual(ObjectKeyBuilder::MAX_FILE_NAME_BYTES, strlen($fileName));
        self::assertStringEndsWith('.pdf', $fileName);
    }

    public function testLongNamesWithTheSameBeginningStayDistinct(): void
    {
        $prefix = str_repeat('Soundtrack_Collection_', 12);

        self::assertNotSame(
            $this->builder->fileName($prefix . 'Disc_1.zip'),
            $this->builder->fileName($prefix . 'Disc_2.zip'),
        );
    }

    public function testTheSameFileNameInTwoRevisionsGivesTwoKeys(): void
    {
        $first = $this->builder->revisionKey(1, 'Game', self::ARTIFACT, self::REVISION, 'setup.exe');
        $second = $this->builder->revisionKey(1, 'Game', self::ARTIFACT, '0190f5e2-9e3c-7f50-8c6d-3e4f5a6b7c8d', 'setup.exe');

        self::assertFalse($first->equals($second));
    }

    public function testTitlesWithoutLettersOrDigitsGetAPlaceholderSlug(): void
    {
        self::assertSame('game', $this->builder->slug('🎮 ™ !!!'));
        self::assertSame('game', $this->builder->slug(''));
    }

    public function testLongTitlesAreShortened(): void
    {
        self::assertLessThanOrEqual(ObjectKeyBuilder::MAX_SLUG_BYTES, strlen($this->builder->slug(str_repeat('Edition ', 40))));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'zero game id' => [0, self::ARTIFACT, self::REVISION];
        yield 'negative game id' => [-5, self::ARTIFACT, self::REVISION];
        yield 'uppercase uuid' => [1, strtoupper(self::ARTIFACT), self::REVISION];
        yield 'uuid with traversal' => [1, self::ARTIFACT, '../../' . self::REVISION];
        yield 'not a uuid' => [1, 'artifact-1', self::REVISION];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidIdentifiersAreRejected(int $gameId, string $artifact, string $revision): void
    {
        $this->expectException(InvalidObjectKey::class);
        $this->builder->revisionKey($gameId, 'Game', $artifact, $revision, 'setup.exe');
    }

    public function testEveryBuiltKeyIsAValidObjectKey(): void
    {
        $key = $this->builder->revisionKey(PHP_INT_MAX, str_repeat('ü', 300), self::ARTIFACT, self::REVISION, str_repeat('ß', 300) . '.tar.gz');

        self::assertSame($key->value, ObjectKey::fromString($key->value)->value);
        self::assertLessThanOrEqual(ObjectKey::MAX_BYTES, strlen($key->value));
    }
}
