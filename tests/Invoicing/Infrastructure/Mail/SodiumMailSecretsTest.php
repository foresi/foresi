<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailSecretsUnavailable;
use App\Invoicing\Infrastructure\Mail\SodiumMailSecrets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SodiumMailSecretsTest extends TestCase
{
    private const string PASSWORD = 'correct-horse-battery';

    public function testSealsAndOpens(): void
    {
        $secrets = new SodiumMailSecrets('k1:'.self::key(1));

        $sealed = $secrets->seal(self::PASSWORD);

        self::assertTrue($secrets->isConfigured());
        self::assertStringStartsWith('k1:', $sealed);
        self::assertStringNotContainsString(self::PASSWORD, $sealed);
        self::assertStringNotContainsString(base64_encode(self::PASSWORD), $sealed);
        self::assertNotSame($sealed, $secrets->seal(self::PASSWORD), 'Every seal has its own nonce.');
        self::assertSame(self::PASSWORD, $secrets->open($sealed));
        // 24 bytes of nonce, the text and 16 bytes of tag.
        self::assertSame(24 + \strlen(self::PASSWORD) + 16, \strlen((string) base64_decode(substr($sealed, 3), true)));
    }

    public function testAWrongKeyCannotOpen(): void
    {
        $sealed = new SodiumMailSecrets('k1:'.self::key(1))->seal(self::PASSWORD);

        $this->expectException(MailSecretsUnavailable::class);

        new SodiumMailSecrets('k1:'.self::key(2))->open($sealed);
    }

    public function testATamperedSealCannotOpen(): void
    {
        $secrets = new SodiumMailSecrets('k1:'.self::key(1));
        $raw = (string) base64_decode(substr($secrets->seal(self::PASSWORD), 3), true);
        $raw[30] = \chr(\ord($raw[30]) ^ 1);

        $this->expectException(MailSecretsUnavailable::class);

        $secrets->open('k1:'.base64_encode($raw));
    }

    public function testAnOldKeyIdStillOpensAfterRotation(): void
    {
        $sealed = new SodiumMailSecrets('old:'.self::key(1))->seal(self::PASSWORD);

        $rotated = new SodiumMailSecrets('new:'.self::key(2).', old:'.self::key(1));

        self::assertSame(self::PASSWORD, $rotated->open($sealed));
        self::assertStringStartsWith('new:', $rotated->seal(self::PASSWORD));
    }

    public function testAnUnknownKeyIdCannotOpen(): void
    {
        $sealed = new SodiumMailSecrets('gone:'.self::key(1))->seal(self::PASSWORD);

        $this->expectException(MailSecretsUnavailable::class);

        new SodiumMailSecrets('k1:'.self::key(1))->open($sealed);
    }

    #[DataProvider('unreadable')]
    public function testAMalformedSealCannotOpen(string $sealed): void
    {
        $this->expectException(MailSecretsUnavailable::class);

        new SodiumMailSecrets('k1:'.self::key(1))->open($sealed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'no key id' => ['abc'];
        yield 'not base64' => ['k1:%%%'];
        yield 'too short' => ['k1:'.base64_encode('short')];
    }

    public function testNoKeysMeansNotConfigured(): void
    {
        $secrets = new SodiumMailSecrets('');

        self::assertFalse($secrets->isConfigured());
        $this->expectException(MailSecretsUnavailable::class);
        $secrets->seal(self::PASSWORD);
    }

    #[DataProvider('brokenKeys')]
    public function testABrokenKeyListIsNotConfiguredAndNeverShowsAKey(string $keys): void
    {
        $secrets = new SodiumMailSecrets($keys);

        self::assertFalse($secrets->isConfigured());
        try {
            $secrets->seal(self::PASSWORD);
            self::fail('A broken key list cannot seal.');
        } catch (MailSecretsUnavailable $refused) {
            self::assertStringNotContainsString(self::key(1), $refused->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenKeys(): iterable
    {
        yield 'no id' => [self::key(1)];
        yield 'short key' => ['k1:'.base64_encode('sixteen bytes!!!')];
        yield 'not base64' => ['k1:'.str_repeat('%', 44)];
        yield 'the same id twice' => ['k1:'.self::key(1).',k1:'.self::key(2)];
        yield 'an id with a space' => ['k 1:'.self::key(1)];
    }

    private static function key(int $seed): string
    {
        return base64_encode(str_repeat(\chr($seed), SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
