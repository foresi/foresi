<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Infrastructure\Files\DiskInboundFiles;
use PHPUnit\Framework\TestCase;

/**
 * Raw bank mail holds personal data: it is written only into its directory,
 * private from the start, never anywhere else.
 */
final class DiskInboundFilesTest extends TestCase
{
    private const string OLD_DIRECTORY = '01963c5e-0000-7000-8000-000000000001';
    private const string MESSAGE = '01963c5e-0000-7000-8000-000000000002';
    private const string MAIL = "From: info@kb.cz\r\n\r\nBody\r\n";

    private string $root;
    private string $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/foresi-inbound-'.bin2hex(random_bytes(6));
        $this->directory = $this->root.'/mail';
        mkdir($this->root, 0o700);
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory.'/'.self::OLD_DIRECTORY, $this->directory] as $directory) {
            if (is_dir($directory)) {
                exec('chflags nouchg '.escapeshellarg($directory).' 2>/dev/null');
                foreach ($this->found($directory.'/*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($directory);
            }
        }
        rmdir($this->root);
    }

    public function testAStoredMailIsPrivateAndReadBack(): void
    {
        $files = new DiskInboundFiles($this->directory);

        $key = $files->store(self::MESSAGE, self::MAIL);

        self::assertSame(self::MESSAGE.'.eml', $key);
        self::assertSame(0o600, fileperms($this->directory.'/'.$key) & 0o777);
        self::assertSame(self::MAIL, $files->read(self::MESSAGE, $key, hash('sha256', self::MAIL)));
    }

    public function testMailStoredOneDirectoryDeeperIsStillRead(): void
    {
        mkdir($this->directory.'/'.self::OLD_DIRECTORY, 0o700, true);
        file_put_contents($this->directory.'/'.self::OLD_DIRECTORY.'/'.self::MESSAGE.'.eml', self::MAIL);

        self::assertSame(self::MAIL, new DiskInboundFiles($this->directory)->read(self::MESSAGE, self::OLD_DIRECTORY.'/'.self::MESSAGE.'.eml', hash('sha256', self::MAIL)));
    }

    public function testTheFileOfAnotherMessageIsRefused(): void
    {
        $files = new DiskInboundFiles($this->directory);
        $key = $files->store(self::MESSAGE, self::MAIL);

        $this->expectExceptionMessageIsOrContains('The inbound mail does not belong to the message.');
        $files->read(self::OLD_DIRECTORY, $key, hash('sha256', self::MAIL));
    }

    /**
     * tempnam() falls back to the system's temporary directory when it cannot
     * write the one asked for: the mail would land, for a moment, where others
     * may read it. The store refuses instead.
     */
    public function testAMailIsNeverWrittenOutsideItsDirectory(): void
    {
        mkdir($this->directory, 0o700);
        exec('chflags uchg '.escapeshellarg($this->directory).' 2>/dev/null', result_code: $code);
        if (0 !== $code || is_writable($this->directory) && @touch($this->directory.'/probe')) {
            @unlink($this->directory.'/probe');
            self::markTestSkipped('This system cannot make a directory unwritable for its owner.');
        }
        $before = $this->found(sys_get_temp_dir().'/mail*');

        try {
            new DiskInboundFiles($this->directory)->store(self::MESSAGE, 'secret payer data');
            self::fail('The store refuses a temporary file outside the directory.');
        } catch (\RuntimeException $refused) {
            self::assertSame('The inbound mail cannot be stored.', $refused->getMessage());
        }

        self::assertSame($before, $this->found(sys_get_temp_dir().'/mail*'), 'Nothing is left in the system temporary directory.');
    }

    /**
     * @return list<string>
     */
    private function found(string $pattern): array
    {
        $paths = glob($pattern);

        return false === $paths ? [] : $paths;
    }
}
