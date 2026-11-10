<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Api;

use App\Party\Application\Query\ContactSliceFilter;
use App\Party\Domain\PartyRole;
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
    ) {
    }

    public function filter(): ContactSliceFilter
    {
        return new ContactSliceFilter(
            trim($this->q),
            null === $this->role ? null : PartyRole::from($this->role),
            $this->archived,
            $this->registrationNumber,
            $this->vatId,
        );
    }
}
