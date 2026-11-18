<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Webhooks\Infrastructure\Api;

use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Tests\Webhooks\Support\WebhookApi;

/**
 * A test case of the webhook API: see WebhookApi.
 */
abstract class WebhookApiTestCase extends ApiTestCase
{
    use WebhookApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebhookApi();
    }
}
