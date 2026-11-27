<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Twig;

use Foresi\Party\Contract\ContactPages;
use Twig\Attribute\AsTwigFunction;

/**
 * The pages of the address book for the templates of other modules: contact_url(id),
 * contact_edit_url(id) and contact_new_url(query), each null without the address book,
 * so a template links a party only when there is a page to link to.
 */
final readonly class ContactPageFunctions
{
    public function __construct(private ?ContactPages $pages = null)
    {
    }

    /**
     * @param array<string, string> $query
     */
    #[AsTwigFunction('contact_url')]
    public function url(?string $id, array $query = []): ?string
    {
        return null === $id || '' === $id ? null : $this->pages?->urlFor($id, $query);
    }

    #[AsTwigFunction('contact_edit_url')]
    public function editUrl(?string $id): ?string
    {
        return null === $id || '' === $id ? null : $this->pages?->editUrlFor($id);
    }

    /**
     * @param array<string, string> $query
     */
    #[AsTwigFunction('contact_new_url')]
    public function newUrl(array $query = []): ?string
    {
        return $this->pages?->newUrl($query);
    }
}
