<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Ui\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Party\Application\DuplicateFinder;
use Foresi\Party\Application\RegistryRecord;
use Foresi\Party\Contract\ContactPages;
use Foresi\Party\Infrastructure\Registry\RegistryLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The registry lookup that fills a party's form: the firm's own settings and, with the
 * address book, a contact's form (the Stimulus controller party-lookup).
 */
final class RegistryLookupController extends AbstractController
{
    public function __construct(
        private readonly DuplicateFinder $duplicates,
        private readonly RegistryLookup $lookup,
        private readonly AuditActor $actor,
        #[Autowire(service: 'limiter.party_lookup')]
        private readonly RateLimiterFactory $lookupLimiter,
        private readonly ?ContactPages $contactPages = null,
    ) {
    }

    /**
     * Registry records for a registration number or the start of a name, with
     * the contact of this address book that already has the same number.
     */
    #[Route('/contacts/lookup', name: 'app_contact_lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        $limit = $this->lookupLimiter->create((string) ($this->actor->userId() ?? $request->getClientIp()))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retry]);
        }

        $results = [];
        foreach ($this->lookup->find($request->query->getString('q')) as $record) {
            $results[] = $this->record($record);
        }

        return new JsonResponse(['results' => $results]);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(RegistryRecord $record): array
    {
        $existing = $this->duplicates->find($record->registrationNumber, $record->vatId)[0] ?? null;
        $existingUrl = null === $existing ? null : $this->contactPages?->urlFor($existing->partyId);

        return [
            'registrationCountry' => $record->registrationNumber->countryCode,
            'registrationNumber' => $record->registrationNumber->value,
            'name' => $record->name,
            'vatId' => $record->vatId?->toString(),
            'street' => $record->seat->street ?? '',
            'city' => $record->seat->city ?? '',
            'postalCode' => $record->seat->postalCode ?? '',
            'countryCode' => $record->seat->countryCode ?? 'CZ',
            // Without the address book there is no contact page to link the match to.
            'existing' => null === $existingUrl ? null : [
                'name' => $existing->displayName,
                'url' => $existingUrl,
            ],
        ];
    }
}
