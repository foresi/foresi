<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api;

use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Shared\Application\View\ViewFilter;
use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/invoices. Every parameter narrows the list; view and filter
 * (spec D13) are read by InvoiceViewFilter and joined by AND with the others.
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
        #[OA\Property(description: 'The id of a saved view of a list of documents, or the system key of one a module ships, such as invoicing.invoices.overdue: only the documents it holds. Needs the views:read scope too.')]
        public ?string $view = null,
        #[OA\Property(description: 'Filter conditions as JSON, {"conditions": [{"field", "op", "value"}]}, joined by AND; the fields of each list are in GET /api/v1/lists.')]
        public ?string $filter = null,
    ) {
    }

    /**
     * @param DocumentKind|null $kind the kind of the view's list, when the query names no kind
     */
    public function filter(?ViewFilter $view = null, ?DocumentKind $kind = null): InvoiceSliceFilter
    {
        return new InvoiceSliceFilter(
            null === $this->kind ? $kind : DocumentKind::from($this->kind),
            null === $this->firmId ? null : strtolower($this->firmId),
            null === $this->customerId ? null : strtolower($this->customerId),
            $this->state,
            $this->trashed,
            null === $this->issuedFrom ? null : new \DateTimeImmutable($this->issuedFrom),
            null === $this->issuedTo ? null : new \DateTimeImmutable($this->issuedTo),
            trim($this->q),
            $view,
        );
    }
}
