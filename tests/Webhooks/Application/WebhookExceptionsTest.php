<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Application;

use App\Webhooks\Application\UnknownWebhookDelivery;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookEndpointDisabled;
use App\Webhooks\Application\WebhookNotAllowed;
use App\Webhooks\Application\WebhookSecretsUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * The messages of the webhook errors reach REST and MCP clients as they are.
 */
final class WebhookExceptionsTest extends TestCase
{
    public function testEachErrorExplainsItself(): void
    {
        self::assertSame('No such webhook delivery.', new UnknownWebhookDelivery()->getMessage());
        self::assertSame('No such webhook endpoint.', new UnknownWebhookEndpoint()->getMessage());
        self::assertSame('The webhook endpoint is disabled. Enable it first.', new WebhookEndpointDisabled()->getMessage());
        self::assertSame('You may not manage webhooks of events you may not read.', new WebhookNotAllowed()->getMessage());
        self::assertSame('Webhooks are managed by a signed-in person.', new WebhookNotAllowed('Webhooks are managed by a signed-in person.')->getMessage());
    }

    public function testMissingSecretKeysNameTheSettingAndKeepTheCause(): void
    {
        $cause = new \RuntimeException('no key');
        $e = new WebhookSecretsUnavailable($cause);

        self::assertStringContainsString('WEBHOOK_SECRET_KEYS', $e->getMessage());
        self::assertSame(0, $e->getCode());
        self::assertSame($cause, $e->getPrevious());
    }
}
