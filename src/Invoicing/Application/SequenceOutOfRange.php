<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A sequence that does not fit the counter: the series has run out of numbers, or
 * a number was given that it can never count up to.
 */
final class SequenceOutOfRange extends \DomainException
{
}
