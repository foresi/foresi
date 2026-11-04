<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Infrastructure\Files\DiskInboundFiles;
use PHPUnit\Framework\TestCase;

/**
 * Raw bank mail holds personal data: it is written only into its tenant's
 * directory, private from the start, never anywhere else.
 */
final class DiskInboundFilesTest extends TestCase
{
    private const string TENANT = '01963c5e-0000-7000-8000-000000000001';
    private const string MESSAGE = '01963c5e-0000-7000-8000-000000000002';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/foresi-inbound-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700);
    }

    protected function tearDown(): void
    {
        $directory = $this->root.'/'.self::TENANT;
        if (is_dir($directory)) {
            exec('chflags nouchg '.escapeshellarg($directory).' 2>/dev/null');
            foreach ($this->found($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        rmdir($this->root);
    }

    public function testAStoredMailIsPrivateAndReadBack(): void
    {
        $files = new DiskInboundFiles($this->root);

        $key = $files->store(self::TENANT, self::MESSAGE, "From: info@kb.cz\r\n\r\nBody\r\n");

        self::assertSame(self::TENANT.'/'.self::MESSAGE.'.eml', $key);
        self::assertSame(0o600, fileperms($this->root.'/'.$key) & 0o777);
        self::assertSame("From: info@kb.cz\r\n\r\nBody\r\n", $files->read(self::TENANT, $key, hash('sha256', "From: info@kb.cz\r\n\r\nBody\r\n")));
    }

    /**
     * tempnam() falls back to the system's temporary directory when it cannot
     * write the one asked for: the mail would land, for a moment, where others
     * may read it. The store refuses instead.
     */
    public function testAMailIsNeverWrittenOutsideTheTenantsDirectory(): void
    {
        $directory = $this->root.'/'.self::TENANT;
        mkdir($directory, 0o700);
        exec('chflags uchg '.escapeshellarg($directory).' 2>/dev/null', result_code: $code);
        if (0 !== $code || is_writable($directory) && @touch($directory.'/probe')) {
            @unlink($directory.'/probe');
            self::markTestSkipped('This system cannot make a directory unwritable for its owner.');
        }
        $before = $this->found(sys_get_temp_dir().'/mail*');

        try {
            new DiskInboundFiles($this->root)->store(self::TENANT, self::MESSAGE, 'secret payer data');
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
