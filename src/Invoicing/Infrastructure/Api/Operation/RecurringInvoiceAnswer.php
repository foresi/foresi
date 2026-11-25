<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\RecurringRefused;
use Foresi\Invoicing\Infrastructure\Api\RecurringInvoiceApiController;
use Foresi\Invoicing\Infrastructure\Api\RecurringInvoiceJson;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What every recurring write answers: the template as GET
 * /api/v1/recurring-invoices/{id} and foresi_get_recurring_invoice answer it.
 * What the rules refuse is worded in English with the messages of the form.
 */
final readonly class RecurringInvoiceAnswer
{
    public function __construct(private RecurringWrites $writes, private RecurringInvoiceJson $json, private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    /**
     * @param \Closure(): string $write   the write, answering the id of the template
     * @param string             $summary with %s for the name of the template
     *
     * @throws InvoicingRefused
     */
    public function of(\Closure $write, string $summary, bool $created = false): OperationOutput
    {
        try {
            $id = $write();
        } catch (RecurringRefused $refused) {
            throw InvoicingRefused::of($refused, fn (IssueProblem $problem): string => $this->translator->trans($problem->key, $problem->parameters, null, 'en'));
        }
        $template = $this->writes->template($id);

        return new OperationOutput(
            $this->json->detail($template),
            \sprintf($summary, $template->name()),
            $created ? $this->urls->generate(RecurringInvoiceApiController::SHOW, ['recurringInvoiceId' => $id]) : null,
        );
    }
}
