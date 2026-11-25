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

    public function testAnAddressIsShownWholeUpToTwoHundredCharactersCountedAsCharacters(): void
    {
        $exact = 'https://x.example.com/'.str_repeat('a', 200 - \strlen('https://x.example.com/'));
        $wide = 'https://x.example.com/'.str_repeat('ž', 150);

        self::assertSame($exact.' [x.example.com]', UntrustedText::shown($exact));
        self::assertSame($wide.' [x.example.com]', UntrustedText::shown($wide), 'Characters, not bytes.');
    }

    public function testALongerAddressIsCutAtTwoHundredCharactersWithAnEllipsis(): void
    {
        $long = 'https://x.example.com/'.str_repeat('ž', 300);

        self::assertSame(mb_substr($long, 0, 200).'… [x.example.com]', UntrustedText::shown($long));
    }

    public function testTheDomainIsShownInLowerCaseBeyondAscii(): void
    {
        self::assertSame('https://ŽLUTÁ.example.com/x [žlutá.example.com]', UntrustedText::shown('https://ŽLUTÁ.example.com/x'));
    }

    public function testAnAddressWithoutADomainIsShownWithAQuestionMark(): void
    {
        self::assertSame('See http://:80 [?] now', UntrustedText::shown('See http://:80 now'));
    }
}
