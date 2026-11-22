<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Activities;

use Foresi\Activities\Contract\ActivityTarget;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Activities about a contact of the address book, seen by the owners and
 * members who see the address book. An archived contact counts as gone: the
 * activity keeps the link, and is then seen through its other records or by
 * its owner alone.
 */
final readonly class ContactActivityTarget implements ActivityTarget
{
    public const string KIND = 'contact';

    public function __construct(private PartyDirectory $directory, private UrlGeneratorInterface $urls)
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

        return $contact instanceof PartySummary ? $this->urls->generate('app_contact_show', ['id' => $contact->id]) : null;
    }

    private function contact(string $kind, string $id): ?PartySummary
    {
        if (self::KIND !== $kind) {
            return null;
        }
        try {
            $contact = $this->directory->find($id);
        } catch (PartyNotAllowed) {
            // A guest sees no address book, so no activities through it.
            return null;
        }

        return !$contact instanceof PartySummary || $contact->archived ? null : $contact;
    }
}
