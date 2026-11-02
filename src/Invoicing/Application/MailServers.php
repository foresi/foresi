<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\FirmMailServer;
use App\Party\Domain\LegalEntity;

interface MailServers
{
    /**
     * The firm's mail server; null when it has none and so cannot send.
     */
    public function for(LegalEntity $firm): ?FirmMailServer;

    public function save(FirmMailServer $server): void;
}
