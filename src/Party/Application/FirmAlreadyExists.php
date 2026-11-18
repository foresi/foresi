<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * An instance holds one firm (ADR 0039); another one belongs in an instance of its own.
 */
final class FirmAlreadyExists extends \DomainException
{
}
