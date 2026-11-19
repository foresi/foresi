<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Invoicing\Application\DocumentFormat;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\MailSamples;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Domain\FirmInvoicing;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MailSamplesTest extends TestCase
{
    public function testTheFirmAndTheSenderAreRealTheDocumentIsMadeUp(): void
    {
        $firm = $this->firm(VatId::fromString('CZ12345695'));
        $values = $this->samples($firm, new User('jana@example.com', 'hash', 'Jana Example'))
            ->valuesFor($firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, Currency::CZK, 'Sample Customer', 'Faktura');

        self::assertSame('20260042', $values['number']);
        self::assertSame('19. 11. 2026', $values['issue_date']);
        self::assertSame('3. 12. 2026', $values['due_date'], 'fourteen days after today');
        self::assertSame('CZK', $values['currency']);
        self::assertSame('Faktura', $values['document_type']);
        self::assertSame('Sample Customer', $values['customer_name']);
        self::assertSame("12\u{a0}100,00\u{a0}Kč", str_replace("\u{202f}", "\u{a0}", $values['amount_total']));
        self::assertSame("2\u{a0}100,00\u{a0}Kč", str_replace("\u{202f}", "\u{a0}", $values['amount_vat']));
        self::assertSame("10\u{a0}000,00\u{a0}Kč", str_replace("\u{202f}", "\u{a0}", $values['amount_net']));
        self::assertSame('Example Firm s.r.o.', $values['firm_name']);
        self::assertSame('invoices@example.com', $values['firm_email'], 'trimmed');
        self::assertSame('CZ12345695', $values['vat_id']);
        self::assertSame('Jana Example', $values['sender_name']);
        self::assertArrayNotHasKey('amount_due', $values);
        self::assertArrayNotHasKey('original_number', $values);
    }

    public function testWithoutASignedInUserTheFirmSends(): void
    {
        $firm = $this->firm(null);
        $values = $this->samples($firm, null)->valuesFor($firm, MailTemplateKind::Invoice, DocumentLanguage::English, Currency::EUR, 'Sample Customer Ltd', 'Invoice');

        self::assertSame('Example Firm s.r.o.', $values['sender_name']);
        self::assertSame('', $values['vat_id']);
        self::assertSame('19 Nov 2026', $values['issue_date']);
        self::assertSame('12,100.00 EUR', str_replace("\u{a0}", ' ', $values['amount_total']));
    }

    public function testAReminderAndACorrectionHaveTheirOwnValues(): void
    {
        $firm = $this->firm(null);
        $samples = $this->samples($firm, null);

        $reminder = $samples->valuesFor($firm, MailTemplateKind::Reminder, DocumentLanguage::English, Currency::EUR, 'C', 'Invoice');
        self::assertSame(['9', '1', '7,260.00 EUR'], [$reminder['days_overdue'], $reminder['reminder_step'], str_replace("\u{a0}", ' ', $reminder['amount_due'])]);
        self::assertArrayNotHasKey('original_number', $reminder);

        $correction = $samples->valuesFor($firm, MailTemplateKind::CorrectionDocument, DocumentLanguage::English, Currency::EUR, 'C', 'Corrective tax document');
        self::assertSame('20260017', $correction['original_number']);
        self::assertArrayNotHasKey('amount_due', $correction);
    }

    private function samples(LegalEntity $firm, ?User $user): MailSamples
    {
        $invoicing = FirmInvoicing::standard($firm);
        $invoicing->change(TotalRounding::None, '', '', '', ' invoices@example.com ', '', '');
        $settings = self::createStub(FirmInvoicingSettings::class);
        $settings->method('for')->willReturn($invoicing);
        $users = self::createStub(UserRepository::class);
        $users->method('findById')->willReturn($user);
        $actor = self::createStub(AuditActor::class);
        $actor->method('userId')->willReturn($user?->id());

        return new MailSamples(new DocumentFormat(), $settings, $users, $actor, new MockClock('2026-11-19 23:30:00'));
    }

    private function firm(?VatId $vatId): LegalEntity
    {
        return new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), $vatId, new Address('Example Street 1', 'Example City', '10000', 'CZ'), $vatId instanceof \Foresi\Party\Domain\VatId ? VatStatus::Registered : VatStatus::NotRegistered, '');
    }
}
