<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\UntrustedText;
use PHPUnit\Framework\TestCase;

final class UntrustedTextTest extends TestCase
{
    public function testAnAddressIsFollowedByItsDomainInBrackets(): void
    {
        self::assertSame('Open https://Pay.Example.com/a?b=1 [pay.example.com]. Thanks', UntrustedText::shown('Open https://Pay.Example.com/a?b=1. Thanks'));
    }

    public function testTextWithoutAddressesIsKeptAndLineEndingsNormalised(): void
    {
        self::assertSame("one\ntwo <b>x</b>", UntrustedText::shown("one\r\ntwo <b>x</b>"));
    }
}
