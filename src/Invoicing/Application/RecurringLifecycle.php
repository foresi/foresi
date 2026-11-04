<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Pausing, resuming, ending and deleting a recurring template, each under its
 * lock, so a run issuing it meanwhile finishes first or finds the change. Nothing
 * is issued after a pause commits. Ending is final. Only a template that never
 * issued may be deleted; one that did is ended instead, so its invoices keep
 * where they came from.
 */
final readonly class RecurringLifecycle
{
    public function __construct(
        private RecurringInvoices $templates,
        private TransactionalWork $work,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Repeatable.
     *
     * @throws RecurringRefused        once ended
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function pause(string $id): void
    {
        $this->work->run(function () use ($id): void {
            $template = $this->unended($id);
            $template->pause($this->now());
        });
    }

    /**
     * Starts again from the first date on or after $today. The dates that fell in
     * the pause are not issued; they come back, so a dialog can name them.
     *
     * @param \DateTimeImmutable $today the tenant's today
     *
     * @return list<\DateTimeImmutable> the skipped dates, oldest first
     *
     * @throws RecurringRefused        once ended
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function resume(string $id, \DateTimeImmutable $today): array
    {
        return $this->work->run(fn (): array => $this->unended($id)->resume($today));
    }

    /**
     * Repeatable.
     *
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function end(string $id): void
    {
        $this->work->run(function () use ($id): void {
            $this->templates->lock($id)->end($this->now());
        });
    }

    /**
     * @throws RecurringRefused        once the template issued an invoice
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function delete(string $id): void
    {
        $this->work->run(function () use ($id): void {
            $template = $this->templates->lock($id);
            if ($template->isIssued()) {
                throw RecurringRefused::because('state', 'recurring.problem.issued');
            }
            foreach ($this->templates->lines($template) as $line) {
                $this->templates->removeLine($line);
            }
            $this->templates->remove($template);
        });
    }

    private function unended(string $id): RecurringInvoice
    {
        $template = $this->templates->lock($id);
        if (RecurringState::Ended === $template->state()) {
            throw RecurringRefused::because('state', 'recurring.problem.ended');
        }

        return $template;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
