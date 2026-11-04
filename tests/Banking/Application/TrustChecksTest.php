<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\TrustCheck;
use App\Banking\Application\TrustChecks;
use App\Banking\Domain\InboundChannel;
use App\Tests\Banking\Support\InboundTestCase;
use App\Tests\Banking\Support\Scalars;

/**
 * The trust check of the last mail of the test feed, as received over the
 * webhook, whose receiving servers the test environment trusts are
 * mx.example.com and relay.example.net.
 */
final class TrustChecksTest extends InboundTestCase
{
    public function testATrustedTopmostServerIsNamedAndTrusted(): void
    {
        $check = $this->checkOf($this->notice('incoming-booked'));

        self::assertSame(InboundChannel::Webhook, $check->channel);
        self::assertSame('mx.example.com', $check->authservId);
        self::assertTrue($check->trusted);
    }

    public function testAnUntrustedTopmostServerIsNamedButNotTrusted(): void
    {
        $check = $this->checkOf("Authentication-Results: other.example.org; dmarc=pass header.from=kb.cz\n".$this->notice('incoming-booked', null));

        self::assertSame('other.example.org', $check->authservId);
        self::assertFalse($check->trusted);
    }

    public function testAMailWithoutResultsNamesNoServer(): void
    {
        $check = $this->checkOf($this->notice('incoming-booked', null));

        self::assertNull($check->authservId);
        self::assertFalse($check->trusted);
    }

    public function testALongIdIsCutToAHostNameOfWholeCharacters(): void
    {
        // Whatever the sender wrote is shown as text, so it is cut by characters, never inside one.
        $check = $this->checkOf('Authentication-Results: '.str_repeat('č', 300)."; none\n".$this->notice('incoming-booked', null));

        self::assertSame(str_repeat('č', 253), $check->authservId);
        self::assertFalse($check->trusted);
    }

    private function checkOf(string $raw): TrustCheck
    {
        $id = $this->received($raw);
        $feedId = Scalars::text($this->em->getConnection()->fetchOne('SELECT bank_feed_id FROM inbound_message WHERE id = ?', [$id]));
        $check = self::getContainer()->get(TrustChecks::class)->lastOf($feedId);
        self::assertInstanceOf(TrustCheck::class, $check);

        return $check;
    }
}
