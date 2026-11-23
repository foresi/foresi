<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\View;

use Foresi\Purchasing\Application\Query\ReceivedDocumentFilter;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\InvalidFilter;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The received documents' views as the list's address names them (spec D10): the
 * person's views, the one ?view= names, or the ?tab= of the tabs it replaced (D12),
 * the list's All when it names none, and the filter the list shows, the view's own
 * unless f= changed it.
 *
 * The fields the sheet had are conditions now (D11): an old ?supplier=, ?kind= or
 * ?treatment= adds its condition to the working filter, so bookmarks still narrow, and so
 * does the month chip's ?month=, now the period quick filter.
 */
final readonly class ReceivedDocumentViews
{
    /** The parameters of the old sheet, by the field they now name. */
    public const array OLD_PARAMS = ['supplier' => ReceivedDocumentLists::SUPPLIER, 'kind' => ReceivedDocumentLists::KIND, 'treatment' => ReceivedDocumentLists::TREATMENT];

    public function __construct(private ReceivedDocumentLists $receivedLists, private ListViews $views, private ActorRole $actor)
    {
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    public function of(InputBag $input): array
    {
        $list = $this->receivedLists->list();
        $views = $this->views->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));
        $working = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        foreach (self::OLD_PARAMS as $param => $field) {
            $value = $input->getString($param);
            if ('' === $value) {
                continue;
            }
            $condition = new Condition($field, 'is', $value);
            try {
                ViewFilter::all($condition)->check($list);
                $working = $working->with($condition);
            } catch (InvalidFilter) {
                // A value the field does not take narrows nothing, as the sheet ignored it.
            }
        }

        $month = ReceivedDocumentFilter::monthValue(ReceivedDocumentFilter::monthOf($input->getString('month')));
        if (null !== $month) {
            $working = new ViewFilter(array_values(array_filter($working->conditions, static fn (Condition $condition): bool => ReceivedDocumentLists::PERIOD !== $condition->field)))
                ->with(new Condition(ReceivedDocumentLists::PERIOD, 'is', $month));
        }

        return [$views, $view, $working];
    }
}
