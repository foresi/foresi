<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Column;

use Foresi\Banking\Domain\BankFeed;
use Foresi\Party\Domain\FirmBankAccount;

/**
 * A bank account of the firm as a row of its bank tab: the account, the name it is shown by,
 * its number grouped by four, and the address its bank mail comes to, if it has one.
 */
final readonly class FirmBankAccountRow
{
    public function __construct(
        public FirmBankAccount $account,
        public string $name,
        public string $iban,
        public ?BankFeed $feed,
    ) {
    }
}
