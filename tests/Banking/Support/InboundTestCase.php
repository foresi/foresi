<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Support;

/**
 * The banking test firm with an inbound address on its account, and synthetic
 * Komerční banka notices to it (InboundFixtures).
 */
abstract class InboundTestCase extends BankingTestCase
{
    use InboundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInbound();
    }
}
