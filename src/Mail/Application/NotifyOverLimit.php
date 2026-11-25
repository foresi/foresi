<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Instance\Application\InstanceClock;
use Psr\Log\LoggerInterface;

/**
 * Records a mail dropped over the daily limit on its address, and logs the first
 * drop of each address and instance day, once, as a warning that names the address
 * id and the limit, never the token or the address itself. The first is told by the
 * count the one SQL statement returns, so two workers never both log it.
 */
final readonly class NotifyOverLimit
{
    public function __construct(
        private InboundAddressStore $store,
        private InstanceClock $clock,
        private DailyLimit $limit,
        private LoggerInterface $logger,
    ) {
    }

    public function notify(string $addressId): void
    {
        $dropped = $this->store->recordDrop($addressId, $this->clock->today());
        if (1 === $dropped) {
            $this->logger->warning('Inbound address {address} reached its daily limit of {limit} e-mails; more mail to it is dropped until tomorrow.', ['address' => $addressId, 'limit' => $this->limit->limit()]);
        }
    }
}
