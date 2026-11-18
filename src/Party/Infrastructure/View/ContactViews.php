<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\View;

use Foresi\Party\Application\View\ContactLists;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The address book's views as its address names them (spec D10): the person's views,
 * the one ?view= names (its All when none), and the filter the list shows, the view's
 * own unless f= changed it. The list and its export read them alike, so an export
 * holds exactly what the list shows.
 */
final readonly class ContactViews
{
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
        $views = $this->views->of(ContactLists::list(), $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view'));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), ContactLists::list()) ?? $view->filter];
    }
}
