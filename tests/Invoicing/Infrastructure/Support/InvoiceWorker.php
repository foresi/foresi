<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Support;

use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
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
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What race.php runs. A kernel test case only for its test container. A process
 * goes through the invoices in time slots shared with the others, so one boot
 * gives many collisions. Every job starts from a form opened at version 1.
 *
 * @phpstan-type Ids array{tenant: string, firm: string, series: string, customer: string, invoices: list<string>}
 */
abstract class InvoiceWorker extends KernelTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const float SLOT = 0.25;
    private const int HOLD = 1_200_000;
    private const string MOMENT = '2026-11-03 10:00:00';

    /**
     * Rebuilds the schema and issues the invoices, each with the same lines.
     *
     * @return Ids
     */
    public static function seed(int $invoices, int $lines): array
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
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register', [self::IBAN]);
        $em->persist($firm);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->flush();

        $ids = ['tenant' => $tenant->toString(), 'firm' => $firm->id(), 'series' => $series->id(), 'customer' => $customer->id(), 'invoices' => []];
        $issue = $container->get(IssueInvoice::class);
        for ($i = 0; $i < $invoices; ++$i) {
            $ids['invoices'][] = $issue->issue(self::input($ids, self::lines($lines, 'seed', '100.00')))->id();
        }

        return $ids;
    }

    /**
     * @return array{results: list<string>, began: list<float>, ended: list<float>} per time slot
     */
    public static function perform(string $json, string $job, float $startAt): array
    {
        $ids = self::ids($json);
        self::enter($ids);
        $container = self::getContainer();
        $edit = $container->get(EditInvoice::class);
        $trash = $container->get(TrashInvoice::class);
        $delivered = $container->get(MarkDelivered::class);
        $issue = $container->get(IssueInvoice::class);

        $results = [];
        $began = [];
        $ended = [];
        foreach ($ids['invoices'] as $slot => $id) {
            usleep(max(0, (int) (($startAt + $slot * self::SLOT - microtime(true)) * 1_000_000)));
            $began[] = microtime(true);
            try {
                $action = match ($job) {
                    'issue' => static fn (): Invoice => $issue->issue(self::input($ids, self::lines(1, 'x', '100.00'))),
                    'edit-two' => static fn (): Invoice => $edit->edit($id, self::input($ids, self::lines(2, 'two', '100.00')), 1),
                    'edit-three' => static fn (): Invoice => $edit->edit($id, self::input($ids, self::lines(3, 'three', '200.00')), 1),
                    'edit-one' => static fn (): Invoice => $edit->edit($id, self::input($ids, self::lines(1, 'one', '100.00')), 1),
                    'trash' => static fn (): Invoice => $trash->trash($id, 'Wrong customer'),
                    'deliver' => static fn (): Invoice => $delivered->mark($id),
                    'renumber' => static fn (): Invoice => $edit->edit($id, self::input($ids, self::lines(1, 'seed', '100.00'), 'R-'.$slot), 1),
                    default => throw new \InvalidArgumentException('Unknown job.'),
                };
                $invoice = $action();
                $results[] = 'issue' === $job ? $invoice->number() : 'done';
            } catch (IssueRefused $refused) {
                $results[] = 'refused:'.$refused->problems[0]->key;
            } catch (\DomainException) {
                $results[] = 'refused';
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
     * Takes the row of the first invoice at the given moment, changes it without
     * moving the version, keeps it for a while, and commits. A service that respects
     * the row waits, then sees the change.
     */
    public static function hold(string $json, string $change, float $startAt): void
    {
        $ids = self::ids($json);
        self::enter($ids);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        usleep(max(0, (int) (($startAt - microtime(true)) * 1_000_000)));
        $connection->beginTransaction();
        $connection->update('invoice', match ($change) {
            'delivered' => ['delivered_at' => self::MOMENT],
            'trashed' => ['deleted_at' => self::MOMENT, 'deleted_reason' => 'Held'],
            default => throw new \InvalidArgumentException('Unknown change.'),
        }, ['id' => $ids['invoices'][0]]);
        usleep(self::HOLD);
        $connection->commit();
    }

    /**
     * Keeps editing the first invoice, with three lines and one in turn.
     */
    public static function churn(string $json, float $startAt, int $saves): void
    {
        $ids = self::ids($json);
        self::enter($ids);
        $edit = self::getContainer()->get(EditInvoice::class);
        usleep(max(0, (int) (($startAt - microtime(true)) * 1_000_000)));
        for ($save = 0; $save < $saves; ++$save) {
            $edit->edit($ids['invoices'][0], self::input($ids, self::lines(0 === $save % 2 ? 3 : 1, 'churn', '100.00')), $save + 1);
        }
    }

    /**
     * Keeps reading the first invoice while it changes.
     *
     * @return array{reads: int, torn: int} how many reads, and how many showed a header and lines that disagree
     */
    public static function read(string $json, float $startAt, float $seconds): array
    {
        $ids = self::ids($json);
        self::enter($ids);
        $documents = self::getContainer()->get(IssuedDocumentFactory::class);
        usleep(max(0, (int) (($startAt - microtime(true)) * 1_000_000)));
        $reads = 0;
        $torn = 0;
        while (microtime(true) < $startAt + $seconds) {
            $document = $documents->of($ids['invoices'][0]);
            ++$reads;
            $lines = array_sum(array_map(static fn (InvoiceLine $line): int => $line->net()->minor(), $document->lines));
            $torn += $lines === $document->net->minor() ? 0 : 1;
        }

        return [
            'reads' => $reads,
            'torn' => $torn,
        ];
    }

    /**
     * @return list<array{number: string, headerNet: int, lineNet: int, positions: list<int>, texts: list<string>, trashed: bool, delivered: bool, version: int}>
     */
    public static function inspect(string $json): array
    {
        $ids = self::ids($json);
        self::enter($ids);
        $store = self::getContainer()->get(InvoiceStore::class);
        $found = [];
        foreach ($ids['invoices'] as $id) {
            $invoice = $store->get($id);
            $lines = $store->lines($invoice);
            $found[] = [
                'number' => $invoice->number(),
                'headerNet' => $invoice->net()->minor(),
                'lineNet' => array_sum(array_map(static fn (InvoiceLine $line): int => $line->net()->minor(), $lines)),
                'positions' => array_map(static fn (InvoiceLine $line): int => $line->position(), $lines),
                'texts' => array_map(static fn (InvoiceLine $line): string => $line->text(), $lines),
                'trashed' => $invoice->isTrashed(),
                'delivered' => $invoice->isDelivered(),
                'version' => $invoice->version(),
            ];
        }

        return $found;
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

    /**
     * @return list<LineInput>
     */
    private static function lines(int $count, string $text, string $price): array
    {
        $lines = [];
        for ($i = 0; $i < $count; ++$i) {
            $lines[] = new LineInput($text, Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        }

        return $lines;
    }

    /**
     * @param array{firm: string, series: string, customer: string} $ids
     * @param list<LineInput>                                       $lines
     */
    private static function input(array $ids, array $lines, ?string $typedNumber = null): InvoiceInput
    {
        $day = new \DateTimeImmutable('2026-11-01');

        return new InvoiceInput($ids['firm'], $ids['series'], $typedNumber, $ids['customer'], null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, $lines);
    }
}
