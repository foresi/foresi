<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentApiController;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentJson;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What every received document write answers: the document with its payments,
 * as GET /api/v1/received-documents/{id} and foresi_get_received_document answer it.
 */
final readonly class ReceivedDocumentAnswer
{
    public function __construct(private ReceivedDocumentBalances $balances, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param string $summary with %s for the evidence number of the document
     */
    public function of(string $id, string $summary, bool $created = false): OperationOutput
    {
        $paid = $this->balances->of($id);

        return new OperationOutput(
            ReceivedDocumentJson::detail($paid),
            \sprintf($summary, $paid->document->evidenceNumber() ?? $id),
            $created ? $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $id]) : null,
        );
    }
}
