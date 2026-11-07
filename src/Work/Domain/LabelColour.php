<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * The colour of a label: the badge tones of the user interface, so a label
 * never brings a colour outside the brand palette.
 */
enum LabelColour: string
{
    case Gray = 'gray';
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';
}
