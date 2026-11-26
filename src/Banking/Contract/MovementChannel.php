<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * The way a bank movement reached Foresi.
 */
enum MovementChannel: string
{
    /** A notification mail of the bank, read by a BankMessageParser. */
    case Mail = 'mail';
    /** A statement file, read by a StatementFormat. */
    case Statement = 'statement';
    /** A bank's API, called by a connector module. */
    case Api = 'api';
}
