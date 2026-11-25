<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * Why the statements of an account do not follow on.
 */
enum StatementGapKind: string
{
    /** The opening balance is not the closing balance of the statement before. */
    case Balance = 'balance';
    /** The number is not the one after the statement before: one is missing. */
    case Number = 'number';
    /** The statement's own opening balance and entries do not add up to its closing balance. */
    case Inner = 'inner';
}
