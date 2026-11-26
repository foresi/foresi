<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Application\Api\ResumeRecurringInvoiceInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * recurring-invoices.resume: the template, and in the summary the dates the
 * pause skipped, which are not issued.
 */
final readonly class ResumeRecurringInvoiceHandler implements OperationHandler
{
    public function __construct(private RecurringWrites $writes, private RecurringInvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ResumeRecurringInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ResumeRecurringInvoiceInput);
        $skipped = [];

        $output = $this->answer->of(function () use ($input, &$skipped): string {
            [$id, $skipped] = $this->writes->resume($input);

            return $id;
        }, 'Resumed the recurring invoice %s.');
        $dates = array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $skipped);

        return [] === $dates ? $output : new OperationOutput(
            $output->data,
            $output->summary.' The pause skipped '.implode(', ', $dates).', which are not issued.',
            $output->location,
        );
    }
}
