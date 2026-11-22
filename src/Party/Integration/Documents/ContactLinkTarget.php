<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Documents;

use Foresi\Documents\Contract\DocumentLinkTarget;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Documents linked to a contact of the address book. An archived contact counts
 * as gone: the document keeps the link and shows it as unavailable.
 */
final readonly class ContactLinkTarget implements DocumentLinkTarget
{
    public const string KIND = 'contact';

    public function __construct(private PartyDirectory $directory, private UrlGeneratorInterface $urls)
    {
    }

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function exists(string $kind, string $id): bool
    {
        return $this->contact($kind, $id) instanceof PartySummary;
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
        $contact = $this->directory->find($id);

        return !$contact instanceof PartySummary || $contact->archived ? null : $contact;
    }
}
