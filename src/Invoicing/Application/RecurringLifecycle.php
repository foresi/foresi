<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Pausing, resuming, ending, deleting and restoring a recurring template, each
 * under its lock, so a run issuing it meanwhile finishes first or finds the change.
 * Nothing is issued after a pause commits. Ending is final. Only a template that
 * never issued may be deleted, to the trash, paused; one that did is ended instead,
 * so its invoices keep where they came from. A template in the trash is restored
 * before anything else is done with it, and comes back paused.
 */
final readonly class RecurringLifecycle
{
    public function __construct(
        private RecurringInvoices $templates,
        private TransactionalWork $work,
        private ClockInterface $clock,
        private AuditActor $actor,
    ) {
    }

    /**
     * Repeatable.
     *
     * @throws RecurringRefused        once ended, or in the trash
     * @throws UnknownRecurringInvoice
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
     * @param \DateTimeImmutable $today the instance's today
     *
     * @return list<\DateTimeImmutable> the skipped dates, oldest first
     *
     * @throws RecurringRefused        once ended, or in the trash
     * @throws UnknownRecurringInvoice
     */
    public function resume(string $id, \DateTimeImmutable $today): array
    {
        return $this->work->run(fn (): array => $this->unended($id)->resume($today));
    }

    /**
     * Repeatable.
     *
     * @throws RecurringRefused        in the trash
     * @throws UnknownRecurringInvoice
     */
    public function end(string $id): void
    {
        $this->work->run(function () use ($id): void {
            $this->untrashed($id)->end($this->now());
        });
    }

    /**
     * To the trash, paused, for PurgeTrashedRecurring to remove after 30 days.
     *
     * @throws RecurringRefused        once the template issued an invoice, or in the trash already
     * @throws UnknownRecurringInvoice
     */
    public function delete(string $id): void
    {
        $this->work->run(function () use ($id): void {
            $template = $this->untrashed($id);
            if ($template->isIssued()) {
                throw RecurringRefused::because('state', 'recurring.problem.issued');
            }
            $template->trash($this->now(), $this->actor->userId());
        });
    }

    /**
     * Back from the trash, paused. Repeatable.
     *
     * @throws UnknownRecurringInvoice
     */
    public function restore(string $id): void
    {
        $this->work->run(function () use ($id): void {
            $this->templates->lock($id)->restore();
        });
    }

    private function untrashed(string $id): RecurringInvoice
    {
        $template = $this->templates->lock($id);
        if ($template->isTrashed()) {
            throw RecurringRefused::because('state', 'recurring.problem.trashed');
        }

        return $template;
    }

    private function unended(string $id): RecurringInvoice
    {
        $template = $this->untrashed($id);
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
