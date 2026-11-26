<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Audit\Domain\AuditEntry;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Tests\Invoicing\Application\RecurringTestCase;

final class RecurringPersistenceTest extends RecurringTestCase
{
    public function testAuditHasOneRootPerTemplate(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'One'), $this->line(text: 'Two')]));
        $other = $this->save->create($this->recurringInput());
        $this->em->clear();
        $this->save->edit($template->id(), $template->version(), $this->recurringInput([$this->line(text: 'Changed')], ['description' => 'Edited']));
        $this->em->clear();

        /** @var list<AuditEntry> $entries */
        $entries = $this->em->createQuery('SELECT a FROM '.AuditEntry::class.' a WHERE a.entityClass IN (:classes) ORDER BY a.occurredAt, a.id')
            ->setParameter('classes', [RecurringInvoice::class, RecurringInvoiceLine::class])
            ->getResult();

        $lines = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM recurring_invoice_line WHERE recurring_invoice_id = :id', ['id' => $template->id()]);
        $roots = [];
        foreach ($entries as $entry) {
            self::assertSame(RecurringInvoice::class, $entry->rootClass());
            $roots[$entry->rootId()][] = $entry->entityClass();
            if (RecurringInvoiceLine::class === $entry->entityClass() && \in_array($entry->entityId(), $lines, true)) {
                self::assertSame($template->id(), $entry->rootId());
            }
        }
        self::assertSame([$template->id(), $other->id()], array_keys($roots));
        // Created with two lines; the edit changed the template and the first line and removed the second.
        $counts = array_count_values($roots[$template->id()]);
        self::assertSame(2, $counts[RecurringInvoice::class]);
        self::assertSame(4, $counts[RecurringInvoiceLine::class]);
        self::assertSame([RecurringInvoice::class => 1, RecurringInvoiceLine::class => 1], array_count_values($roots[$other->id()]));
        $versionChanges = array_filter($entries, static fn (AuditEntry $entry): bool => isset($entry->changes()['version']));
        self::assertSame([], $versionChanges, 'the version is technical');
    }

    public function testThePageFiltersByFirmStateAndFailure(): void
    {
        $active = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-12-15')]));
        $soon = $this->save->create($this->recurringInput());
        $paused = $this->save->create($this->recurringInput(), startPaused: true);
        $ended = $this->save->create($this->recurringInput());
        $this->lifecycle->end($ended->id());
        self::getContainer()->get(TransactionalWork::class)->run(function () use ($active): void {
            $this->templates->lock($active->id())->recordFailure(new \DateTimeImmutable('2026-12-15 05:00:00'), 'no_mail_server');
        });

        $all = $this->templates->page(new RecurringFilter(null, false, 1));
        self::assertSame(4, $all->total);
        self::assertSame(1, $all->pages);
        self::assertSame($ended->id(), $all->templates[3]->id(), 'ended templates come last');
        self::assertSame($active->id(), $all->templates[2]->id(), 'then by the next date');

        self::assertSame([$paused->id()], array_map(static fn (RecurringInvoice $template): string => $template->id(), $this->templates->page(new RecurringFilter(RecurringState::Paused, false, 1))->templates));
        self::assertSame([$active->id()], array_map(static fn (RecurringInvoice $template): string => $template->id(), $this->templates->page(new RecurringFilter(null, true, 1))->templates));
        self::assertSame(4, $this->templates->page(new RecurringFilter(null, false, 9))->total);
        self::assertSame(1, $this->templates->page(new RecurringFilter(null, false, 9))->page, 'a page out of range gives the nearest one');
        self::assertContains($soon->id(), array_map(static fn (RecurringInvoice $template): string => $template->id(), $all->templates));
    }
}
