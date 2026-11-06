<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Why a corrective tax document corrects its document. A change is a fact after
 * the supply that changes its base, dated on the day of that fact (§42 of the
 * Czech VAT Act); an error corrects a document that was wrong, which taxes the
 * original period (§43).
 */
enum CorrectionType: string
{
    case Change = 'change';
    case Error = 'error';
}
