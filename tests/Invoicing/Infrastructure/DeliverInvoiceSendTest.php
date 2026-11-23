<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Foresi\Invoicing\Infrastructure\Messenger\MarkSendFailed;
use Foresi\Tests\Invoicing\Application\SendingTestCase;
use Symfony\Component\Yaml\Yaml;

final class DeliverInvoiceSendTest extends SendingTestCase
{
    public function testTheTriesOfASendAreTheMailTransportsTries(): void
    {
        /** @var array{framework: array{messenger: array{transports: array{mail: array{retry_strategy: array{max_retries: int}}}}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/src/Shared/config/packages/messenger.yaml');

        self::assertSame(MarkSendFailed::ATTEMPTS, 1 + $config['framework']['messenger']['transports']['mail']['retry_strategy']['max_retries']);
    }
}
