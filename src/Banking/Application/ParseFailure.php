<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Why a bank's mail could not be read. A parser fails rather than guess: a
 * failure is retried with a later parser version, a wrong amount never is.
 */
enum ParseFailure: string
{
    /** The mail is the bank's, but not in a layout the parser knows. */
    case UnknownLayout = 'unknown_layout';
    /** A field the movement needs is not there. */
    case MissingField = 'missing_field';
    /** A field is there but its value cannot be read. */
    case InvalidValue = 'invalid_value';
    /** Two places in the mail disagree, such as its language versions. */
    case Inconsistent = 'inconsistent';
    /** More than one reading, or more than one parser, fits the mail. */
    case Ambiguous = 'ambiguous';
}
