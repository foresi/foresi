<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * The VAT of the firm: its VAT periods with the due dates from the country's
 * rules, and where it pays its VAT.
 */
final class VatBundle extends ModuleBundle
{
}
