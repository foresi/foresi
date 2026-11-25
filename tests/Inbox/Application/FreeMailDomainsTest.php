<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\FreeMailDomains;
use PHPUnit\Framework\TestCase;

final class FreeMailDomainsTest extends TestCase
{
    public function testKnowsTheFreeProvidersWithoutRegardToCase(): void
    {
        foreach (['seznam.cz', 'Email.cz', 'gmail.com', 'outlook.com', 'icloud.com', 'proton.me', '@post.cz', 'centrum.cz.'] as $domain) {
            self::assertTrue(FreeMailDomains::isFree($domain), $domain);
        }
    }

    public function testGmxUnderAnyTopLevelDomain(): void
    {
        foreach (['gmx.com', 'gmx.net', 'gmx.de', 'gmx.at', 'gmx.ch', 'gmx.co.uk'] as $domain) {
            self::assertTrue(FreeMailDomains::isFree($domain), $domain);
        }
        self::assertFalse(FreeMailDomains::isFree('gmx.example.com'));
        self::assertFalse(FreeMailDomains::isFree('mygmx.com'));
    }

    public function testAFirmsDomainIsNotFree(): void
    {
        foreach (['example.com', 'client-a.example.com', 'mail.seznam.cz.example.com', 'gmail.com.example.com', ''] as $domain) {
            self::assertFalse(FreeMailDomains::isFree($domain), $domain);
        }
    }
}
