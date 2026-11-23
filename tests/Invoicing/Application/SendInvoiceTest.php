<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Infrastructure\Mail\RouterDocumentLinks;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SendInvoiceTest extends SendingTestCase
{
    public function testMailLinksUseTheConfiguredBase(): void
    {
        $links = new RouterDocumentLinks(self::getContainer()->get(UrlGeneratorInterface::class), 'https://invoices.example.com/app', false);

        self::assertSame('https://invoices.example.com/app/d/'.str_repeat('a', 43), $links->url(str_repeat('a', 43)));
    }
}
