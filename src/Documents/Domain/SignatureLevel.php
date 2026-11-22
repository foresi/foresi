<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

/**
 * The level of electronic signature a type of document needs (eIDAS):
 * simple, advanced or qualified. Manual signing records any of them.
 */
enum SignatureLevel: string
{
    case Simple = 'simple';
    case Advanced = 'advanced';
    case Qualified = 'qualified';
}
