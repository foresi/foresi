<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Api;

use App\Invoicing\Application\Query\InvoiceSliceFilter;
use App\Invoicing\Domain\DocumentKind;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/invoices. Every parameter narrows the list.
 */
final readonly class InvoiceListQuery
{
    public function __construct(
        #[Assert\Choice(choices: ['invoice', 'advance', 'payment_tax_document', 'correction_document'])]
        public ?string $kind = null,
        #[SerializedName('firm_id')]
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $firmId = null,
        #[SerializedName('customer_id')]
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $customerId = null,
        #[Assert\Choice(choices: InvoiceSliceFilter::STATES)]
        public ?string $state = null,
        public bool $trashed = false,
        #[SerializedName('issued_from')]
        #[Assert\Date]
        public ?string $issuedFrom = null,
        #[SerializedName('issued_to')]
        #[Assert\Date]
        public ?string $issuedTo = null,
        public string $q = '',
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
    }

    public function filter(): InvoiceSliceFilter
    {
        return new InvoiceSliceFilter(
            null === $this->kind ? null : DocumentKind::from($this->kind),
            null === $this->firmId ? null : strtolower($this->firmId),
            null === $this->customerId ? null : strtolower($this->customerId),
            $this->state,
            $this->trashed,
            null === $this->issuedFrom ? null : new \DateTimeImmutable($this->issuedFrom),
            null === $this->issuedTo ? null : new \DateTimeImmutable($this->issuedTo),
            trim($this->q),
        );
    }
}
