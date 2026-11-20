<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Support;

use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\SendReminders;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * What reminder-race.php runs: two reminder runs at once, in time slots shared
 * with the other process. Each slot has a firm whose
 * reminders are on and invoices due on 10 January, so both runs find the same
 * first reminders on the 17th.
 *
 * @phpstan-type Ids list<array{invoices: list<string>}>
 */
abstract class ReminderWorker extends KernelTestCase
{
    public const int INVOICES = 12;
    private const string IBAN = 'CZ6508000000192000145399';
    private const string RUN_AT = '2027-01-17 09:00:00 UTC';
    private const float SLOT = 1.5;

    /**
     * Rebuilds the schema and seeds one firm per slot.
     *
     * @return Ids
     */
    public static function seed(int $slots): array
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $issue = $container->get(IssueInvoice::class);
        $delivered = $container->get(MarkDelivered::class);
        $secrets = $container->get(MailSecrets::class);

        $ids = [];
        for ($slot = 0; $slot < $slots; ++$slot) {
            $em->clear();
            $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register');
            $em->persist($firm);
            FirmAccounts::add($em, self::IBAN);
            $series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $em->persist($series);
            $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
            $em->persist($customer);
            $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $em->persist(new PartyEmail($customer, 'billing@example.com', EmailPurpose::Invoices));
            $em->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $secrets->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
            $reminders = FirmReminders::standard();
            $reminders->change(true, 7, 7, null, new \DateTimeImmutable('2027-01-01'));
            $em->persist($reminders);
            $em->flush();

            $invoices = [];
            $day = new \DateTimeImmutable('2026-11-01');
            for ($i = 0; $i < self::INVOICES; ++$i) {
                $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
                $invoice = $issue->issue(new InvoiceInput($series->id(), null, $customer->id(), null, $day, $day, new \DateTimeImmutable('2027-01-10'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
                $invoices[] = $delivered->mark($invoice->id())->id();
            }
            $ids[] = ['invoices' => $invoices];
        }

        return $ids;
    }

    /**
     * @return array{results: list<string>, began: list<float>, ended: list<float>} per time slot
     */
    public static function perform(string $json, string $job, float $startAt): array
    {
        if ('remind' !== $job) {
            throw new \InvalidArgumentException('Unknown job.');
        }
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        Clock::set(new MockClock(self::RUN_AT));
        $results = [];
        $began = [];
        $ended = [];
        foreach (array_keys(self::ids($json)) as $slot) {
            usleep(max(0, (int) (($startAt + $slot * self::SLOT - microtime(true)) * 1_000_000)));
            $em->clear();
            $began[] = microtime(true);
            $container->get(SendReminders::class)->run(null);
            $ended[] = microtime(true);
            $results[] = 'done';
        }

        return [
            'results' => $results,
            'began' => $began,
            'ended' => $ended,
        ];
    }

    /**
     * The reminder steps stored per invoice, as rows of every status.
     *
     * @return list<list<list<int>>> per slot, per invoice
     */
    public static function inspect(string $json): array
    {
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $found = [];
        foreach (self::ids($json) as $slotIds) {
            $invoices = [];
            foreach ($slotIds['invoices'] as $invoice) {
                $steps = $connection->fetchFirstColumn("SELECT reminder_step FROM invoice_send WHERE invoice_id = ? AND purpose = 'reminder' ORDER BY reminder_step", [$invoice]);
                $invoices[] = array_map(static fn (mixed $step): int => is_numeric($step) ? (int) $step : -1, $steps);
            }
            $found[] = $invoices;
        }

        return $found;
    }

    /**
     * @return Ids
     */
    private static function ids(string $json): array
    {
        /** @var Ids $ids */
        $ids = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);

        return $ids;
    }
}
