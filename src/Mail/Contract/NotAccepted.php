<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * A purpose refuses a message, such as one for the address of an archived bank
 * account: nothing of it is kept, and the sender learns no more than of an
 * unknown address.
 */
final class NotAccepted extends \RuntimeException
{
}
