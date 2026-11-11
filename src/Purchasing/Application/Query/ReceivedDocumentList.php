<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Party\Application\LegalEntities;
use App\Purchasing\Application\PurchasingAccess;
use App\Purchasing\Application\PurchasingNotAllowed;

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
        private LegalEntities $firms,
    ) {
    }

    /**
     * A firm or a supplier the organisation does not know is no filter at all,
     * and a page past the last is the last.
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function page(ReceivedDocumentFilter $filter): ReceivedDocumentPage
    {
        $this->access->denyUnlessStaff();
        $firms = [];
        foreach ($this->firms->all() as $firm) {
            $firms[$firm->id()] = $firm->name();
        }
        $firmId = null !== $filter->firmId && isset($firms[$filter->firmId]) ? $filter->firmId : null;
        $suppliers = $this->queries->suppliers($firmId);
        $supplierId = null !== $filter->supplierId && isset($suppliers[$filter->supplierId]) ? $filter->supplierId : null;
        $filter = new ReceivedDocumentFilter($filter->tab, $firmId, $filter->month, $supplierId, $filter->kind, $filter->treatment, trim(mb_substr($filter->text, 0, ReceivedDocumentFilter::MAX_TEXT)), $filter->page);

        $total = $this->queries->count($filter);
        $counts = [];
        foreach (ReceivedDocumentFilter::TABS as $tab) {
            $counts[$tab] = $tab === $filter->tab ? $total : $this->queries->count($filter->onTab($tab));
        }
        $pages = max(1, (int) ceil($total / ReceivedDocumentFilter::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        return new ReceivedDocumentPage(
            $filter,
            $this->queries->rows($filter, ($page - 1) * ReceivedDocumentFilter::PER_PAGE, ReceivedDocumentFilter::PER_PAGE),
            $total,
            $page,
            $pages,
            $counts,
            $firms,
            $suppliers,
            $this->queries->months($firmId),
        );
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
     * The firms that take documents, by name.
     *
     * @return array<string, string> name by id
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function activeFirms(): array
    {
        $this->access->denyUnlessStaff();
        $firms = [];
        foreach ($this->firms->active() as $firm) {
            $firms[$firm->id()] = $firm->name();
        }

        return $firms;
    }
}
