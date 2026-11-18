<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Party\Domain\LegalEntity;

interface MailServers
{
    /**
     * The firm's mail server; null when it has none and so cannot send.
     */
    public function for(LegalEntity $firm): ?FirmMailServer;

    public function save(FirmMailServer $server): void;
}
