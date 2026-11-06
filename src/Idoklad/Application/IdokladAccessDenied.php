<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * iDoklad refused the client id and secret, or the token they gave.
 */
final class IdokladAccessDenied extends IdokladFailure
{
}
