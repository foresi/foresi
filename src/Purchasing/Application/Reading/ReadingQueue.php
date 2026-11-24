<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

/**
 * Hands a reading to the background job. Called once the document and its
 * reading are stored, so the job never looks for a draft that is not there.
 */
interface ReadingQueue
{
    public function queue(ReadReceivedDocument $message): void;
}
