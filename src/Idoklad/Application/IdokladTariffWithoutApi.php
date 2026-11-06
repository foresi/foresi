<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * iDoklad answered 402: the account's tariff has no API access, or it expired.
 */
final class IdokladTariffWithoutApi extends IdokladFailure
{
}
