<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\Routing;

use Foresi\Party\Contract\ContactPages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address book's pages of a contact, for the modules that link a party.
 */
final readonly class RoutedContactPages implements ContactPages
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function urlFor(string $id, array $query = []): string
    {
        return $this->urls->generate('app_contact_show', ['id' => $id] + $query);
    }

    public function editUrlFor(string $id): string
    {
        return $this->urls->generate('app_contact_edit', ['id' => $id]);
    }

    public function newUrl(array $query = []): string
    {
        return $this->urls->generate('app_contact_new', $query);
    }
}
