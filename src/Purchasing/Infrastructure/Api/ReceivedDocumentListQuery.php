<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api;

use Foresi\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/received-documents. Every parameter narrows the list.
 */
final readonly class ReceivedDocumentListQuery
{
    public function __construct(
        #[SerializedName('firm_id')]
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $firmId = null,
        #[SerializedName('supplier_id')]
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $supplierId = null,
        #[Assert\Choice(choices: ['draft', 'recorded'])]
        public ?string $state = null,
        public bool $unpaid = false,
        #[SerializedName('due_before')]
        #[Assert\Date]
        public ?string $dueBefore = null,
        public bool $trashed = false,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
    }

    public function filter(): ReceivedDocumentSliceFilter
    {
        return new ReceivedDocumentSliceFilter(
            null === $this->firmId ? null : strtolower($this->firmId),
            null === $this->state ? null : ReceivedDocumentState::from($this->state),
            $this->unpaid,
            null === $this->dueBefore ? null : new \DateTimeImmutable($this->dueBefore),
            $this->trashed,
            null === $this->supplierId ? null : strtolower($this->supplierId),
        );
    }
}
