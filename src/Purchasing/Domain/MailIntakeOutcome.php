<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

/**
 * What became of a mail sent to a capture address: it waits for the job
 * (Queued), made one draft or more (Filed), had no attachment worth a draft
 * (NothingAccepted), or the job gave up after its retries (Failed).
 */
enum MailIntakeOutcome: string
{
    case Queued = 'queued';
    case Filed = 'filed';
    case NothingAccepted = 'nothing_accepted';
    case Failed = 'failed';
}
