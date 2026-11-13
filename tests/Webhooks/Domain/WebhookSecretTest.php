<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Domain;

use App\Webhooks\Domain\WebhookSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookSecretTest extends TestCase
{
    public function testAGeneratedSecretIsThirtyTwoRandomBytesWithThePrefix(): void
    {
        $secret = WebhookSecret::generate();

        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/', $secret->reveal());
        self::assertSame(32, \strlen($secret->key()));
        self::assertSame($secret->key(), base64_decode(substr($secret->reveal(), 6), true));
        self::assertNotSame($secret->key(), WebhookSecret::generate()->key());
    }

    public function testAStringSecretGivesItsDecodedBytes(): void
    {
        $bytes = str_repeat("\x01", 24);

        self::assertSame($bytes, WebhookSecret::fromString('whsec_'.base64_encode($bytes))->key());
        self::assertSame(64, \strlen(WebhookSecret::fromString('whsec_'.base64_encode(str_repeat("\x02", 64)))->key()));
    }

    #[DataProvider('invalid')]
    public function testAnInvalidSecretIsRefusedWithoutEchoingIt(string $secret): void
    {
        try {
            WebhookSecret::fromString($secret);
            self::fail('The secret was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'no prefix' => [base64_encode(str_repeat("\x03", 32))];
        yield 'another prefix' => ['whsk_'.base64_encode(str_repeat("\x03", 32))];
        yield '23 bytes' => ['whsec_'.base64_encode(str_repeat("\x03", 23))];
        yield '65 bytes' => ['whsec_'.base64_encode(str_repeat("\x03", 65))];
        yield 'not base64' => ['whsec_'.str_repeat('*', 44)];
    }

    public function testTheSecretDoesNotShowInADump(): void
    {
        $secret = WebhookSecret::generate();

        self::assertStringNotContainsString(substr($secret->reveal(), 6), print_r($secret, true));
        self::assertStringNotContainsString($secret->key(), print_r($secret, true));
    }
}
