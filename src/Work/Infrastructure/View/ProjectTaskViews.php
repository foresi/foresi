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
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\View\ProjectTaskLists;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A project's task views as the list's address names them (spec D10): the person's
 * views, the one ?view= names, the list's All when it names none, and the filter
 * the list shows, the view's own unless f= changed it.
 *
 * The filters the list had are conditions now (D11): an old ?responsible=, ?status=
 * or ?due= adds its condition to the working filter, so bookmarks still narrow.
 * ?status=open was the list's default and adds nothing; ?status=all asks for every
 * status. A value the old filter did not know narrows nothing, as it did not.
 */
final readonly class ProjectTaskViews
{
    /** The parameters of the old filters. */
    public const array OLD_PARAMS = [ProjectTaskLists::RESPONSIBLE => true, ProjectTaskLists::STATUS => true, ProjectTaskLists::DUE => true];

    public function __construct(private ProjectTaskLists $taskLists, private ListViews $views, private ActorRole $actor)
    {
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    public function of(InputBag $input): array
    {
        $list = $this->taskLists->list();
        $views = $this->views->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view'));
        $working = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        foreach (self::conditions($input->all()) as $condition) {
            $working = $working->with($condition);
        }

        return [$views, $view, $working];
    }

    /**
     * The conditions the old parameters of an address stand for.
     *
     * @param array<array-key, mixed> $query
     *
     * @return list<Condition>
     */
    public static function conditions(array $query): array
    {
        $param = static fn (string $name): string => \is_string($query[$name] ?? null) ? $query[$name] : '';
        $responsible = $param(ProjectTaskLists::RESPONSIBLE);
        $conditions = [];
        if (ProjectTaskLists::ME === $responsible || 1 === preg_match('/^'.Requirement::UUID.'$/D', $responsible)) {
            $conditions[] = new Condition(ProjectTaskLists::RESPONSIBLE, 'is', $responsible);
        }
        $conditions[] = match ($param(ProjectTaskLists::STATUS)) {
            ProjectTaskLists::FINISHED => new Condition(ProjectTaskLists::STATUS, 'is', ProjectTaskLists::FINISHED),
            'all' => new Condition(ProjectTaskLists::STATUS, 'is_any_of', [ProjectTaskLists::OPEN, ProjectTaskLists::FINISHED]),
            default => null,
        };
        $due = DueBucket::tryFrom($param(ProjectTaskLists::DUE));
        $conditions[] = $due instanceof DueBucket ? new Condition(ProjectTaskLists::DUE, 'is', $due->value) : null;

        return array_values(array_filter($conditions, static fn (?Condition $condition): bool => $condition instanceof Condition));
    }
}
