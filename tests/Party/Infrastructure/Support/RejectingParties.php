<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Support;

use Foresi\Party\Application\Parties;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Test double decorating the real Parties port: while switched on, storing a
 * party succeeds and then fails with a domain error, the way a rule that only
 * the domain knows (or a race) would. Test env only.
 */
final class RejectingParties implements Parties
{
    public static bool $reject = false;

    public function __construct(#[AutowireDecorated] private readonly Parties $inner)
    {
    }

    public function register(Party $party, PartyAddress $billing, array $emails): void
    {
        $this->inner->register($party, $billing, $emails);
        if (self::$reject) {
            throw new \DomainException('Rejected by the test double.');
        }
    }

    public function addEmail(PartyEmail $email): void
    {
        $this->inner->addEmail($email);
    }

    public function removeEmail(PartyEmail $email): void
    {
        $this->inner->removeEmail($email);
    }
}
