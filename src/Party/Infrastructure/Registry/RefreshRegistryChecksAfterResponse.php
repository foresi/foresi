<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Registry;

use App\Party\Application\PartyQuery;
use App\Party\Application\RefreshRegistryChecks;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Saving a contact never waits for the public registers, which can take
 * seconds to answer. The controller only notes which party to check; the
 * checks run once the response has been sent, and their outcome is stored
 * with the time of the check.
 */
final readonly class RefreshRegistryChecksAfterResponse
{
    private const string ATTRIBUTE = '_party_refresh_checks';

    public function __construct(
        private PartyQuery $parties,
        private RefreshRegistryChecks $refresh,
        private TenantContext $context,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public static function schedule(Request $request, string $partyId): void
    {
        $request->attributes->set(self::ATTRIBUTE, $partyId);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_string($id) || !$this->context->has() || !$this->em->isOpen()) {
            return;
        }

        try {
            $party = $this->parties->find($id);
            if ($party instanceof \App\Party\Domain\Party) {
                $this->refresh->refresh($party);
                $this->em->flush();
            }
        } catch (\Throwable $failure) {
            // The response is gone already; the contact stays as saved and the checks can be repeated by hand.
            $this->logger->error('The registry checks of a saved contact failed.', ['exception' => $failure]);
        }
    }
}
