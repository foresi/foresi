<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Crypto;

use App\Shared\Application\SealerUnavailable;
use App\Shared\Infrastructure\Crypto\SodiumSealer;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use PHPUnit\Framework\TestCase;

final class SodiumWebhookSecretsTest extends TestCase
{
    public function testSealsAndOpens(): void
    {
        $secrets = new SodiumWebhookSecrets('k1:'.$this->key(1));
        $secret = WebhookSecret::generate();

        $sealed = $secrets->seal($secret);

        self::assertTrue($secrets->isConfigured());
        self::assertStringStartsWith('k1:', $sealed);
        self::assertStringNotContainsString($secret->reveal(), $sealed);
        self::assertStringNotContainsString(substr($secret->reveal(), 6), $sealed);
        self::assertNotSame($sealed, $secrets->seal($secret), 'Every seal has its own nonce.');
        self::assertSame($secret->key(), $secrets->open($sealed)->key());
    }

    public function testWithoutKeysNothingIsSealed(): void
    {
        $secrets = new SodiumWebhookSecrets('');

        self::assertFalse($secrets->isConfigured());
        $this->expectException(SealerUnavailable::class);
        $this->expectExceptionMessageIsOrContains('WEBHOOK_SECRET_KEYS');

        $secrets->seal(WebhookSecret::generate());
    }

    public function testAWrongKeyCannotOpen(): void
    {
        $sealed = new SodiumWebhookSecrets('k1:'.$this->key(1))->seal(WebhookSecret::generate());

        $this->expectException(SealerUnavailable::class);

        new SodiumWebhookSecrets('k1:'.$this->key(2))->open($sealed);
    }

    public function testASealOfSomethingElseDoesNotOpenAsASecret(): void
    {
        $sealed = new SodiumSealer('k1:'.$this->key(1), 'webhook secrets')->seal('not-a-secret');

        $this->expectException(SealerUnavailable::class);

        new SodiumWebhookSecrets('k1:'.$this->key(1))->open($sealed);
    }

    private function key(int $seed): string
    {
        return base64_encode(str_repeat(\chr($seed), SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
