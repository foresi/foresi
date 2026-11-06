<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * iDoklad gave no usable answer. The message is safe to show: it never holds
 * a credential, a token or a response body.
 */
class IdokladFailure extends \RuntimeException
{
}
