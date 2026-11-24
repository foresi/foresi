<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * A reader could not read a file at all: it is corrupt, over a limit, or the
 * service behind the reader is down. Finding nothing is not a failure.
 */
final class ReaderFailed extends \RuntimeException
{
}
