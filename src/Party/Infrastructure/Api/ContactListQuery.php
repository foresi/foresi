<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api;

use Foresi\Party\Application\Query\ContactSliceFilter;
use Foresi\Party\Domain\PartyRole;
use Foresi\Shared\Application\View\ViewFilter;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/contacts. Every parameter narrows the list.
 */
final readonly class ContactListQuery
{
    public function __construct(
        public string $q = '',
        #[Assert\Choice(choices: ['customer', 'supplier', 'partner', 'authority'])]
        public ?string $role = null,
        public bool $archived = false,
        #[SerializedName('registration_number')]
        public ?string $registrationNumber = null,
        #[SerializedName('vat_id')]
        public ?string $vatId = null,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
        // A saved view's id or system key, such as "party.contacts.customers" (spec D13).
        public ?string $view = null,
        // D4 JSON, {"conditions": [...]}, joined with the view's by AND.
        public ?string $filter = null,
    ) {
    }

    public function filter(?ViewFilter $view = null): ContactSliceFilter
    {
        return new ContactSliceFilter(
            trim($this->q),
            null === $this->role ? null : PartyRole::from($this->role),
            $this->archived,
            $this->registrationNumber,
            $this->vatId,
            $view,
        );
    }
}
