<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Mcp;

use App\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use App\Tests\Webhooks\Support\WebhookApi;

/**
 * A webhook tool called as the MCP server calls it, next to the REST API of
 * the same endpoints, to compare what both answer.
 */
abstract class WebhookToolTestCase extends ReadToolTestCase
{
    use WebhookApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebhookApi();
    }
}
