<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\Country;

use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\SetVatSettingsInput;
use Foresi\Vat\Application\VatSettingsReads;
use Foresi\Vat\Application\VatSettingsWrites;

/**
 * The variable symbol the Czech rules suggest: the digits of the firm's VAT
 * ID, unless one is set.
 */
final class VatSettingsTest extends VatTestCase
{
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

    private function writes(): VatSettingsWrites
    {
        return self::getContainer()->get(VatSettingsWrites::class);
    }

    private function reads(): VatSettingsReads
    {
        return self::getContainer()->get(VatSettingsReads::class);
    }
}
