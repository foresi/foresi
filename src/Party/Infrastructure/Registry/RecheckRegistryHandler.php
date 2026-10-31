<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Registry;

use App\Audit\Application\AuditCorrelation;
use App\Party\Application\PartyQuery;
use App\Party\Application\RecheckRegistry;
use App\Party\Application\RefreshRegistryChecks;
use App\Party\Domain\Party;
use App\Party\Domain\VatId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Runs the register checks of one party in the background. The registers are
 * asked within the budget of outgoing calls the address book shares: a recheck
 * that finds less than HEADROOM calls left for a register waits a minute and
 * comes back, so a large import never uses up what people typing in the
 * contact form need.
 */
#[AsMessageHandler]
final readonly class RecheckRegistryHandler
{
    public const int RETRY_DELAY_MS = 60_000;
    public const int HEADROOM = 30;

    /** The registers a refresh asks; the keys of the outgoing call limiter. */
    private const array REGISTERS = ['vies', 'adis'];

    public function __construct(
        private PartyQuery $parties,
        private RefreshRegistryChecks $refresh,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private AuditCorrelation $correlation,
        #[Autowire(service: 'limiter.registry_outgoing')]
        private RateLimiterFactory $registryOutgoingLimiter,
    ) {
    }

    public function __invoke(RecheckRegistry $message): void
    {
        $party = $this->parties->find($message->partyId);
        if (!$party instanceof Party || !$party->vatId() instanceof VatId) {
            return;
        }
        if (!$this->hasBudget()) {
            $this->bus->dispatch($message, [new DelayStamp(self::RETRY_DELAY_MS)]);

            return;
        }

        $work = function () use ($party): void {
            $this->refresh->refresh($party);
            $this->em->flush();
        };
        if (null === $message->correlationId) {
            $work();
        } else {
            $this->correlation->within($message->correlationId, $work);
        }
    }

    private function hasBudget(): bool
    {
        return array_all(self::REGISTERS, fn (string $register): bool => $this->registryOutgoingLimiter->create($register)->consume(0)->getRemainingTokens() > self::HEADROOM);
    }
}
