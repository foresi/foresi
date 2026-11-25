<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Infrastructure\Api\SalesApiController;
use Foresi\Sales\Infrastructure\Api\SalesJson;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The answer of a write to a deal, its lines or its links: the deal as
 * GET /api/v1/sales/deals/{dealId} reads it, a summary and, for a new deal,
 * where it is read.
 */
final readonly class DealAnswer
{
    public function __construct(private DealReads $reads, private SalesJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public function of(string $dealId, string $summary, bool $created = false): OperationOutput
    {
        $detail = $this->reads->deal($dealId);

        return new OperationOutput(
            $this->json->deal($detail),
            \sprintf($summary, $detail->deal->title()),
            $created ? $this->urls->generate(SalesApiController::DEAL, ['dealId' => $dealId]) : null,
        );
    }
}
