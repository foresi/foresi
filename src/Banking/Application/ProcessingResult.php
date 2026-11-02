<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundStatus;

/**
 * Where one processing run left an inbound message, as stored on its row. A
 * new run replaces all of it.
 */
final readonly class ProcessingResult
{
    /**
     * @param string|null $parserId the parser that read it, or the parser signature when none could
     * @param string|null $failure  one of the ProcessMessage failure codes, for a failed or rejected message
     * @param string|null $detail   for the operator: the field or check that failed, or why it was ignored; never the mail
     */
    public function __construct(
        public InboundStatus $status,
        public ?TrustVerdict $verdict = null,
        public ?string $parserId = null,
        public ?int $parserVersion = null,
        public ?string $failure = null,
        public ?string $detail = null,
    ) {
    }

    public static function parsedBy(InboundStatus $status, BankMessageParser $parser, ?TrustVerdict $verdict, ?string $failure = null, ?string $detail = null): self
    {
        return new self($status, $verdict, $parser->id(), $parser->version(), $failure, $detail);
    }
}
