<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Api;

use App\Invoicing\Application\Query\PaymentSliceFilter;
use App\Invoicing\Domain\PaymentDirection;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/payments. Every parameter narrows the list.
 */
final readonly class PaymentListQuery
{
    public function __construct(
        #[SerializedName('firm_id')]
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $firmId = null,
        #[SerializedName('invoice_id')]
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $invoiceId = null,
        #[Assert\Choice(choices: ['incoming', 'refund'])]
        public ?string $direction = null,
        #[SerializedName('received_from')]
        #[Assert\Date]
        public ?string $receivedFrom = null,
        #[SerializedName('received_to')]
        #[Assert\Date]
        public ?string $receivedTo = null,
        #[SerializedName('include_cancelled')]
        public bool $includeCancelled = false,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
    }

    public function filter(): PaymentSliceFilter
    {
        return new PaymentSliceFilter(
            null === $this->firmId ? null : strtolower($this->firmId),
            null === $this->invoiceId ? null : strtolower($this->invoiceId),
            null === $this->direction ? null : PaymentDirection::from($this->direction),
            null === $this->receivedFrom ? null : new \DateTimeImmutable($this->receivedFrom),
            null === $this->receivedTo ? null : new \DateTimeImmutable($this->receivedTo),
            $this->includeCancelled,
        );
    }
}
