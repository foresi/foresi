<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\PairingChoices;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Banking\Support\BankingTestCase;
use Foresi\Tests\Banking\Support\LoggingBankMovements;
use Foresi\Tests\Events\Support\ReadsEventLog;

final class RecordMovementTest extends BankingTestCase
{
    use ReadsEventLog;

    public function testRecordingAMovementPutsItsAmountAsADecimalStringInTheEvent(): void
    {
        $movement = $this->recordMovement->record($this->incoming('1234.05', vs: '777', counterIban: null), self::unverified());
        $second = $this->recordMovement->record($this->incoming('0.07', vs: null, counterIban: null, bookedOn: '2026-11-21', direction: MovementDirection::Outgoing), self::unverified());

        $events = $this->events('banking.movement.recorded');

        self::assertCount(2, $events);
        self::assertSame($movement->id(), $events[0]['subject']);
        self::assertSame(
            ['movement_id' => $movement->id(), 'firm_id' => $this->firm->id(), 'account_id' => $this->account->id(), 'direction' => 'incoming', 'amount' => '1234.05', 'currency' => 'CZK', 'booked_on' => '2026-11-20', 'state' => $movement->state()->value, 'variable_symbol' => '777'],
            $events[0]['data'],
        );
        self::assertSame(['0.07', 'outgoing', '2026-11-21'], [$events[1]['data']['amount'] ?? null, $events[1]['data']['direction'] ?? null, $events[1]['data']['booked_on'] ?? null]);
        self::assertSame($second->id(), $events[1]['subject']);
    }

    public function testTheAccountIsLockedBeforeTheMovementIsAdded(): void
    {
        $movements = new LoggingBankMovements($this->movements);
        $container = self::getContainer();
        $record = new RecordMovement($movements, $container->get(FirmBankAccounts::class), $this->matcher, $container->get(FirmPairingRules::class), $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $container->get(PairingChoices::class), $container->get(EventRecorder::class), $container->get(InstanceFirm::class));

        $movement = $record->record($this->incoming('10.00'), self::verified());

        // Movements of one account are recorded one after the other: the second of two equal ones sees the first.
        self::assertSame(['lockAccount:'.$this->account->id(), 'add:'.$movement->id()], $movements->entries);
    }

    public function testMovementInputComesFromAParsedMovementCut(): void
    {
        $parsed = new ParsedMovement(MovementDirection::Incoming, Money::of('10.00', Currency::CZK), self::IBAN, '123/0800', null, str_repeat('N', 300), new \DateTimeImmutable('2026-11-20'), '1', null, null, str_repeat('m', 600), null);

        $input = MovementInput::of($parsed, $this->account->id(), null);

        self::assertSame([255, 500, '1'], [mb_strlen($input->counterName ?? ''), mb_strlen($input->message ?? ''), $input->variableSymbol]);
        self::assertSame($input->fingerprint(), MovementInput::of($parsed, $this->account->id(), '01963c5e-0000-7000-8000-000000000009')->fingerprint(), 'the message does not count');
        self::assertNotSame($input->fingerprint(), MovementInput::of($parsed, '01963c5e-0000-7000-8000-00000000000a', null)->fingerprint(), 'the account does');
    }
}
