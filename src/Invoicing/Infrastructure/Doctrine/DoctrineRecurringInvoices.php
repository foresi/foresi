<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Application\UnknownRecurringInvoice;
use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Domain\SendStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;

/**
 * The lock is the write that changes nothing of DoctrineInvoiceStore, for the same
 * reason: it works alike on SQLite and PostgreSQL.
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 */
final readonly class DoctrineRecurringInvoices implements RecurringInvoices
{
    private const string LOCK = 'UPDATE recurring_invoice SET version = version WHERE id = :id';

    public function __construct(private RecurringLists $recurringLists, private EntityManagerInterface $em, private InstanceClock $clock)
    {
    }

    public function get(string $id): RecurringInvoice
    {
        // A query, not find(): find() answers from the identity map.
        $template = $this->em->createQuery('SELECT r FROM '.RecurringInvoice::class.' r WHERE r.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $template instanceof RecurringInvoice ? $template : throw new UnknownRecurringInvoice();
    }

    public function current(string $id): RecurringInvoice
    {
        $this->refusePendingChanges();
        $this->em->clear();

        return $this->get($id);
    }

    public function lock(string $id): RecurringInvoice
    {
        $this->refusePendingChanges();
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id]);
        if (1 !== $locked) {
            throw new UnknownRecurringInvoice();
        }
        // What this process remembers may be older than the lock.
        $this->em->clear();

        return $this->get($id);
    }

    public function lockDuringWrite(string $id): RecurringInvoice
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id]);
        if (1 !== $locked) {
            throw new UnknownRecurringInvoice();
        }
        // A query answers from the identity map, and refresh() cannot write readonly
        // fields, so a template loaded before the lock leaves the session first, with
        // its lines, which would otherwise point to an object the session no longer knows.
        $work = $this->em->getUnitOfWork();
        foreach ($work->getIdentityMap()[RecurringInvoiceLine::class] ?? [] as $line) {
            if ($line instanceof RecurringInvoiceLine && $line->template()->id() === $id) {
                $this->em->detach($line);
            }
        }
        $loaded = $work->tryGetById($id, RecurringInvoice::class);
        if ($loaded instanceof RecurringInvoice) {
            $this->em->detach($loaded);
        }

        return $this->get($id);
    }

    public function occurrenceIssued(string $id, \DateTimeImmutable $occurrence): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM invoice WHERE recurring_invoice_id = :id AND recurring_occurrence = :occurrence',
            ['id' => $id, 'occurrence' => $occurrence->format('Y-m-d')],
        );
    }

    public function add(RecurringInvoice $template): void
    {
        $this->em->persist($template);
    }

    public function remove(RecurringInvoice $template): void
    {
        // What an untouched import carried over was issued elsewhere.
        if ($template->isIssued() && !$template->isUntouchedImport()) {
            throw new \LogicException('A template that issued an invoice is ended, never removed.');
        }
        $this->em->remove($template);
    }

    public function trashedBefore(\DateTimeImmutable $moment): array
    {
        /** @var list<RecurringInvoice> $templates */
        $templates = $this->em->createQuery('SELECT r FROM '.RecurringInvoice::class.' r WHERE r.deletedAt IS NOT NULL AND r.deletedAt < :moment ORDER BY r.deletedAt ASC, r.id ASC')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->getResult();

        return $templates;
    }

    public function lines(RecurringInvoice $template): array
    {
        /** @var list<RecurringInvoiceLine> $lines */
        $lines = $this->em->createQuery('SELECT l FROM '.RecurringInvoiceLine::class.' l WHERE l.template = :template ORDER BY l.position, l.id')
            ->setParameter('template', $template->id())
            ->getResult();

        return $lines;
    }

    public function addLine(RecurringInvoiceLine $line): void
    {
        $this->em->persist($line);
    }

    public function removeLine(RecurringInvoiceLine $line): void
    {
        $this->em->remove($line);
    }

    public function due(\DateTimeImmutable $today): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQuery('SELECT r.id FROM '.RecurringInvoice::class.' r WHERE r.state = :active AND r.deletedAt IS NULL AND r.nextOccurrence <= :today ORDER BY r.id')
            ->setParameter('active', RecurringState::Active->value)
            ->setParameter('today', new \DateTimeImmutable($today->format('Y-m-d')), Types::DATE_IMMUTABLE)
            ->getSingleColumnResult();

        return $ids;
    }

    public function page(RecurringFilter $filter): RecurringPage
    {
        $conditions = ['1 = 1'];
        $parameters = [];
        if ($filter->state instanceof RecurringState) {
            $conditions[] = 'r.state = :state';
            $parameters['state'] = [$filter->state->value, null];
        }
        if ($filter->failing) {
            $conditions[] = RecurringFailing::dql('r');
            $parameters += self::failingParameters();
        }
        $where = implode(' AND ', $conditions);
        $today = $this->today();

        // One query counts the views the list shows a count for, and the one it lists (spec D9).
        $sums = [];
        $counting = $parameters;
        foreach ([...array_values($filter->counted), $filter->view ?? new ViewFilter()] as $index => $view) {
            [$condition, $bound] = $this->inView($view, 'c'.$index, $today);
            $sums[] = 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END)';
            $counting += $bound;
        }
        /** @var list<numeric-string|int|null> $results */
        $results = array_values((array) $this->bind($this->em->createQuery('SELECT '.implode(', ', $sums).' FROM '.RecurringInvoice::class.' r WHERE '.$where), $counting)->getSingleResult(Query::HYDRATE_ARRAY));
        $values = array_map(static fn (int|string|null $value): int => (int) $value, $results);
        $total = (int) array_pop($values);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($filter->page, $pages));
        [$condition, $bound] = $this->inView($filter->view ?? new ViewFilter(), 'l', $today);

        // Whether each template is failing now comes with it, so the rows need no query of their own.
        $descending = str_starts_with($filter->sort, '-');
        $order = RecurringFilter::NAME === ltrim($filter->sort, '-') ? 'LOWER(r.name)'.($descending ? ' DESC' : '').', r.nextOccurrence' : 'without_next'.($descending ? ' DESC' : '').', r.nextOccurrence'.($descending ? ' DESC' : '');
        $list = $this->em->createQuery(
            'SELECT r, CASE WHEN r.nextOccurrence IS NULL THEN 1 ELSE 0 END AS HIDDEN without_next, CASE WHEN '.RecurringFailing::dql('r', 'now').' THEN 1 ELSE 0 END AS failing_now'
            .' FROM '.RecurringInvoice::class.' r WHERE '.$where.' AND '.$condition.' ORDER BY '.$order.', r.id',
        );
        $this->bind($list, $parameters + $bound + self::failingParameters());
        /** @var list<array{0: RecurringInvoice, failing_now: int|string}> $found */
        $found = $list->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();
        $templates = [];
        $failing = [];
        foreach ($found as $row) {
            $templates[] = $row[0];
            if (1 === (int) $row['failing_now']) {
                $failing[] = $row[0]->id();
            }
        }

        return new RecurringPage($templates, $total, $page, $pages, $failing, array_combine(array_map(strval(...), array_keys($filter->counted)), $values));
    }

    public function failing(): int
    {
        $query = $this->em->createQuery('SELECT COUNT(r.id) FROM '.RecurringInvoice::class.' r WHERE '.RecurringFailing::dql('r'));
        RecurringFailing::bind($query);

        return (int) $query->getSingleScalarResult();
    }

    public function inReview(): int
    {
        // Plain SQL, so the key of occurrences, led by the template, finds the invoices.
        // To send, as the list's filter has it: neither marked delivered nor on its way, and not in the trash.
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM invoice i WHERE i.recurring_invoice_id IS NOT NULL AND i.deleted_at IS NULL AND i.delivered_at IS NULL'
            .' AND NOT EXISTS (SELECT 1 FROM invoice_send s WHERE s.invoice_id = i.id AND s.purpose = :purpose AND s.status IN (:queued, :sent))',
            ['purpose' => SendPurpose::Invoice->value, 'queued' => SendStatus::Queued->value, 'sent' => SendStatus::Sent->value],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function refusePendingChanges(): void
    {
        $work = $this->em->getUnitOfWork();
        $work->computeChangeSets();
        if ([] !== $work->getScheduledEntityInsertions() || [] !== $work->getScheduledEntityUpdates() || [] !== $work->getScheduledEntityDeletions()) {
            throw new \LogicException('Store pending changes before working on a recurring invoice.');
        }
    }

    /**
     * The templates a view holds; each view under its own prefix, so one count query
     * holds many side by side. The state, delivery, next issue and name are columns;
     * failing is the SQL the tab counted with. A view shows the trash only when it
     * asks for it, as the invoice list's does.
     *
     * @param \Closure(): \DateTimeImmutable $today
     *
     * @return array{string, Parameters}
     */
    private function inView(ViewFilter $view, string $prefix, \Closure $today): array
    {
        $derived = static fn (Condition $condition, string $name): array => match ($condition->field) {
            RecurringLists::FAILING => [DqlConditions::derivedBoolean($condition, RecurringFailing::dql('r', $name)), self::failingParameters()],
            RecurringLists::TRASHED => [DqlConditions::derivedBoolean($condition, 'r.deletedAt IS NOT NULL'), []],
            default => throw new \LogicException(\sprintf('The field "%s" of the recurring invoices has no SQL.', $condition->field)),
        };

        [$condition, $parameters] = new DqlConditions($this->recurringLists->list(), [
            RecurringLists::STATE => 'r.state',
            RecurringLists::DELIVERY => 'r.delivery',
        ], $derived(...))->compile($view, $today, $prefix);

        return [([] === $view->on(RecurringLists::TRASHED) ? 'r.deletedAt IS NULL AND ' : '').$condition, $parameters];
    }

    /**
     * The parameters of RecurringFailing::dql(), as RecurringFailing::bind() sets them.
     *
     * @return Parameters
     */
    private static function failingParameters(): array
    {
        return [
            'failingEnded' => [RecurringState::Ended->value, null],
            'failingPurpose' => [SendPurpose::Invoice->value, null],
            'failingDelivering' => [[SendStatus::Queued->value, SendStatus::Sent->value], ArrayParameterType::STRING],
        ];
    }

    /**
     * The instance's today for relative dates, read once and only when a date needs it.
     *
     * @return \Closure(): \DateTimeImmutable
     */
    private function today(): \Closure
    {
        $day = null;

        return function () use (&$day): \DateTimeImmutable {
            return $day ??= $this->clock->today();
        };
    }

    /**
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult> $query
     * @param Parameters           $parameters
     *
     * @return Query<TKey, TResult>
     */
    private function bind(Query $query, array $parameters): Query
    {
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        return $query;
    }
}
