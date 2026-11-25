<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\Query\InvoiceDetails;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Invoicing\Infrastructure\Api\InvoiceJson;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What every invoice write answers: the document as GET /api/v1/invoices/{id}
 * and foresi_get_invoice answer it. What IssueRules refuses is worded in
 * English with the messages of the form, whatever the person's language, as
 * the rest of the API is.
 */
final readonly class InvoiceAnswer
{
    public function __construct(private InvoiceDetails $details, private UrlGeneratorInterface $urls, private TranslatorInterface $translator, private InstanceFirm $instanceFirm)
    {
    }

    /**
     * @param \Closure(): string $write   the write, answering the id of the invoice
     * @param string             $summary with %s for the number of the document
     *
     * @throws InvoicingRefused
     */
    public function of(\Closure $write, string $summary, bool $created = false): OperationOutput
    {
        try {
            $id = $write();
        } catch (IssueRefused $refused) {
            throw InvoicingRefused::of($refused, fn (IssueProblem $problem): string => $this->translator->trans($problem->key, $problem->parameters, null, 'en'));
        }
        $detail = InvoiceJson::detail($this->details->of($id), $this->instanceFirm->get());
        $number = $detail['number'] ?? null;

        return new OperationOutput(
            $detail,
            \sprintf($summary, \is_string($number) ? $number : $id),
            $created ? $this->urls->generate(InvoiceApiController::SHOW, ['invoiceId' => $id]) : null,
        );
    }
}
