<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Work\Application\WorkScope;
use App\Work\Domain\ProjectVisibility;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;

/**
 * WorkScope in DQL: the joins and the condition that keep a query to what the
 * person sees, the same rule as WorkScope answers in PHP. Everything needs
 * neither; an empty set of shares is a condition nothing meets, never an
 * empty IN list.
 *
 * The joins use aliases of their own, starting with "vs", so they never meet
 * those of the query they are added to.
 */
final readonly class ScopedDql
{
    /**
     * @param array<string, array{mixed, ArrayParameterType|string}> $parameters
     */
    private function __construct(public string $joins, public string $where, private array $parameters)
    {
    }

    /**
     * The tasks seen, on the alias of the task in the query.
     */
    public static function tasks(WorkScope $scope, string $task): self
    {
        if ($scope->isEverything()) {
            return new self('', '', []);
        }
        $parameters = ['vs_no' => [false, Types::BOOLEAN]];
        $shared = self::in($task.'.id', 'vs_tasks', $scope->taskIds(), $parameters);
        $sharedParent = self::in('vst.id', 'vs_tasks', $scope->taskIds(), $parameters);
        $open = self::opens($scope, 'vsp', $parameters);
        $inSection = self::in('vss.id', 'vs_sections', $scope->sectionIds(), $parameters);
        $inParentSection = self::in('vsts.id', 'vs_sections', $scope->sectionIds(), $parameters);

        // A subtask follows its task, unless it is private itself and not shared.
        return new self(
            sprintf(' JOIN %1$s.project vsp LEFT JOIN %1$s.section vss LEFT JOIN %1$s.parent vst LEFT JOIN vst.section vsts', $task),
            sprintf(
                ' AND ((vst.id IS NULL AND %s) OR (vst.id IS NOT NULL AND (%s OR (%s.private = :vs_no AND %s))))',
                self::top($task, 'vss', $shared, $inSection, $open),
                $shared,
                $task,
                self::top('vst', 'vsts', $sharedParent, $inParentSection, $open),
            ),
            $parameters,
        );
    }

    /**
     * A task that is not a subtask: shared, or not private and in a section
     * shared, or in a section that is not private or in none, of a project open
     * to the person.
     */
    private static function top(string $task, string $section, string $shared, string $inSection, string $open): string
    {
        return sprintf(
            '(%1$s OR (%2$s.private = :vs_no AND (%4$s OR ((%3$s.id IS NULL OR %3$s.private = :vs_no) AND %5$s))))',
            $shared,
            $task,
            $section,
            $inSection,
            $open,
        );
    }

    /**
     * The projects seen, on the alias of the project in the query.
     */
    public static function projects(WorkScope $scope, string $project): self
    {
        if ($scope->isEverything()) {
            return new self('', '', []);
        }
        $parameters = [];
        $open = self::opens($scope, $project, $parameters);

        return new self('', sprintf(' AND (%s OR %s)', $open, self::in($project.'.id', 'vs_section_projects', $scope->sectionProjectIds(), $parameters)), $parameters);
    }

    /**
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult> $query
     *
     * @return Query<TKey, TResult>
     */
    public function apply(Query $query): Query
    {
        foreach ($this->parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        return $query;
    }

    /**
     * Whether everything not private in the project is seen.
     *
     * @param array<string, array{mixed, ArrayParameterType|string}> $parameters
     */
    private static function opens(WorkScope $scope, string $project, array &$parameters): string
    {
        $shared = self::in($project.'.id', 'vs_projects', $scope->projectIds(), $parameters);
        if (!$scope->seesOrganisation()) {
            return $shared;
        }
        $parameters['vs_organisation'] = [ProjectVisibility::Organisation->value, Types::STRING];

        return sprintf('(%s.visibility = :vs_organisation OR %s)', $project, $shared);
    }

    /**
     * @param list<string>                                           $ids
     * @param array<string, array{mixed, ArrayParameterType|string}> $parameters
     */
    private static function in(string $field, string $name, array $ids, array &$parameters): string
    {
        if ([] === $ids) {
            return '1 = 0';
        }
        $parameters[$name] = [$ids, ArrayParameterType::STRING];

        return sprintf('%s IN (:%s)', $field, $name);
    }
}
