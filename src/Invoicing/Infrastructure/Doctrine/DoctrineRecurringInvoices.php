<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringPage;
use App\Invoicing\Application\UnknownRecurringInvoice;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\SendPurpose;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * The lock is the write that changes nothing of DoctrineInvoiceStore, for the same
 * reasons: it works alike on SQLite and PostgreSQL, and it names the tenant itself,
 * as the tenant filter covers only queries of the ORM.
 */
final readonly class DoctrineRecurringInvoices implements RecurringInvoices
{
    private const string LOCK = 'UPDATE recurring_invoice SET version = version WHERE id = :id AND tenant_id = :tenant';

    public function __construct(private EntityManagerInterface $em, private TenantContext $context)
    {
    }

    public function get(string $id): RecurringInvoice
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
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
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
        if (1 !== $locked) {
            throw new UnknownRecurringInvoice();
        }
        // What this process remembers may be older than the lock.
        $this->em->clear();

        return $this->get($id);
    }

    public function lockDuringWrite(string $id): RecurringInvoice
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
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
        // Plain SQL: the tenant filter covers only queries of the ORM, so the tenant is named here.
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM invoice WHERE tenant_id = :tenant AND recurring_invoice_id = :id AND recurring_occurrence = :occurrence',
            ['tenant' => $this->context->current()->toString(), 'id' => $id, 'occurrence' => $occurrence->format('Y-m-d')],
        );
    }

    public function add(RecurringInvoice $template): void
    {
        $this->em->persist($template);
    }

    public function remove(RecurringInvoice $template): void
    {
        if ($template->isIssued()) {
            throw new \LogicException('A template that issued an invoice is ended, never removed.');
        }
        $this->em->remove($template);
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
        $ids = $this->em->createQuery('SELECT r.id FROM '.RecurringInvoice::class.' r WHERE r.state = :active AND r.nextOccurrence <= :today ORDER BY r.id')
            ->setParameter('active', RecurringState::Active->value)
            ->setParameter('today', new \DateTimeImmutable($today->format('Y-m-d')), Types::DATE_IMMUTABLE)
            ->getSingleColumnResult();

        return $ids;
    }

    public function page(RecurringFilter $filter): RecurringPage
    {
        $conditions = ['1 = 1'];
        $parameters = [];
        if (null !== $filter->firmId) {
            $conditions[] = 'r.firm = :firm';
            $parameters['firm'] = $filter->firmId;
        }
        if ($filter->state instanceof RecurringState) {
            $conditions[] = 'r.state = :state';
            $parameters['state'] = $filter->state->value;
        }
        if ($filter->failing) {
            $conditions[] = 'r.lastFailureCode IS NOT NULL AND r.state <> :ended';
            $parameters['ended'] = RecurringState::Ended->value;
        }
        $where = implode(' AND ', $conditions);

        $count = $this->em->createQuery('SELECT COUNT(r.id) FROM '.RecurringInvoice::class.' r WHERE '.$where);
        $this->bind($count, $parameters);
        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        $list = $this->em->createQuery(
            'SELECT r, CASE WHEN r.nextOccurrence IS NULL THEN 1 ELSE 0 END AS HIDDEN without_next FROM '.RecurringInvoice::class.' r WHERE '.$where.' ORDER BY without_next, r.nextOccurrence, r.id',
        );
        $this->bind($list, $parameters);
        /** @var list<RecurringInvoice> $templates */
        $templates = $list->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        return new RecurringPage($templates, $total, $page, $pages);
    }

    public function failing(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(r.id) FROM '.RecurringInvoice::class.' r WHERE r.lastFailureCode IS NOT NULL AND r.state <> :ended')
            ->setParameter('ended', RecurringState::Ended->value)
            ->getSingleScalarResult();
    }

    public function inReview(): int
    {
        // Plain SQL: the tenant filter covers only queries of the ORM, so the tenant is named here,
        // which also lets the key of occurrences, led by tenant and template, find the invoices.
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM invoice i WHERE i.tenant_id = :tenant AND i.recurring_invoice_id IS NOT NULL AND i.deleted_at IS NULL'
            .' AND NOT EXISTS (SELECT 1 FROM invoice_send s WHERE s.invoice_id = i.id AND s.purpose = :purpose)',
            ['tenant' => $this->context->current()->toString(), 'purpose' => SendPurpose::Invoice->value],
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
     * @param Query<mixed, mixed>   $query
     * @param array<string, string> $parameters
     */
    private function bind(Query $query, array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
    }
}
