<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Domain\LinkAudience;
use PHPUnit\Framework\TestCase;

final class LinkTokensTest extends TestCase
{
    private const string SEND = '0190a0b4-0000-7000-8000-000000000001';

    public function testALinkStaysTheSameAcrossReleases(): void
    {
        // A link already delivered must keep leading to its document after an upgrade.
        $tokens = new LinkTokens('example-secret');

        self::assertSame('dciekx0sGfr-sQ81OxcT-tS-x_13X3ZV1bjE0-LFiaQ', $tokens->token(self::SEND, LinkAudience::Customer));
        self::assertSame('SjnvBwI0EU8tRIePnDAhFwRLWM2WqxY7FMmPLNOR6aw', $tokens->token(self::SEND, LinkAudience::Copy));
    }

    public function testATokenIsWellFormedAndHashedForLookup(): void
    {
        $tokens = new LinkTokens('example-secret');
        $token = $tokens->token(self::SEND, LinkAudience::Customer);

        self::assertTrue(LinkTokens::isWellFormed($token));
        self::assertFalse(LinkTokens::isWellFormed($token.'x'));
        self::assertSame(hash('sha256', $token), $tokens->hash($token));
        self::assertNotSame($token, new LinkTokens('other-secret')->token(self::SEND, LinkAudience::Customer));
    }

    public function testLinksNeedTheSecret(): void
    {
        $this->expectException(\LogicException::class);

        new LinkTokens('');
    }
}
