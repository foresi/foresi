<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Target;

use Foresi\Meetings\Contract\MeetingTarget;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Contract\ContactPages;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Domain\Id;

/**
 * A meeting about a contact of the address book, such as a client met or a
 * person from outside the firm who attended. An archived contact counts as
 * gone: the link stays and shows nothing. Without the address book the contact is
 * named, not linked (ContactPages).
 */
final readonly class ContactMeetingTarget implements MeetingTarget
{
    public const string KIND = 'contact';

    public function __construct(private PartyDirectory $directory, private ?ContactPages $pages = null)
    {
    }

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function canSee(string $kind, string $id): bool
    {
        return $this->contact($kind, $id) instanceof PartySummary;
    }

    public function canLink(string $kind, string $id): bool
    {
        return $this->canSee($kind, $id);
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->contact($kind, $id)?->displayName;
    }

    public function url(string $kind, string $id): ?string
    {
        $contact = $this->contact($kind, $id);

        return $contact instanceof PartySummary ? $this->pages?->urlFor($contact->id) : null;
    }

    private function contact(string $kind, string $id): ?PartySummary
    {
        if (self::KIND !== $kind || !Id::isValid($id)) {
            return null;
        }
        try {
            $contact = $this->directory->find($id);
        } catch (PartyNotAllowed) {
            return null;
        }

        return !$contact instanceof PartySummary || $contact->archived ? null : $contact;
    }
}
