<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * When a person gets an e-mail about a kind of event: at once, a short while
 * after it unless it was read in the inbox first (Linear), in the daily digest
 * (Asana), or never. The inbox always has it.
 */
enum EmailDelivery: string
{
    case Immediate = 'immediate';
    case Digest = 'digest';
    case Off = 'off';
}
