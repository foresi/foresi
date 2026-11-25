<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Support;

use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\ClaimedMessage;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\MessageRow;
use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\ProcessingResult;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\InboundMessage;
use Foresi\Banking\Domain\InboundStatus;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The two stores the bank screen reads, holding made-up counts and accounts, and
 * writing what it asked for: "movements:"/"messages:" with the conditions of a
 * count, "counts:" with the text and the views counted together, "attention:" with
 * the moment and the view of the mail, "rows:"/"messageRows:" with the offset and
 * limit of a page. Each view counted together holds 7 plus its conditions.
 * Nothing else of them is called.
 */
final class ScreenStores
{
    /** @var list<string> */
    public array $asked = [];

    public readonly BankMovements $movements;
    public readonly InboundMessages $messages;

    /**
     * @param array<string, array{label: string, iban: string}> $accounts by account id
     */
    public function __construct(array $accounts, int $movementCount, int $messageCount, int $attention = 0)
    {
        $log = function (string $entry): void {
            $this->asked[] = $entry;
        };
        $this->movements = new readonly class($accounts, $movementCount, $log) implements BankMovements {
            /**
             * @param array<string, array{label: string, iban: string}> $accounts
             * @param \Closure(string): void                            $log
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

            public function fingerprintTakenByOther(string $fingerprint, string $sourceId, ?string $inboundMessageId, ?string $statementId = null): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function referenceKnown(string $accountId, string $sourceId, string $reference): bool
            {
                throw new \LogicException('Not read by the screen.');
            }

            public function corroborationCandidates(MovementInput $input): array
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
                ($this->log)('movements:'.json_encode($filter->view->toArray()['conditions']).':'.$filter->text);

                return $this->count;
            }

            public function counts(string $text, array $views): array
            {
                ($this->log)('counts:'.$text.':'.implode(',', array_keys($views)));

                return array_map(static fn (ViewFilter $view): int => 7 + \count($view->conditions), $views);
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

            public function add(InboundMessage $message): void
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

            public function needingAttention(\DateTimeImmutable $stuckBefore, ?ViewFilter $view = null): int
            {
                ($this->log)('attention:'.$stuckBefore->format('H:i').':'.json_encode($view?->toArray()['conditions']));

                return $this->attention;
            }

            public function count(MovementFilter $filter): int
            {
                ($this->log)('messages:'.json_encode($filter->view->toArray()['conditions']));

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
