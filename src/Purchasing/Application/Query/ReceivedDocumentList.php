<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The lists of received documents for the screens, the API and MCP alike: a
 * page of a tab with its counts, and the count of the drafts that wait to be
 * completed. Only the owners and members of the organisation read them.
 */
final readonly class ReceivedDocumentList
{
    public function __construct(
        private PurchasingAccess $access,
        private ReceivedDocumentQueries $queries,
        private InstanceFirm $firm,
    ) {
    }

    /**
     * A page past the last is the last.
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function page(ReceivedDocumentFilter $filter): ReceivedDocumentPage
    {
        $this->access->denyUnlessStaff();
        $filter = new ReceivedDocumentFilter($filter->tab, $filter->month, trim(mb_substr($filter->text, 0, ReceivedDocumentFilter::MAX_TEXT)), $filter->page, $filter->view, $filter->counted);

        [$total, $counts] = $this->queries->counts($filter);
        $pages = max(1, (int) ceil($total / ReceivedDocumentFilter::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        return new ReceivedDocumentPage(
            $filter,
            $this->queries->rows($filter, ($page - 1) * ReceivedDocumentFilter::PER_PAGE, ReceivedDocumentFilter::PER_PAGE),
            $total,
            $page,
            $pages,
            $counts,
            $this->queries->months(),
        );
    }

    /**
     * How many documents a view holds, of every month and text.
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function count(ViewFilter $view): int
    {
        $this->access->denyUnlessStaff();

        return $this->queries->counts(new ReceivedDocumentFilter(view: $view))[0];
    }

    /**
     * The drafts that wait to be completed, for the badge of the sidebar.
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function drafts(): int
    {
        $this->access->denyUnlessStaff();

        return $this->queries->drafts();
    }

    /**
     * Whether the instance has its firm (ADR 0039): none before the first-run setup.
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function hasFirm(): bool
    {
        $this->access->denyUnlessStaff();

        return $this->firm->find() instanceof \Foresi\Party\Domain\LegalEntity;
    }
}
