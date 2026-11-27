<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Events;

use Foresi\Party\Infrastructure\Api\FirmApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the firm can be read in the API. A contact's events are the address book's
 * (ContactsSubjectUrls).
 */
final readonly class PartySubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'party';
    }

    public function url(EventSubject $subject): ?string
    {
        return match ($subject->type) {
            'firm' => $this->urls->generate(FirmApiController::SHOW, [], UrlGeneratorInterface::ABSOLUTE_PATH),
            default => null,
        };
    }
}
