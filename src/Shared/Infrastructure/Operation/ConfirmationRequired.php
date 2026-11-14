<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Operation;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;

/**
 * An operation declared with needsConfirmation: a person must confirm it in
 * Foresi, and until that can be done it is refused over the API (ADR 0032):
 * REST 403 and MCP confirmation_required. It is never kept under an
 * idempotency key.
 */
final class ConfirmationRequired extends \DomainException implements Refused
{
    public const string CODE = 'confirmation_required';

    public function __construct(string $operation)
    {
        parent::__construct(\sprintf('The operation %s needs the confirmation of a person in Foresi, which the API cannot ask for yet. Ask the person to do it in Foresi.', $operation));
    }

    public function reason(): string
    {
        return self::CODE;
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::Forbidden;
    }

    public function field(): ?string
    {
        return null;
    }

    public function details(): array
    {
        return [];
    }
}
