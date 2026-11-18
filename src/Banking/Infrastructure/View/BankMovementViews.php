<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\View;

use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The bank screen's views as its address names them (spec D10): the list, the
 * person's views, the one ?view= names (All when none) and the filter the screen
 * shows, the view's own unless f= changed it. The ?tab= of the tabs the views
 * replaced still names one, and the ?account= of the select the filter sheet had
 * adds its condition (D11, D12), so old links and bookmarks keep working. The firm
 * select is gone with the one firm of an instance.
 */
final readonly class BankMovementViews
{
    public function __construct(private BankMovementLists $lists, private ListViews $views, private ActorRole $actor)
    {
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array{ListDefinition, list<ListView>, ListView, ViewFilter}
     */
    public function of(InputBag $input): array
    {
        $list = $this->lists->list();
        $views = $this->views->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));
        $working = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        $account = $input->getString(BankMovementLists::ACCOUNT);
        if (\in_array($account, $list->fields[BankMovementLists::ACCOUNT]->options ?? [], true)) {
            $working = $working->with(new Condition(BankMovementLists::ACCOUNT, 'is', $account));
        }

        return [$list, $views, $view, $working];
    }
}
