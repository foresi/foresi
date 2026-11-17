<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;

/**
 * No received document of the active tenant has the id; answered as not found.
 */
final class UnknownReceivedDocument extends \RuntimeException implements Refused
{
    public function __construct(string $message = 'The organisation has no received document with this id. Find the id with foresi_search_received_documents or GET /api/v1/received-documents.')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return 'not_found';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::NotFound;
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
