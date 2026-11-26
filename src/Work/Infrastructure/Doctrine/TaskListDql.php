<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskStar;
use Foresi\Work\Domain\TaskWatcher;

/**
 * The conditions, the sort and the groups of the list of all tasks (TaskLists) in DQL, on
 * the aliases of FROM: t the task, s its status, p its project, c the project's client, r
 * the responsible membership, u its user and pa the task a subtask is of. The web list and GET /api/v1/tasks read
 * through it, so a view holds the same tasks in both.
 *
 * "Me" names the membership of the person looking, bound as :me; one who has none is no
 * one's "me", so a condition on them matches no task. Starred and the sort by my order are
 * about the stars of the person looking, never anyone else's. A label in the trash labels nothing.
 * A finished task is in a due bucket only by its date: overdue is past and not finished.
 */
final readonly class TaskListDql
{
    public const string FROM = ' FROM Foresi\Work\Domain\Task t JOIN t.status s JOIN t.project p LEFT JOIN p.client c LEFT JOIN t.responsible r LEFT JOIN r.user u LEFT JOIN t.parent pa';

    /** The columns of the fields DqlConditions compares as they are stored. */
    private const array COLUMNS = [
        TaskLists::TITLE => 't.title',
        TaskLists::PROJECT => 'IDENTITY(t.project)',
        TaskLists::CLIENT => 'IDENTITY(p.client)',
        TaskLists::RESPONSIBLE => 'IDENTITY(t.responsible)',
        TaskLists::STATUS_TYPE => 's.type',
        TaskLists::PRIORITY => 't.priority',
        TaskLists::DUE_ON => 't.dueOn',
        TaskLists::CREATED => 't.createdAt',
        TaskLists::UPDATED => 't.updatedAt',
    ];
    /** No membership has this id: what "me" names for one who has none. */
    private const string NO_ONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(private ListDefinition $list, private ?Membership $me, private \DateTimeImmutable $today)
    {
    }

    /**
     * The DQL of a filter's conditions and their parameters, :me among them where a condition
     * names the person looking.
     *
     * @param bool $openUnlessAsked whether a finished task is left out unless a condition asks about the status type
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    public function where(ViewFilter $filter, bool $openUnlessAsked, string $prefix = 'tl'): array
    {
        $filter = new ViewFilter(array_map($this->resolved(...), $filter->conditions));
        $derived = fn (Condition $condition, string $name): array => $this->derived($condition, $name);
        [$where, $parameters] = new DqlConditions($this->list, self::COLUMNS, $derived)->compile($filter, fn (): \DateTimeImmutable => $this->today, $prefix);
        if ($openUnlessAsked && [] === $filter->on(TaskLists::STATUS_TYPE)) {
            $where .= ' AND s.type IN (:'.$prefix.'_open)';
            $parameters[$prefix.'_open'] = [self::unfinished(), ArrayParameterType::STRING];
        }
        if (str_contains($where, ':me')) {
            $parameters += $this->meParameter();
        }

        return [$where, $parameters];
    }

    /**
     * The parameter that names the person looking, as a condition or the sort by my order reads it.
     *
     * @return array<string, array{mixed, null}>
     */
    public function meParameter(): array
    {
        return ['me' => [$this->me?->id() ?? self::NO_ONE, null]];
    }

    /**
     * The expressions a sort orders by, each with its direction, the ties broken by the
     * due date (the undated last), the highest priority, the newest and the id.
     *
     * @return list<array{string, string}> DQL expressions with ASC or DESC
     */
    public static function order(string $sort): array
    {
        $field = ltrim($sort, '-');
        $dir = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
        $priority = self::priorityRank();
        $order = match ($field) {
            // The undated last whichever way: Linear and Asana keep tasks with no date at the end.
            TaskLists::SORT_DUE => [['CASE WHEN t.dueOn IS NULL THEN 1 ELSE 0 END', 'ASC'], ['t.dueOn', $dir]],
            TaskLists::SORT_PRIORITY => [[$priority, $dir]],
            TaskLists::SORT_CREATED => [['t.createdAt', $dir]],
            TaskLists::SORT_UPDATED => [['t.updatedAt', $dir]],
            TaskLists::SORT_PROJECT => [['p.name', $dir]],
            TaskLists::SORT_TITLE => [['t.title', $dir]],
            // The starred in the person's order, the rest after them whichever way.
            TaskLists::SORT_MY_ORDER => [
                [\sprintf('CASE WHEN EXISTS (SELECT so_e.id FROM %s so_e WHERE so_e.task = t AND so_e.membership = :me) THEN 0 ELSE 1 END', TaskStar::class), 'ASC'],
                [\sprintf('(SELECT so_p.position FROM %s so_p WHERE so_p.task = t AND so_p.membership = :me)', TaskStar::class), $dir],
            ],
            default => [],
        };
        $ties = [['CASE WHEN t.dueOn IS NULL THEN 1 ELSE 0 END', 'ASC'], ['t.dueOn', 'ASC'], [$priority, 'DESC'], ['t.createdAt', 'DESC'], ['t.id', 'DESC']];

        return [...$order, ...$ties];
    }

    /**
     * What orders the groups of a grouping, the tasks of no group last.
     *
     * @return list<array{string, string}>
     */
    public static function groupOrder(string $group): array
    {
        return match ($group) {
            TaskLists::GROUP_CLIENT => [['CASE WHEN c.id IS NULL THEN 1 ELSE 0 END', 'ASC'], ['c.displayName', 'ASC'], ['c.id', 'ASC']],
            TaskLists::GROUP_PROJECT => [['p.name', 'ASC'], ['p.id', 'ASC']],
            TaskLists::GROUP_RESPONSIBLE => [['CASE WHEN r.id IS NULL THEN 1 ELSE 0 END', 'ASC'], ['u.displayName', 'ASC'], ['r.id', 'ASC']],
            TaskLists::GROUP_PRIORITY => [[self::priorityRank(), 'DESC']],
            TaskLists::GROUP_STATUS_TYPE => [[self::statusRank(), 'ASC']],
            TaskLists::GROUP_DUE => [[self::dueRank(), 'ASC']],
            default => [],
        };
    }

    /**
     * The expression that names a task's group, as the counts read it: the id of its client,
     * project or responsible, its priority, its status type, or the rank of its due bucket.
     */
    public static function groupKey(string $group): ?string
    {
        return match ($group) {
            TaskLists::GROUP_CLIENT => 'IDENTITY(p.client)',
            TaskLists::GROUP_PROJECT => 'p.id',
            TaskLists::GROUP_RESPONSIBLE => 'IDENTITY(t.responsible)',
            TaskLists::GROUP_PRIORITY => 't.priority',
            TaskLists::GROUP_STATUS_TYPE => 's.type',
            TaskLists::GROUP_DUE => self::dueRank(),
            default => null,
        };
    }

    /**
     * The parameters the due ranks read: today and the last of the next seven days.
     *
     * @return array<string, array{mixed, string|null}>
     */
    public function dueParameters(): array
    {
        return ['tl_today' => [$this->today, Types::DATE_IMMUTABLE], 'tl_week' => [$this->today->modify('+7 days'), Types::DATE_IMMUTABLE]];
    }

    /**
     * The due buckets in order, as dueRank() numbers them, by the keys of TaskGroup.
     */
    public const array DUE_GROUPS = ['overdue', 'today', 'upcoming', 'later', 'no_date'];

    private static function dueRank(): string
    {
        return 'CASE WHEN t.dueOn IS NULL THEN 4 WHEN t.dueOn < :tl_today THEN 0 WHEN t.dueOn = :tl_today THEN 1 WHEN t.dueOn <= :tl_week THEN 2 ELSE 3 END';
    }

    private static function priorityRank(): string
    {
        $cases = array_map(static fn (Priority $priority): string => \sprintf("WHEN t.priority = '%s' THEN %d", $priority->value, $priority->rank()), Priority::cases());

        return 'CASE '.implode(' ', $cases).' ELSE 0 END';
    }

    private static function statusRank(): string
    {
        $cases = array_map(static fn (StatusType $type, int $rank): string => \sprintf("WHEN s.type = '%s' THEN %d", $type->value, $rank), StatusType::cases(), array_keys(StatusType::cases()));

        return 'CASE '.implode(' ', $cases).' ELSE 9 END';
    }

    /**
     * A condition with "me" as the membership of the person looking, on the fields of people.
     */
    private function resolved(Condition $condition): Condition
    {
        if (!\in_array($condition->field, [TaskLists::RESPONSIBLE, TaskLists::CREATED_BY], true)) {
            return $condition;
        }
        $me = $this->me?->id() ?? self::NO_ONE;
        $value = \is_array($condition->value)
            ? array_map(static fn (mixed $one): mixed => TaskLists::ME === $one ? $me : $one, $condition->value)
            : (TaskLists::ME === $condition->value ? $me : $condition->value);

        return new Condition($condition->field, $condition->op, $value);
    }

    /**
     * The fields DqlConditions cannot compare as stored.
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function derived(Condition $condition, string $name): array
    {
        $values = \is_array($condition->value) ? array_values($condition->value) : (null === $condition->value ? [] : [$condition->value]);

        return match ($condition->field) {
            TaskLists::INVOLVEMENT => [DqlConditions::derivedEnum($condition, [
                MyTaskLists::RESPONSIBLE => 't.responsible = :me',
                MyTaskLists::WATCHER => \sprintf('EXISTS (SELECT w_%1$s.id FROM %2$s w_%1$s WHERE w_%1$s.task = t AND w_%1$s.membership = :me)', $name, TaskWatcher::class),
            ]), []],
            TaskLists::DUE => $this->due($condition, $name),
            TaskLists::LABELS => $this->labels($condition->op, $values, $name),
            TaskLists::CREATED_BY => $this->createdBy($condition->op, $values, $name),
            TaskLists::STARRED => [DqlConditions::derivedBoolean($condition, \sprintf('EXISTS (SELECT st_%1$s.id FROM %2$s st_%1$s WHERE st_%1$s.task = t AND st_%1$s.membership = :me)', $name, TaskStar::class)), []],
            default => throw new \LogicException(\sprintf('The field "%s" of the tasks has no DQL.', $condition->field)),
        };
    }

    /**
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function due(Condition $condition, string $name): array
    {
        $today = ':'.$name.'_today';
        $predicates = [
            DueBucket::Overdue->value => 't.dueOn < '.$today.' AND s.type IN (:'.$name.'_open)',
            DueBucket::Today->value => 't.dueOn = '.$today,
            DueBucket::NextSevenDays->value => 't.dueOn >= '.$today.' AND t.dueOn <= :'.$name.'_week',
            DueBucket::None->value => 't.dueOn IS NULL',
        ];
        $dql = DqlConditions::derivedEnum($condition, array_map(static fn (string $predicate): string => '('.$predicate.')', $predicates));
        $parameters = [];
        if (str_contains($dql, $today)) {
            $parameters[$name.'_today'] = [$this->today, Types::DATE_IMMUTABLE];
        }
        if (str_contains($dql, ':'.$name.'_week')) {
            $parameters[$name.'_week'] = [$this->today->modify('+7 days'), Types::DATE_IMMUTABLE];
        }
        if (str_contains($dql, ':'.$name.'_open')) {
            $parameters[$name.'_open'] = [self::unfinished(), ArrayParameterType::STRING];
        }
        // A task with no date is never before nor after a date: the negation of a bucket keeps it.
        if (\in_array($condition->op, ['is_not', 'is_none_of'], true) && !\in_array(DueBucket::None->value, (array) $condition->value, true)) {
            $dql = '(t.dueOn IS NULL OR '.$dql.')';
        }

        return [$dql, $parameters];
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function labels(string $op, array $ids, string $name): array
    {
        $labelled = \sprintf('SELECT tl_%1$s.id FROM %2$s tl_%1$s JOIN tl_%1$s.label l_%1$s WHERE tl_%1$s.task = t AND l_%1$s.deletedAt IS NULL', $name, TaskLabel::class);
        $parameters = [$name => [array_values(array_filter($ids, \is_string(...))), ArrayParameterType::STRING]];
        $with = $labelled.' AND l_'.$name.'.id IN (:'.$name.')';

        return match ($op) {
            'is', 'is_any_of' => ['EXISTS ('.$with.')', $parameters],
            'is_not' => ['NOT EXISTS ('.$with.')', $parameters],
            'is_empty' => ['NOT EXISTS ('.$labelled.')', []],
            default => ['EXISTS ('.$labelled.')', []],
        };
    }

    /**
     * Who created a task is a user; the builder picks a person, a membership of one.
     *
     * @param list<mixed> $ids
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function createdBy(string $op, array $ids, string $name): array
    {
        $users = \sprintf('SELECT IDENTITY(m_%1$s.user) FROM %2$s m_%1$s WHERE m_%1$s.id IN (:%1$s)', $name, Membership::class);
        $parameters = [$name => [array_values(array_filter($ids, \is_string(...))), ArrayParameterType::STRING]];

        return match ($op) {
            'is', 'is_any_of' => ['t.createdBy IN ('.$users.')', $parameters],
            'is_not' => ['t.createdBy NOT IN ('.$users.')', $parameters],
            // Every task has its creator.
            'is_empty' => ['1 = 0', []],
            default => ['1 = 1', []],
        };
    }

    /**
     * @return list<string>
     */
    public static function unfinished(): array
    {
        return array_values(array_map(static fn (StatusType $type): string => $type->value, array_filter(StatusType::cases(), static fn (StatusType $type): bool => !$type->isFinished())));
    }
}
