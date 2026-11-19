<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\View;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueues;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Infrastructure\Controller\InvoiceListController;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The list a tax document waits in, as a tab of the list it belongs to: "Needs tax
 * document" after the views of the advances, "Waiting for correction" after those
 * of the tax documents. A queue of work sits among the views of its list, as
 * Shopify's Unfulfilled, Xero's Awaiting payment and HubSpot's task views do, and
 * its page shows the same tabs with its own one active, so it reads as a part of
 * that list rather than as a place of its own.
 */
final readonly class TaxDocumentQueueTabs
{
    /** The queue of each kind of document, and its route and label. */
    private const array QUEUES = [
        'advance' => [TaxDocumentQueueLists::MISSING, 'app_advances_missing', 'advance.missing.tab'],
        'payment_tax_document' => [TaxDocumentQueueLists::UNCORRECTED, 'app_tax_documents_uncorrected', 'tax_document.uncorrected.tab'],
    ];

    public function __construct(
        private TaxDocumentQueues $queues,
        private InvoiceList $list,
        private ListViews $views,
        private InstanceClock $clock,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The tab of the queue of a kind's list, with how many wait in it; none for a
     * kind that has no queue.
     *
     * @return array<string, mixed>|null
     */
    public function of(DocumentKind $kind, bool $active = false, bool $modified = false): ?array
    {
        if (!isset(self::QUEUES[$kind->value])) {
            return null;
        }
        [$key, $route, $label] = self::QUEUES[$kind->value];

        return [
            'label' => $this->translator->trans($label),
            'href' => $this->urls->generate($route),
            'count' => \count($this->queues->rows($key, $this->clock->today())),
            'active' => $active,
            'modified' => $modified,
        ];
    }

    /**
     * The tabs of the list a queue belongs to, as its page shows them: the list's
     * primary views with their counts, then the queue's tab, active while the queue
     * shows its All; the list's other views go in "More views".
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    public function parentOf(DocumentKind $kind, ?string $userId, bool $active, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($this->views->of(InvoiceLists::of($kind), $userId));
        $link = fn (ListView $view, bool $counted): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            'href' => $this->urls->generate(InvoiceListController::ROUTES[$kind->value], $view->isAll() ? [] : ['view' => $view->key]),
            // Counted as the list counts its tabs without a search.
            'count' => $counted && $view->showCount ? $this->list->count(new InvoiceSliceFilter($kind, view: $view->filter)) : null,
            'active' => false,
            'private' => $view->private,
        ];
        $tabs = array_map(static fn (ListView $view): array => $link($view, true), $primary);
        $queue = $this->of($kind, $active, $modified);
        if (null !== $queue) {
            $tabs[] = $queue;
        }

        return ['tabs' => $tabs, 'moreViews' => array_map(static fn (ListView $view): array => $link($view, false), $overflow)];
    }
}
