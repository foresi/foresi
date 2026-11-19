<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\View;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\View\MyTaskLists;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * My tasks' views as the list's address names them (spec D10): the
 * person's views, the one ?view= names, or the ?tab= of the tabs it replaced (D12),
 * the list's All when it names none, and the filter the list shows, the view's own
 * unless f= changed it.
 *
 * The client select of the sheet is a condition now (D11): an old ?client= adds
 * its condition to the working filter, so bookmarks still narrow.
 */
final readonly class MyTaskViews
{
    /** The parameters of the old sheet, by the field they now name. */
    public const array OLD_PARAMS = ['client' => MyTaskLists::CLIENT];

    public function __construct(private ListViews $views, private ActorRole $actor)
    {
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    public function of(InputBag $input): array
    {
        $list = MyTaskLists::list();
        $views = $this->views->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));
        $working = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        $client = $input->getString('client');
        // A contact is named by its id; anything else names no client, as the sheet ignored it.
        if (Id::isValid($client)) {
            $working = $working->with(new Condition(MyTaskLists::CLIENT, 'is', $client));
        }

        return [$views, $view, $working];
    }
}
