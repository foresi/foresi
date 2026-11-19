<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Party\Application\TransactionalWork;

/**
 * Removes for good, with their lines, the recurring templates that have been in the
 * trash for more than 30 days, as tasks and time are. Only a template that never
 * issued gets there, so no invoice points to one. The audit log keeps what it was.
 */
final readonly class PurgeTrashedRecurring
{
    public const int DAYS = 30;

    public function __construct(private RecurringInvoices $templates, private TransactionalWork $work)
    {
    }

    /**
     * @return int how many templates were removed
     */
    public function run(\DateTimeImmutable $now): int
    {
        return $this->work->run(function () use ($now): int {
            $expired = $this->templates->trashedBefore($now->modify(\sprintf('-%d days', self::DAYS)));
            foreach ($expired as $template) {
                foreach ($this->templates->lines($template) as $line) {
                    $this->templates->removeLine($line);
                }
                $this->templates->remove($template);
            }

            return \count($expired);
        });
    }
}
