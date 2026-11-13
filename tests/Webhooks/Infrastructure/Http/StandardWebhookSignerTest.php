<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Http;

use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Http\StandardWebhookSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardWebhookSigner::class)]
final class StandardWebhookSignerTest extends TestCase
{
    public function testMatchesTheStandardWebhooksTestVector(): void
    {
        $headers = (new StandardWebhookSigner())->headers(
            'msg_p5jXN8AQM9LWM0D4loKWxJek',
            1614265330,
            '{"test": 2432232314}',
            [WebhookSecret::fromString('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw')],
        );

        self::assertSame([
            'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
            'webhook-timestamp' => '1614265330',
            'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
        ], $headers);
    }

    public function testTwoSecretsGiveTwoSpaceSeparatedSignatures(): void
    {
        $first = WebhookSecret::fromString('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');
        $second = WebhookSecret::generate();
        $signer = new StandardWebhookSigner();

        $signature = $signer->headers('msg_1', 1614265330, '{}', [$first, $second])['webhook-signature'];

        $parts = explode(' ', $signature);
        self::assertCount(2, $parts);
        self::assertSame($signer->headers('msg_1', 1614265330, '{}', [$first])['webhook-signature'], $parts[0]);
        self::assertSame($signer->headers('msg_1', 1614265330, '{}', [$second])['webhook-signature'], $parts[1]);
        self::assertNotSame($parts[0], $parts[1]);
    }
}
