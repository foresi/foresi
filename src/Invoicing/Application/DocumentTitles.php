<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The title a document carries on its PDF, such as "Invoice - tax document", in
 * the document's own language.
 */
interface DocumentTitles
{
    public function of(IssuedDocument $document): string;
}
