<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

/**
 * Why the module that bills work refuses an invoice: the translation key of
 * the problem, which Invoicing shows as a problem of the whole form.
 */
final class BilledWorkRefused extends \DomainException
{
    public function __construct(public readonly string $problem)
    {
        parent::__construct($problem);
    }
}
