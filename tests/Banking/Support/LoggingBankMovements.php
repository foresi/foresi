<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\BankMovements;
use App\Banking\Application\MovementFilter;
use App\Banking\Domain\BankMovement;

/**
 * The movement store, writing every account lock and every added movement, in
 * order: "lockAccount:" with the account id, "add:" with the movement id.
 */
final class LoggingBankMovements implements BankMovements
{
    /** @var list<string> */
    public array $entries = [];

    public function __construct(private readonly BankMovements $movements)
    {
    }

    public function get(string $id): BankMovement
    {
        return $this->movements->get($id);
    }

    public function lock(string $id): BankMovement
    {
        return $this->movements->lock($id);
    }

    public function lockAccount(string $accountId): void
    {
        $this->entries[] = 'lockAccount:'.$accountId;
        $this->movements->lockAccount($accountId);
    }

    public function add(BankMovement $movement): void
    {
        $this->entries[] = 'add:'.$movement->id();
        $this->movements->add($movement);
    }

    public function fingerprintTakenByOther(string $fingerprint, ?string $inboundMessageId): bool
    {
        return $this->movements->fingerprintTakenByOther($fingerprint, $inboundMessageId);
    }

    public function hasLivePayment(string $movementId): bool
    {
        return $this->movements->hasLivePayment($movementId);
    }

    public function anyOnAccount(string $accountId): bool
    {
        return $this->movements->anyOnAccount($accountId);
    }

    public function unmatchedIncoming(): int
    {
        return $this->movements->unmatchedIncoming();
    }

    public function accounts(): array
    {
        return $this->movements->accounts();
    }

    public function count(MovementFilter $filter): int
    {
        return $this->movements->count($filter);
    }

    public function rows(MovementFilter $filter, int $offset, int $limit): array
    {
        return $this->movements->rows($filter, $offset, $limit);
    }
}
