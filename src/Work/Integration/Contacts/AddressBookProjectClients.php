<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Contacts;

use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Contract\ContactPages;
use Foresi\Work\Contract\ProjectClients;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The client of a project where the address book is installed: any contact of it,
 * picked from ProjectClientsController and linked to its page.
 */
#[AsAlias(ProjectClients::class)]
final readonly class AddressBookProjectClients implements ProjectClients
{
    public function __construct(private UrlGeneratorInterface $urls, private PartyQuery $parties, private ContactPages $pages)
    {
    }

    public function pickerUrl(): string
    {
        return $this->urls->generate(ProjectClientsController::ROUTE);
    }

    public function name(string $id): ?string
    {
        return $this->parties->find($id)?->displayName();
    }

    public function pageUrl(string $id): ?string
    {
        return $this->pages->urlFor($id);
    }
}
