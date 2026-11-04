<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\BankMovements;
use App\Banking\Application\ClaimedMessage;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\MessageRow;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\ProcessingResult;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;

/**
 * The two stores the bank screen reads, holding made-up counts and accounts, and
 * writing what it asked for: "movements:"/"messages:" with the tab, firm and
 * account of a count, "rows:"/"messageRows:" with the offset and limit of a page.
 * Nothing else of them is called.
 */
final class ScreenStores
{
    /** @var list<string> */
    public array $asked = [];

    public readonly BankMovements $movements;
    public readonly InboundMessages $messages;

    /**
     * @param array<string, array{firmId: string, firmName: string, label: string, iban: string}> $accounts by account id
     */
    public function __construct(array $accounts, int $movementCount, int $messageCount, int $attention = 0)
    {
        $log = function (string $entry): void {
            $this->asked[] = $entry;
        };
        $this->movements = new readonly class($accounts, $movementCount, $log) implements BankMovements {
            /**
             * @param array<string, array{firmId: string, firmName: string, label: string, iban: string}> $accounts
             * @param \Closure(string): void                                                              $log
             */
            public function __construct(private array $accounts, private int $count, private \Closure $log)
            {
            }

            public function get(string $id): BankMovement
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function lock(string $id): BankMovement
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function lockAccount(string $accountId): void
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function add(BankMovement $movement): void
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function fingerprintTakenByOther(string $fingerprint, ?string $inboundMessageId): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function hasLivePayment(string $movementId): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function anyOnAccount(string $accountId): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function unmatchedIncoming(): int
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function accounts(): array
            {
                return $this->accounts;
            }

            public function count(MovementFilter $filter): int
            {
                ($this->log)(\sprintf('movements:%s:%s:%s', $filter->tab, $filter->firmId ?? '-', $filter->accountId ?? '-'));

                return MovementFilter::UNMATCHED === $filter->tab ? 7 : $this->count;
            }

            public function rows(MovementFilter $filter, int $offset, int $limit): array
            {
                ($this->log)(\sprintf('rows:%d:%d', $offset, $limit));

                return [];
            }
        };
        $this->messages = new readonly class($messageCount, $attention, $log) implements InboundMessages {
            /**
             * @param \Closure(string): void $log
             */
            public function __construct(private int $count, private int $attention, private \Closure $log)
            {
            }

            public function has(string $dedupKey): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function rawHashOf(string $dedupKey): ?string
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function add(InboundMessage $message): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function find(string $id): ?InboundMessage
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function lastOfFeed(string $feedId): ?InboundMessage
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function claim(string $id, array $from, ?string $failure = null): ?ClaimedMessage
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function finish(string $id, ProcessingResult $result, \DateTimeImmutable $at): void
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function recordAccepted(string $id, InboundStatus $status): void
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function countFailedAttempt(string $id): void
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function due(array $parserVersions, string $signature, int $limit): array
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function needingAttention(\DateTimeImmutable $stuckBefore, ?string $firmId = null, ?string $accountId = null): int
            {
                return $this->attention;
            }

            public function count(MovementFilter $filter): int
            {
                ($this->log)(\sprintf('messages:%s:%s:%s', $filter->tab, $filter->firmId ?? '-', $filter->accountId ?? '-'));

                return $this->count;
            }

            public function rows(MovementFilter $filter, \DateTimeImmutable $stuckBefore, int $offset, int $limit): array
            {
                ($this->log)(\sprintf('messageRows:%d:%d', $offset, $limit));

                return [];
            }

            public function row(string $id, \DateTimeImmutable $stuckBefore): ?MessageRow
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function dismiss(string $id, array $from, ?\DateTimeImmutable $receivedBefore): ?InboundStatus
            {
                throw new \LogicException('Not read by the screen.');
            }
        };
    }
}
