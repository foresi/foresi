<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Events;

use App\Party\Infrastructure\Api\ContactApiController;
use App\Shared\Application\Event\EventSubjectUrl;
use App\Shared\Domain\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a contact can be read in the API.
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
        return 'contact' === $subject->type ? $this->urls->generate(ContactApiController::SHOW, ['contactId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
