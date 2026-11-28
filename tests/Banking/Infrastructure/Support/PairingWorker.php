<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure\Support;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\Allocation;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\PairingRefused;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Application\UnpairMovement;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Invoicing\Application\CancelPayment;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What pairing-race.php runs: automatic and manual pairing in parallel processes,
 * in time slots shared with the others, one invoice per slot. Each invoice has two
 * verified notices of its whole amount, told apart by their bank references.
 *
 * @phpstan-type Ids array{invoices: list<string>, first: list<string>, second: list<string>}
 */
abstract class PairingWorker extends KernelTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string AMOUNT = '1210.00';
    private const float SLOT = 0.5;

    /**
     * Rebuilds the schema, issues the invoices and records their two movements.
     *
     * @param bool $paired whether the first movement of each invoice is paired with it already
     *
     * @return Ids
     */
    public static function seed(int $invoices, bool $paired = false): array
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register'));
        $account = FirmAccounts::add($em, self::IBAN);
        $series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->flush();

        $ids = ['invoices' => [], 'first' => [], 'second' => []];
        $issue = $container->get(IssueInvoice::class);
        $record = $container->get(RecordMovement::class);
        $day = new \DateTimeImmutable('2026-11-01');
        $verified = new TrustVerdict(MovementTrust::Verified, TrustVerdict::DMARC);
        for ($i = 0; $i < $invoices; ++$i) {
            $symbol = (string) (2026100 + $i);
            // 1,000.00 with 21 % VAT: 1,210.00 to pay.
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $invoice = $issue->issue(new InvoiceInput($series->id(), null, $customer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, self::IBAN, $symbol, '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
            $ids['invoices'][] = $invoice->id();
            foreach (['first', 'second'] as $notice) {
                $input = new MovementInput($account->id(), null, MovementDirection::Incoming, Money::of(self::AMOUNT, Currency::of('CZK')), $day, '123456789/0800', null, 'Example Customer a.s.', $symbol, null, null, null, $notice.'-'.$i);
                $ids[$notice][] = $record->record($input, $verified)->id();
            }
            if ($paired) {
                $container->get(PairMovement::class)->pair($ids['first'][$i], [new Allocation($invoice->id(), Money::of(self::AMOUNT, Currency::of('CZK')))]);
            }
        }

        return $ids;
    }

    /**
     * @param string $job auto-first, auto-second, manual-first, unpair-first or cancel-first
     *
     * @return array{results: list<string>, began: list<float>, ended: list<float>} per time slot
     */
    public static function perform(string $json, string $job, float $startAt): array
    {
        $ids = self::ids($json);
        self::enter();
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
                    'manual-first' => '' !== $pairing->pair($ids['first'][$slot], [new Allocation($invoice, Money::of(self::AMOUNT, Currency::of('CZK')))]) ? 'paired' : 'no id',
                    'unpair-first' => self::unpaired($ids['first'][$slot]),
                    'cancel-first' => self::cancelled($ids['first'][$slot]),
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
     * @return list<array{paid: int, allocations: int, movements: list<array{state: string, reason: ?string, payments: int, cancelled: int}>}>
     */
    public static function inspect(string $json): array
    {
        $ids = self::ids($json);
        self::enter();
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
                $cancelled = $connection->fetchOne('SELECT COUNT(*) FROM payment WHERE bank_movement_id = ? AND cancelled_at IS NOT NULL', [$movement]);
                $movements[] = ['state' => $row['state'], 'reason' => \is_string($row['unmatched_reason']) ? $row['unmatched_reason'] : null, 'payments' => self::int($payments), 'cancelled' => self::int($cancelled)];
            }
            $found[] = ['paid' => self::int($paid), 'allocations' => self::int($allocations), 'movements' => $movements];
        }

        return $found;
    }

    /**
     * How many deadlocks PostgreSQL has found in the database so far; null on another
     * database. A backend reports its count when it ends, so ask after the workers.
     */
    public static function deadlocks(): ?int
    {
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return null;
        }
        $connection->fetchOne('SELECT pg_stat_clear_snapshot()');

        return self::int($connection->fetchOne('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()'));
    }

    private static function unpaired(string $movementId): string
    {
        self::getContainer()->get(UnpairMovement::class)->unpair($movementId);

        return 'unpaired';
    }

    /**
     * Cancels the payment of the movement on the invoice, as the payment panel does:
     * by the id of the payment it shows, read before.
     */
    private static function cancelled(string $movementId): string
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $paymentId = $connection->fetchOne('SELECT id FROM payment WHERE bank_movement_id = ?', [$movementId]);
        \assert(\is_string($paymentId));
        self::getContainer()->get(CancelPayment::class)->cancel($paymentId);

        return 'cancelled';
    }

    private static function int(mixed $value): int
    {
        \assert(is_numeric($value));

        return (int) $value;
    }

    private static function enter(): void
    {
        self::bootKernel(['debug' => false]);
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
