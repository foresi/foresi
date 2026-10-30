<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Support;

use App\Party\Application\Parties;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
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
