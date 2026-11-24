<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api;

use Foresi\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/received-documents. Every parameter narrows the list.
 */
final readonly class ReceivedDocumentListQuery
{
    public function __construct(
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
        // A saved view's id or system key, such as "purchasing.received.drafts" (spec D13).
        public ?string $view = null,
        // D4 JSON, {"conditions": [...]}, joined with the view's by AND.
        public ?string $filter = null,
        // Where the reading of the file stands, and how the document came in.
        #[Assert\Choice(choices: ['reading', 'read', 'nothing_found', 'failed'])]
        public ?string $reading = null,
        #[Assert\Choice(choices: ['manual', 'upload', 'api'])]
        public ?string $source = null,
    ) {
    }

    public function filter(?ViewFilter $view = null): ReceivedDocumentSliceFilter
    {
        return new ReceivedDocumentSliceFilter(
            null === $this->state ? null : ReceivedDocumentState::from($this->state),
            $this->unpaid,
            null === $this->dueBefore ? null : new \DateTimeImmutable($this->dueBefore),
            $this->trashed,
            null === $this->supplierId ? null : strtolower($this->supplierId),
            $this->narrowed($view),
        );
    }

    /**
     * The view with the reading and the source added as conditions of the list.
     */
    private function narrowed(?ViewFilter $view): ?ViewFilter
    {
        $conditions = array_filter([
            null === $this->reading ? null : new Condition(ReceivedDocumentLists::READING, 'is', $this->reading),
            null === $this->source ? null : new Condition(ReceivedDocumentLists::SOURCE, 'is', $this->source),
        ]);
        foreach ($conditions as $condition) {
            $view = ($view ?? ViewFilter::all())->with($condition);
        }

        return $view;
    }
}
