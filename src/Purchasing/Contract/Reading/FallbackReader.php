<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * A reader that runs when a file arrives only if the readers before it left
 * the total, the issue date or the supplier's identity empty. It always runs
 * when a person asks. It is for a reader that costs something per file, so it
 * is kept for the files the structured readers could not read.
 */
interface FallbackReader extends DocumentReader
{
}
