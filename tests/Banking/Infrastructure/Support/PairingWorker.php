<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure\Support;

use App\Banking\Application\MovementInput;
use App\Banking\Application\PairingRefused;
use App\Banking\Application\PairMovement;
use App\Banking\Application\RecordMovement;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What pairing-race.php runs: automatic and manual pairing in parallel processes,
 * in time slots shared with the others, one invoice per slot. Each invoice has two
 * verified notices of its whole amount, told apart by their bank references.
 *
 * @phpstan-type Ids array{tenant: string, invoices: list<string>, first: list<string>, second: list<string>}
 */
abstract class PairingWorker extends KernelTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string AMOUNT = '1210.00';
    private const float SLOT = 0.5;

    /**
     * Rebuilds the schema, issues the invoices and records their two movements.
     *
     * @return Ids
     */
    public static function seed(int $invoices): array
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $tenant = TenantId::generate();
        $container->get(TenantContext::class)->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register');
        $em->persist($firm);
        $account = FirmAccounts::add($em, $firm, self::IBAN);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->flush();

        $ids = ['tenant' => $tenant->toString(), 'invoices' => [], 'first' => [], 'second' => []];
        $issue = $container->get(IssueInvoice::class);
        $record = $container->get(RecordMovement::class);
        $day = new \DateTimeImmutable('2026-11-01');
        $verified = new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC);
        for ($i = 0; $i < $invoices; ++$i) {
            $symbol = (string) (2026100 + $i);
            // 1,000.00 with 21 % VAT: 1,210.00 to pay.
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $invoice = $issue->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, self::IBAN, $symbol, '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
            $ids['invoices'][] = $invoice->id();
            foreach (['first', 'second'] as $notice) {
                $input = new MovementInput($account->id(), null, MovementDirection::Incoming, Money::of(self::AMOUNT, Currency::CZK), $day, '123456789/0800', null, 'Example Customer a.s.', $symbol, null, null, null, $notice.'-'.$i);
                $ids[$notice][] = $record->record($input, $verified)->id();
            }
        }

        return $ids;
    }

    /**
     * @param string $job auto-first, auto-second or manual-first
     *
     * @return array{results: list<string>, began: list<float>, ended: list<float>} per time slot
     */
    public static function perform(string $json, string $job, float $startAt): array
    {
        $ids = self::ids($json);
        self::enter($ids);
        $pairing = self::getContainer()->get(PairMovement::class);
        $results = [];
        $began = [];
        $ended = [];
        foreach ($ids['invoices'] as $slot => $invoice) {
            usleep(max(0, (int) (($startAt + $slot * self::SLOT - microtime(true)) * 1_000_000)));
            $began[] = microtime(true);
            try {
                $results[] = match ($job) {
                    'auto-first' => null === $pairing->auto($ids['first'][$slot]) ? 'left' : 'paired',
                    'auto-second' => null === $pairing->auto($ids['second'][$slot]) ? 'left' : 'paired',
                    'manual-first' => '' !== $pairing->pair($ids['first'][$slot], [new AllocationRequest($invoice, Money::of(self::AMOUNT, Currency::CZK))])->id() ? 'paired' : 'no id',
                    default => throw new \InvalidArgumentException('Unknown job.'),
                };
            } catch (PairingRefused $refused) {
                $results[] = 'refused:'.$refused->reason;
            }
            $ended[] = microtime(true);
        }

        return [
            'results' => $results,
            'began' => $began,
            'ended' => $ended,
        ];
    }

    /**
     * @return list<array{paid: int, allocations: int, movements: list<array{state: string, reason: ?string, payments: int}>}>
     */
    public static function inspect(string $json): array
    {
        $ids = self::ids($json);
        self::enter($ids);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $found = [];
        foreach ($ids['invoices'] as $round => $invoice) {
            $paid = $connection->fetchOne('SELECT COALESCE(SUM(a.amount_minor + a.settlement_minor), 0) FROM payment_allocation a JOIN payment p ON p.id = a.payment_id WHERE a.invoice_id = ? AND p.cancelled_at IS NULL', [$invoice]);
            $allocations = $connection->fetchOne('SELECT COUNT(*) FROM payment_allocation WHERE invoice_id = ?', [$invoice]);
            $movements = [];
            foreach ([$ids['first'][$round], $ids['second'][$round]] as $movement) {
                $row = $connection->fetchAssociative('SELECT state, unmatched_reason FROM bank_movement WHERE id = ?', [$movement]);
                \assert(\is_array($row) && \is_string($row['state']));
                $payments = $connection->fetchOne('SELECT COUNT(*) FROM payment WHERE bank_movement_id = ? AND cancelled_at IS NULL', [$movement]);
                $movements[] = ['state' => $row['state'], 'reason' => \is_string($row['unmatched_reason']) ? $row['unmatched_reason'] : null, 'payments' => self::int($payments)];
            }
            $found[] = ['paid' => self::int($paid), 'allocations' => self::int($allocations), 'movements' => $movements];
        }

        return $found;
    }

    private static function int(mixed $value): int
    {
        \assert(is_numeric($value));

        return (int) $value;
    }

    /**
     * @param Ids $ids
     */
    private static function enter(array $ids): void
    {
        self::bootKernel(['debug' => false]);
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::fromString($ids['tenant']));
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
