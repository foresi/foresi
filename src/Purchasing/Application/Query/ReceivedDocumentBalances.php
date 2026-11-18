<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

use Foresi\Purchasing\Application\PaymentBalance;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\ReceivedDocumentPayments;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;

/**
 * The received documents for the API and MCP with what is paid and what
 * remains: a slice of a list, or one document with its payments. Only the
 * owners and members of the organisation read them, in the trash too.
 */
final readonly class ReceivedDocumentBalances
{
    public function __construct(
        private PurchasingAccess $access,
        private ReceivedDocuments $documents,
        private ReceivedDocumentQueries $queries,
        private ReceivedDocumentPayments $payments,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed      for anyone but the owners and members of the organisation
     * @throws \InvalidArgumentException for a limit below one, or an id that is none
     */
    public function slice(ReceivedDocumentSliceFilter $filter, ?string $afterId, int $limit): ReceivedDocumentSlice
    {
        $this->access->denyUnlessStaff();
        [$documents, $hasMore] = $this->queries->slice($filter, $afterId, $limit);
        $sums = $this->payments->sums(array_map(static fn (\Foresi\Purchasing\Domain\ReceivedDocument $document): string => $document->id(), $documents));

        return new ReceivedDocumentSlice(array_map(static fn (\Foresi\Purchasing\Domain\ReceivedDocument $document): PayableDocument => new PayableDocument(
            $document,
            PaymentBalance::of($document, $sums[$document->id()]['paid'] ?? 0, $sums[$document->id()]['last'] ?? null),
        ), $documents), $hasMore);
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     */
    public function of(string $documentId): PaidDocument
    {
        $this->access->denyUnlessStaff();
        $document = $this->documents->find($documentId) ?? throw new UnknownReceivedDocument();
        $payments = $this->payments->ofDocument($document->id());
        $paid = array_sum(array_map(static fn (ReceivedDocumentPayment $payment): int => $payment->amount()->minor(), $payments));
        // The payments come by the day paid, so the last is the latest.
        $last = [] === $payments ? null : $payments[array_key_last($payments)]->paidOn();

        return new PaidDocument($document, PaymentBalance::of($document, $paid, $last), $payments);
    }
}
