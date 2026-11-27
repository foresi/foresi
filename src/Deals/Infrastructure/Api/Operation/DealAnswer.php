<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Infrastructure\Api\SalesApiController;
use Foresi\Deals\Infrastructure\Api\SalesJson;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The answer of a write to a deal, its lines or its links: the deal as
 * GET /api/v1/deals/{dealId} reads it, a summary and, for a new deal,
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
