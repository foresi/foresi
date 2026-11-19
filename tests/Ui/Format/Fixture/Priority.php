<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Format\Fixture;

/**
 * An enum backed by a number, as a column may show one.
 */
enum Priority: int
{
    case High = 3;
}
