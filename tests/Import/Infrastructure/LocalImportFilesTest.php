<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Infrastructure\LocalImportFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LocalImportFiles::class)]
final class LocalImportFilesTest extends TestCase
{
    private string $root;
    private string $source;
    private LocalImportFiles $files;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/foresi-import-files-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/imports';
        $this->source = $base.'/upload.tmp';
        file_put_contents($this->source, "name;email\nExample Company;testing@example.com\n");
        $this->files = new LocalImportFiles($this->root);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(\dirname($this->root));
    }

    public function testStoreCopiesTheFileIntoTheStoreWithMode0600(): void
    {
        $key = $this->files->store($this->source, 'contacts.csv');

        self::assertTrue(Uuid::isValid($key));
        $path = $this->files->path($key);
        self::assertSame($this->root.'/'.$key, $path);
        self::assertSame(file_get_contents($this->source), file_get_contents($path));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($path)), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->root)), -4));
        self::assertFileExists($this->source);
    }

    public function testAnExistingStoreDirectoryIsTightenedTo0700(): void
    {
        mkdir($this->root, 0o755, true);

        $this->files->store($this->source, 'a.csv');

        clearstatcache();
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->root)), -4));
    }

    public function testTheOriginalNameIsNotPartOfThePath(): void
    {
        $key = $this->files->store($this->source, '../../etc/passwd.csv');

        self::assertStringNotContainsString('passwd', $key);
        self::assertStringNotContainsString('..', $key);
    }

    public function testEachStoreGetsItsOwnKey(): void
    {
        self::assertNotSame($this->files->store($this->source, 'a.csv'), $this->files->store($this->source, 'a.csv'));
    }

    public function testAKeyStoredUnderATenantDirectoryStillReads(): void
    {
        $key = Uuid::v4()->toRfc4122().'/'.Uuid::v4()->toRfc4122();
        mkdir($this->root.'/'.\dirname($key), 0o700, true);
        file_put_contents($this->root.'/'.$key, 'legacy');

        self::assertSame('legacy', file_get_contents($this->files->path($key)));

        $this->files->delete($key);

        self::assertFileDoesNotExist($this->root.'/'.$key);
    }

    public function testStoringAMissingFileFailsAndLeavesNothingBehind(): void
    {
        try {
            $this->files->store($this->root.'/missing.tmp', 'a.csv');
            self::fail('Expected RuntimeException.');
        } catch (\RuntimeException) {
            self::assertSame([], glob($this->root.'/*'));
        }
    }

    public function testDeleteRemovesTheFileAndIsIdempotent(): void
    {
        $key = $this->files->store($this->source, 'a.csv');
        $path = $this->files->path($key);

        $this->files->delete($key);
        $this->files->delete($key);

        self::assertFileDoesNotExist($path);
    }

    public function testPathOfAMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->files->path(Uuid::v4()->toRfc4122());
    }

    #[DataProvider('malformedKeys')]
    public function testMalformedKeysAreRejected(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->files->path($key);
    }

    #[DataProvider('malformedKeys')]
    public function testMalformedKeysCannotDeleteAnything(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->files->delete($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../../etc/passwd'];
        yield 'traversal in the uuid slot' => ['11111111-1111-7111-8111-111111111111/../../../etc/passwd'];
        yield 'three parts' => ['11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222/33333333-3333-4333-8333-333333333333'];
        yield 'trailing slash after one part' => ['11111111-1111-7111-8111-111111111111/'];
        yield 'trailing slash' => ['11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222/'];
        yield 'prefix before the key' => ['x11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222'];
        yield 'newline at the end' => ["11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222\n"];
    }
}
