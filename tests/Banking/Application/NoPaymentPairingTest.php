<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\Allocation;
use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\Matcher;
use Foresi\Banking\Application\PairingRefused;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\UnpairMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Banking\Infrastructure\Pairing\NoCandidates;
use Foresi\Banking\Infrastructure\Pairing\NoPaymentPairing;
use Foresi\Shared\Application\ConflictRetry;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Banking\Support\BankingTestCase;

/**
 * Banking without a module that pays documents from movements, such as
 * Invoicing: every movement stays unmatched with the reason that nothing pairs
 * it, and pairing or unpairing one is refused without storing anything.
 */
final class NoPaymentPairingTest extends BankingTestCase
{
    public function testNothingPairsAndNothingIsNamed(): void
    {
        $pairing = new NoPaymentPairing();
        $candidates = new NoCandidates();

        self::assertFalse($pairing->isAvailable());
        self::assertNull($pairing->settlementLimit(Currency::of('CZK')));
        self::assertFalse($pairing->isLive('01963c5e-0000-7000-8000-00000000beef'));
        self::assertSame([], $pairing->documentsOf(['01963c5e-0000-7000-8000-00000000beef']));
        $pairing->lock([]);
        self::assertSame([[], [], [], []], [$candidates->withSymbol('20260001'), $candidates->open(Currency::of('CZK'), '', [], 10), $candidates->refunds(null), $candidates->paidFrom(self::COUNTER_IBAN, null)]);
        $movement = $this->storedMovement($this->recordMovement->record($this->incoming('1210.00'), self::verified())->id());
        $this->expectRefused(PairingRefused::NO_PAIRING, static fn (): string => $pairing->pay($movement, [new Allocation('01963c5e-0000-7000-8000-00000000dead', $movement->amount())]));
        $this->expectRefused(PairingRefused::NO_PAIRING, static function () use ($pairing): string {
            $pairing->cancel('01963c5e-0000-7000-8000-00000000beef');

            return '';
        });
    }

    public function testAMovementStaysUnmatchedBecauseNothingPairsIt(): void
    {
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());

        self::assertNull($this->pairingWithout()->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::NoPairing, null], [$stored->state(), $stored->unmatchedReason(), $stored->paymentId()]);
    }

    public function testTheGateStillGivesItsReason(): void
    {
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::unverified());

        $decision = $this->matcherWithout()->decide($this->storedMovement($movement->id()), [], $this->rules()->get(), null);

        self::assertSame(UnmatchedReason::Unverified, $decision->reason, 'the gate comes first: check it in the bank');
        self::assertFalse($decision->pairs());
    }

    public function testPairingAndUnpairingByHandAreRefused(): void
    {
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());

        $this->expectRefused(PairingRefused::NO_PAIRING, fn (): string => $this->pairingWithout()->pair($movement->id(), [new Allocation('01963c5e-0000-7000-8000-00000000dead', $this->czk('1210.00'))]));
        $this->expectRefused(PairingRefused::NOT_PAIRED, function () use ($movement): string {
            $this->unpairingWithout()->unpair($movement->id());

            return '';
        });
        self::assertSame(MovementState::Unmatched, $this->storedMovement($movement->id())->state());
    }

    private function matcherWithout(): Matcher
    {
        return new Matcher(new NoCandidates(), new NoPaymentPairing());
    }

    private function pairingWithout(): PairMovement
    {
        $container = self::getContainer();

        return new PairMovement($this->movements, $this->matcherWithout(), $this->rules(), new NoPaymentPairing(), $container->get(TransactionalWork::class), $container->get(ConflictRetry::class));
    }

    private function unpairingWithout(): UnpairMovement
    {
        $container = self::getContainer();

        return new UnpairMovement($this->movements, new NoPaymentPairing(), $container->get(TransactionalWork::class), $container->get(ConflictRetry::class));
    }

    private function rules(): FirmPairingRules
    {
        return self::getContainer()->get(FirmPairingRules::class);
    }

    /**
     * @param callable(): string $call
     */
    private function expectRefused(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (PairingRefused $refused) {
            self::assertSame($reason, $refused->reason);

            return;
        }
        self::fail('Expected the pairing to be refused for '.$reason.'.');
    }
}
