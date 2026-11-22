<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\Party\Domain\Address;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\SetVatSettingsInput;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatSettingsReads;
use Foresi\Vat\Application\VatSettingsWrites;

/**
 * Where the firm pays its VAT: the limits of the account and the variable
 * symbol, the suggested symbol, and who may read and set them.
 */
final class VatSettingsTest extends VatTestCase
{
    public function testTheLimitsCountCharactersNotBytes(): void
    {
        $account = str_repeat('č', 64);
        $symbol = str_repeat('ř', 35);

        $set = $this->writes()->set(new SetVatSettingsInput($account, ' '.$symbol.' '));

        self::assertSame([$account, $symbol], [$set->officeAccount, $set->variableSymbol]);
        $this->assertRefused('office_account_invalid', fn (): \Foresi\Vat\Application\VatSettingsView => $this->writes()->set(new SetVatSettingsInput(str_repeat('č', 65))));
        $this->assertRefused('variable_symbol_invalid', fn (): \Foresi\Vat\Application\VatSettingsView => $this->writes()->set(new SetVatSettingsInput('705-1/0710', str_repeat('ř', 36))));
    }

    public function testAnEmptySymbolTakesTheVatIdDigitsAndASetOneIsTrimmed(): void
    {
        self::assertSame('12345678', $this->writes()->set(new SetVatSettingsInput('705-1/0710'))->variableSymbol);
        self::assertSame('99', $this->writes()->set(new SetVatSettingsInput('705-1/0710', ' 99 '))->variableSymbol);
        self::assertSame(['705-1/0710', '99'], [$this->reads()->current()->officeAccount, $this->reads()->current()->variableSymbol]);
    }

    public function testAFirmWithoutAVatIdGetsAnEmptySuggestedSymbol(): void
    {
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), null, $this->firm->address(), $this->firm->vatStatus(), '');
        $this->em->flush();

        self::assertSame('', $this->reads()->current()->suggestion?->variableSymbol);
        self::assertSame('', $this->writes()->set(new SetVatSettingsInput('705-1/0710'))->variableSymbol);
    }

    public function testWithoutCountryRulesThereIsNoSuggestion(): void
    {
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), $this->firm->vatId(), new Address('Example Street 1', 'Example City', '811 01', 'SK'), $this->firm->vatStatus(), '');
        $this->em->flush();

        self::assertNull($this->reads()->current()->suggestion);
        self::assertSame('', $this->writes()->set(new SetVatSettingsInput('705-1/0710'))->variableSymbol);
    }

    public function testAGuestNeitherReadsNorSets(): void
    {
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', fn (): \Foresi\Vat\Application\VatSettingsView => $this->reads()->current());
        $this->assertRefused('forbidden', fn (): \Foresi\Vat\Application\VatSettingsView => $this->writes()->set(new SetVatSettingsInput('705-1/0710')));
    }

    private function writes(): VatSettingsWrites
    {
        return self::getContainer()->get(VatSettingsWrites::class);
    }

    private function reads(): VatSettingsReads
    {
        return self::getContainer()->get(VatSettingsReads::class);
    }

    private function assertRefused(string $reason, \Closure $work): void
    {
        try {
            $work();
            self::fail('Expected a refusal '.$reason.'.');
        } catch (VatRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }
}
