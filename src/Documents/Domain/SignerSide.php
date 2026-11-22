<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

/**
 * Whether a signer signs for the firm or for the other party.
 */
enum SignerSide: string
{
    case Ours = 'ours';
    case Theirs = 'theirs';
}
