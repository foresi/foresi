<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Contract;

use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * How a bank movement meets a match rule (C16), the way planned items and the
 * levies of Taxes and contributions pair with it: the account and the
 * variable symbol it names, each when named, without the zeros and spaces a
 * bank may add.
 */
final class ActualFlowTest extends TestCase
{
    public function testAMovementMeetsTheAccountAndTheSymbolTheRuleNames(): void
    {
        $movement = $this->movement('000123-0000456789/0100', '0007805121234');

        self::assertTrue($movement->meets('123-456789/0100', '7805121234'));
        self::assertTrue($movement->meets(' 123 - 456789 / 0100 ', null), 'Only the account named.');
        self::assertTrue($movement->meets(null, '7805121234'), 'Only the symbol named.');
        self::assertFalse($movement->meets('123-456789/0300', '7805121234'), 'Another bank.');
        self::assertFalse($movement->meets('456789/0100', '7805121234'), 'Without the prefix it is another account.');
        self::assertFalse($movement->meets('123-456789/0100', '7805121235'));
        self::assertFalse($movement->meets(null, null), 'A rule that names nothing meets nothing.');
    }

    public function testAnIbanComparesInCapitalsWithoutSpacesAndAMissingValueMeetsNothing(): void
    {
        self::assertTrue($this->movement('cz65 0800 0000 1920 0014 5399', null)->meets('CZ6508000000192000145399', null));
        self::assertFalse($this->movement(null, '1')->meets('123/0100', '1'), 'A movement without an account.');
        self::assertFalse($this->movement('123/0100', null)->meets('123/0100', '1'), 'A movement without a symbol.');
        self::assertTrue($this->movement('123/0100', null)->meets('123/0100', ''), 'An empty symbol matches a movement without one.');
        self::assertFalse($this->movement('123/0100', null)->meets('1230100', null), 'The bank code stays apart.');
        self::assertFalse($this->movement('123/0100', null)->meets('1230100/', null));
    }

    public function testADocumentNeverMeetsARule(): void
    {
        $document = new ActualFlow('invoicing.invoices', new FlowSubject('invoice', 'i1'), FlowDirection::Out, Money::ofMinor(100, Currency::CZK), new \DateTimeImmutable('2026-11-20'), 'c1', '123/0100', '1');

        self::assertFalse($document->meets('123/0100', '1'));
    }

    private function movement(?string $account, ?string $symbol): ActualFlow
    {
        return new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm1'), FlowDirection::Out, Money::ofMinor(100, Currency::CZK), new \DateTimeImmutable('2026-11-20'), counterpartyAccount: $account, variableSymbol: $symbol);
    }
}
