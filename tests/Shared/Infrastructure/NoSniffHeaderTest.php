<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NoSniffHeaderTest extends WebTestCase
{
    public function testEveryResponseForbidsContentTypeSniffing(): void
    {
        $client = self::createClient();

        foreach (['/login', '/_catalogue', '/nothing-here'] as $url) {
            $client->request('GET', $url);
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'), $url);
        }
    }
}
