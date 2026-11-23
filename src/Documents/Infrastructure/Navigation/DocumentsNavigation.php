<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Navigation;

use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Documents\Infrastructure\Controller\DocumentController;
use Foresi\Documents\Infrastructure\Controller\DocumentSettingsController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Documents in the sidebar under Sales, after the invoices, as NDAs and
 * contracts are sales paperwork (P5 of the Sales and CRM plan), and their
 * types and templates under Settings. For the owners and members only.
 */
final readonly class DocumentsNavigation implements NavigationItemProvider
{
    public function __construct(private DocumentsAccess $access)
    {
    }

    public function items(): iterable
    {
        if (!$this->access->isStaff()) {
            return;
        }
        yield new NavigationItem(CoreNavigationAreas::SALES, 'nav.item.documents', 'file-text', DocumentController::LIST_ROUTE, weight: 30, commandLabelKey: 'documents.action.new', commandRoute: DocumentController::NEW_ROUTE, routePrefixes: [DocumentController::LIST_ROUTE, DocumentController::SHOW_ROUTE], section: CoreNavigationAreas::SECTION_SELLING);
        yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.document_settings', 'file-text', DocumentSettingsController::ROUTE, weight: 40, routePrefixes: [DocumentSettingsController::ROUTE], section: CoreNavigationAreas::SECTION_FIRM);
    }
}
