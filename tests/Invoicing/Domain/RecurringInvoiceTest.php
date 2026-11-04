<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringContent;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * A recurring template on its own, without the database: its name, its limits,
 * and how pausing, resuming, ending and editing move its dates and its version.
 */
final class RecurringInvoiceTest extends TestCase
{
    private ?LegalEntity $firm = null;

    public function testANewTemplateIsActiveAndCopiesByDefault(): void
    {
        $template = $this->template();

        self::assertSame(RecurringState::Active, $template->state());
        self::assertNull($template->pausedAt());
        self::assertSame('2026-12-01', $template->nextOccurrence()?->format('Y-m-d'));
        self::assertTrue($template->copyToMe());
        self::assertTrue($template->copyToOthers());
        self::assertSame(1, $template->version());
    }

    public function testTheNameIsTrimmedAndMayTakeItsWholeLengthInAnyScript(): void
    {
        self::assertSame('Hosting', $this->template(name: "  Hosting \n")->name());
        $long = str_repeat('č', RecurringInvoice::MAX_NAME_LENGTH);
        self::assertSame($long, $this->template(name: $long)->name());
    }

    public function testANameOverItsLengthIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        $this->template(name: str_repeat('a', RecurringInvoice::MAX_NAME_LENGTH + 1));
    }

    public function testWithoutANameTheDescriptionNamesItOnOneLineAndCutToTheLength(): void
    {
        self::assertSame('Hosting for the month', $this->template(description: "  Hosting\n  for the\tmonth  ")->name());
        $long = str_repeat('ž', RecurringInvoice::MAX_NAME_LENGTH + 5);
        self::assertSame(str_repeat('ž', RecurringInvoice::MAX_NAME_LENGTH), $this->template(description: $long)->name());
        self::assertSame('Example Customer', $this->template(description: " \n ")->name(), 'nothing but blanks: the customer names it');
    }

    public function testAnImportedOriginTakesOneToSixtyFourCharacters(): void
    {
        $long = str_repeat('č', RecurringInvoice::MAX_IMPORTED_FROM_LENGTH);

        self::assertSame($long, $this->template(importedFrom: $long)->importedFrom());
    }

    #[TestWith([''])]
    #[TestWith(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])]
    public function testAnImportedOriginEmptyOrTooLongIsRefused(string $importedFrom): void
    {
        $this->expectException(\DomainException::class);

        $this->template(importedFrom: $importedFrom);
    }

    public function testDueDaysRunFromZeroTo365(): void
    {
        self::assertSame(0, $this->template(dueDays: 0)->dueDays());
        self::assertSame(RecurringInvoice::MAX_DUE_DAYS, $this->template(dueDays: RecurringInvoice::MAX_DUE_DAYS)->dueDays());
    }

    #[TestWith([-1])]
    #[TestWith([366])]
    public function testDueDaysOutsideTheRangeAreRefused(int $days): void
    {
        $this->expectException(\DomainException::class);

        $this->template(dueDays: $days);
    }

    public function testAFailureCodeTakesOneToSixtyFourCharacters(): void
    {
        $template = $this->template();
        $code = str_repeat('a', RecurringInvoice::MAX_FAILURE_CODE_LENGTH);

        $template->recordFailure(new \DateTimeImmutable('2026-12-01 05:30:00'), $code);

        self::assertSame($code, $template->lastFailureCode());
    }

    #[TestWith([''])]
    #[TestWith(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])]
    public function testAFailureCodeEmptyOrTooLongIsRefused(string $code): void
    {
        $this->expectException(\DomainException::class);

        $this->template()->recordFailure(new \DateTimeImmutable('2026-12-01 05:30:00'), $code);
    }

    public function testEachChangeOfStateCountsAVersion(): void
    {
        $template = $this->template(unit: RecurrenceUnit::Day);

        $template->advance(new \DateTimeImmutable('2026-12-01'));
        self::assertSame(2, $template->version());
        $template->pause(new \DateTimeImmutable('2026-12-01 10:00:00'));
        self::assertSame(3, $template->version());
        $template->pause(new \DateTimeImmutable('2026-12-01 11:00:00'));
        self::assertSame(3, $template->version(), 'pausing again changes nothing');
        $template->resume(new \DateTimeImmutable('2026-12-03'));
        self::assertSame(4, $template->version());
        $template->change($this->content(unit: RecurrenceUnit::Day, dueDays: 30), new \DateTimeImmutable('2026-12-03'));
        self::assertSame(5, $template->version());
        $template->end(new \DateTimeImmutable('2026-12-04 10:00:00'));
        self::assertSame(6, $template->version());
    }

    public function testAnEndedTemplateCannotBePaused(): void
    {
        $template = $this->template();
        $template->end(new \DateTimeImmutable('2026-11-20'));

        $this->expectException(\DomainException::class);

        $template->pause(new \DateTimeImmutable('2026-11-21'));
    }

    public function testAnEndedTemplateCannotBeResumed(): void
    {
        $template = $this->template();
        $template->end(new \DateTimeImmutable('2026-11-20'));

        $this->expectException(\DomainException::class);

        $template->resume(new \DateTimeImmutable('2026-11-21'));
    }

    public function testAnEndedTemplateCannotBeChanged(): void
    {
        $template = $this->template();
        $template->end(new \DateTimeImmutable('2026-11-20'));

        $this->expectException(\DomainException::class);

        $template->change($this->content(), new \DateTimeImmutable('2026-11-21'));
    }

    public function testResumingOnTheDayOfAnOccurrenceSkipsOnlyTheDatesBefore(): void
    {
        $template = $this->template(unit: RecurrenceUnit::Day);
        $template->pause(new \DateTimeImmutable('2026-11-20'));

        $skipped = $template->resume(new \DateTimeImmutable('2026-12-03'));

        self::assertSame(['2026-12-01', '2026-12-02'], array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $skipped));
        self::assertSame('2026-12-03', $template->nextOccurrence()?->format('Y-m-d'));
    }

    public function testResumingLongAfterTheLastInvoiceGoesOnFromToday(): void
    {
        $template = $this->template();
        $template->advance(new \DateTimeImmutable('2026-12-01'));
        $template->pause(new \DateTimeImmutable('2026-12-02'));

        $template->resume(new \DateTimeImmutable('2027-03-15'));

        self::assertSame('2027-04-01', $template->nextOccurrence()?->format('Y-m-d'));
    }

    public function testResumingTheDayAfterTheLastInvoiceIssuedAheadKeepsItsNextDate(): void
    {
        // Issued on 1 December for the 1st, paused, resumed on the same day: the next date is not the 1st again.
        $template = $this->template(unit: RecurrenceUnit::Day);
        $template->advance(new \DateTimeImmutable('2026-12-01'));
        $template->pause(new \DateTimeImmutable('2026-11-30'));

        $template->resume(new \DateTimeImmutable('2026-11-30'));

        self::assertSame('2026-12-02', $template->nextOccurrence()?->format('Y-m-d'));
    }

    public function testAnEditLongAfterTheLastInvoiceTakesTheNewScheduleFromToday(): void
    {
        $template = $this->template();
        $template->advance(new \DateTimeImmutable('2026-12-01'));
        $later = new Recurrence(new \DateTimeImmutable('2026-12-10'), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null);

        self::assertSame('2027-01-10', $template->nextOccurrenceUnder($later, new \DateTimeImmutable('2026-12-15'))?->format('Y-m-d'));
    }

    public function testATemplateIsStampedWithItsTenantOnPersist(): void
    {
        $this->expectException(\LogicException::class);

        $this->template()->tenantId();
    }

    public function testALineIsStampedWithItsTenantOnPersist(): void
    {
        $line = new RecurringInvoiceLine($this->template(), 0, 'Hosting', Quantity::fromString('1'), 'pcs', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        $this->expectException(\LogicException::class);

        $line->tenantId();
    }

    private function template(string $name = '', string $description = 'Hosting', int $dueDays = 14, RecurrenceUnit $unit = RecurrenceUnit::Month, ?string $importedFrom = null): RecurringInvoice
    {
        return new RecurringInvoice(
            $this->firm(),
            Currency::CZK,
            $this->content($name, $description, $dueDays, $unit),
            null,
            new \DateTimeImmutable('2026-11-02 11:00:00'),
            importedFrom: $importedFrom,
        );
    }

    private function content(string $name = '', string $description = 'Hosting', int $dueDays = 14, RecurrenceUnit $unit = RecurrenceUnit::Month): RecurringContent
    {
        return new RecurringContent(
            null,
            new Party(PartyKind::Person, 'Example Customer', null, null, ''),
            PaymentMethod::Cash,
            null,
            '',
            '',
            $description,
            '',
            '',
            DocumentLanguage::English,
            new Recurrence(new \DateTimeImmutable('2026-12-01'), $unit, 1, false, RecurrenceEnd::Never, null, null),
            TaxPointRule::IssueDate,
            $dueDays,
            RecurringDelivery::Review,
            $name,
        );
    }

    private function firm(): LegalEntity
    {
        return $this->firm ??= new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
    }
}
