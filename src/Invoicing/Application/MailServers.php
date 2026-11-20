<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\FirmMailServer;

interface MailServers
{
    /**
     * The firm's mail server; null when it has none and so cannot send.
     */
    public function get(): ?FirmMailServer;

    public function save(FirmMailServer $server): void;
}
