<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Application\PurgeRecurringTrashDaily;
use Foresi\Invoicing\Application\PurgeTrashedRecurring;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Invoicing\Application\RecurringTestCase;
use Symfony\Component\Clock\Clock;

/**
 * A recurring template that never issued goes to the trash instead of being removed:
 * paused there, so nothing issues it, out of every list but the trash, back paused on
 * restore and removed for good 30 days after it was deleted.
 */
final class RecurringTrashTest extends RecurringTestCase
{
    public function testDeleteMovesATemplateThatNeverIssuedToTheTrashPaused(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'One'), $this->line(text: 'Two')]));

        $this->lifecycle->delete($template->id());

        $row = $this->templateRow($template->id());
        self::assertNotNull($row['deleted_at']);
        self::assertSame('paused', $row['state']);
        self::assertCount(2, $this->lineRows($template->id()), 'its lines stay, for a restore');
        self::assertSame([], $this->templates->due(new \DateTimeImmutable('2027-01-20')), 'a template in the trash is never due');
        self::assertSame([], $this->listed(new ViewFilter()), 'the list leaves it out');
        self::assertSame([], $this->listed(null, true), 'so does the cash-flow forecast, which reads the active ones');
        self::assertSame([$template->id()], $this->listed(ViewFilter::all(new Condition(RecurringLists::TRASHED, 'is', true))));
    }

    public function testATemplateThatIssuedIsOnlyEnded(): void
    {
        $issued = $this->save->create($this->recurringInput());
        $this->advance($issued->id(), '2026-12-01');

        self::assertSame(['recurring.problem.issued'], $this->recurringRefusalOf(fn () => $this->lifecycle->delete($issued->id())));
        $row = $this->templateRow($issued->id());
        self::assertNull($row['deleted_at']);
        self::assertSame('active', $row['state']);
    }

    public function testNothingButRestoreWorksInTheTrash(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->delete($template->id());
        $this->em->clear();

        foreach ([
            'resume' => fn (): array => $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2026-12-01')),
            'pause' => fn () => $this->lifecycle->pause($template->id()),
            'end' => fn () => $this->lifecycle->end($template->id()),
            'delete' => fn () => $this->lifecycle->delete($template->id()),
            'edit' => fn (): RecurringInvoice => $this->save->edit($template->id(), $this->templates->get($template->id())->version(), $this->recurringInput()),
        ] as $action => $call) {
            self::assertSame(['recurring.problem.trashed'], $this->recurringRefusalOf($call), $action);
        }
        self::assertNull(self::getContainer()->get(IssueRecurringOccurrence::class)->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01')), 'issue now issues nothing');
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM invoice WHERE recurring_invoice_id = :id', ['id' => $template->id()]));
    }

    public function testRestoreBringsItBackPausedSoTheDatesInTheTrashAreSkipped(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->delete($template->id());

        self::mockTime('2027-01-20 11:00:00');
        $this->lifecycle->restore($template->id());

        $row = $this->templateRow($template->id());
        self::assertNull($row['deleted_at']);
        self::assertNull($row['deleted_by']);
        self::assertSame('paused', $row['state']);
        self::assertSame([], $this->templates->due(new \DateTimeImmutable('2027-01-20')), 'restored, it still issues nothing');
        self::assertSame([$template->id()], $this->listed(new ViewFilter()));

        $skipped = $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2027-01-20'));
        self::assertSame(['2026-12-01', '2027-01-01'], array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $skipped));
        self::assertSame('2027-02-01', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testAnEndedTemplateComesBackEnded(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->end($template->id());
        $this->lifecycle->delete($template->id());

        $this->lifecycle->restore($template->id());

        self::assertSame('ended', $this->templateRow($template->id())['state']);
    }

    public function testThePurgeRemovesWhatWasDeletedMoreThan30DaysAgo(): void
    {
        $old = $this->save->create($this->recurringInput());
        $this->lifecycle->delete($old->id());
        self::mockTime('2026-11-12 11:00:00');
        $recent = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-12-15')]));
        $this->lifecycle->delete($recent->id());
        $live = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-12-15')]));
        self::mockTime('2026-12-03 11:00:00');

        $job = new PurgeRecurringTrashDaily(self::getContainer()->get(PurgeTrashedRecurring::class), Clock::get());
        self::assertSame('recurring-trash-purge', $job->name());
        self::assertSame('2026-12-02', $job->period(new \DateTimeImmutable('2026-12-03 01:59:00 UTC'), new \DateTimeZone('Europe/Prague')));
        self::assertSame('2026-12-03', $job->period(new \DateTimeImmutable('2026-12-03 02:00:00 UTC'), new \DateTimeZone('Europe/Prague')));
        $job->run(null);

        $connection = $this->em->getConnection();
        self::assertFalse($connection->fetchOne('SELECT id FROM recurring_invoice WHERE id = :id', ['id' => $old->id()]));
        self::assertSame([], $this->lineRows($old->id()));
        self::assertNotNull($this->templateRow($recent->id())['deleted_at']);
        self::assertNull($this->templateRow($live->id())['deleted_at']);
        self::assertSame(0, self::getContainer()->get(PurgeTrashedRecurring::class)->run(new \DateTimeImmutable('2026-12-03 11:00:00')));
    }

    /**
     * @return list<string> the ids the list shows
     */
    private function listed(?ViewFilter $view, bool $active = false): array
    {
        $this->em->clear();
        $page = $this->templates->page(new RecurringFilter($active ? RecurringState::Active : null, false, 1, $view));

        return array_map(static fn (RecurringInvoice $template): string => $template->id(), $page->templates);
    }

    private function advance(string $id, string $occurrence): void
    {
        self::getContainer()->get(TransactionalWork::class)->run(function () use ($id, $occurrence): void {
            $this->templates->lock($id)->advance(new \DateTimeImmutable($occurrence));
        });
    }
}
