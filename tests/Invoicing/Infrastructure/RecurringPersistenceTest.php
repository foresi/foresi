<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Audit\Domain\AuditEntry;
use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\UnknownRecurringInvoice;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\RecurringState;
use App\Party\Application\TransactionalWork;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Invoicing\Application\RecurringTestCase;

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

    public function testAnotherTenantsTemplateIsUnknown(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->em->clear();
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        self::assertSame([], $this->templates->due(new \DateTimeImmutable('2027-12-31')));
        self::assertSame(0, $this->templates->page(new RecurringFilter(null, null, false, 1))->total);
        try {
            $this->templates->get($template->id());
            self::fail('Expected the template to be unknown.');
        } catch (UnknownRecurringInvoice) {
        }
        $this->expectException(UnknownRecurringInvoice::class);
        self::getContainer()->get(TransactionalWork::class)->run(fn (): RecurringInvoice => $this->templates->lock($template->id()));
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

        $all = $this->templates->page(new RecurringFilter(null, null, false, 1));
        self::assertSame(4, $all->total);
        self::assertSame(1, $all->pages);
        self::assertSame($ended->id(), $all->templates[3]->id(), 'ended templates come last');
        self::assertSame($active->id(), $all->templates[2]->id(), 'then by the next date');

        self::assertSame([$paused->id()], array_map(static fn (RecurringInvoice $template): string => $template->id(), $this->templates->page(new RecurringFilter(null, RecurringState::Paused, false, 1))->templates));
        self::assertSame([$active->id()], array_map(static fn (RecurringInvoice $template): string => $template->id(), $this->templates->page(new RecurringFilter(null, null, true, 1))->templates));
        self::assertSame(0, $this->templates->page(new RecurringFilter('00000000-0000-7000-8000-000000000000', null, false, 1))->total);
        self::assertSame(4, $this->templates->page(new RecurringFilter($this->firm->id(), null, false, 9))->total);
        self::assertSame(1, $this->templates->page(new RecurringFilter($this->firm->id(), null, false, 9))->page, 'a page out of range gives the nearest one');
        self::assertContains($soon->id(), array_map(static fn (RecurringInvoice $template): string => $template->id(), $all->templates));
    }
}
