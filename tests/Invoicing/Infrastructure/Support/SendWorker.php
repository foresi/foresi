<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Support;

use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendRefused;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\MailEncryption;
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
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What send-race.php runs: sending, delivering and trashing in parallel
 * processes, in time slots shared with the others, one invoice per slot. The
 * mail gateway records and takes a while, as a real server does, so a second
 * worker arrives while the first one is still talking to it.
 *
 * @phpstan-type Ids array{tenant: string, invoices: list<string>, sends: list<string>}
 */
abstract class SendWorker extends KernelTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const float SLOT = 0.6;
    private const int SERVER_TALKS = 400_000;

    /**
     * Rebuilds the schema and issues the invoices, each with one queued send when asked.
     *
     * @return Ids
     */
    public static function seed(int $invoices, bool $queued): array
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
        $em->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $container->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        $em->flush();

        $ids = ['tenant' => $tenant->toString(), 'invoices' => [], 'sends' => []];
        $issue = $container->get(IssueInvoice::class);
        $sending = $container->get(SendInvoice::class);
        $day = new \DateTimeImmutable('2026-11-01');
        for ($i = 0; $i < $invoices; ++$i) {
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $invoice = $issue->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
            $ids['invoices'][] = $invoice->id();
            if ($queued) {
                $ids['sends'][] = $sending->send(self::request($invoice->id()))->id();
            }
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
        $bus = $container->get(MessageBusInterface::class);
        $trash = $container->get(TrashInvoice::class);
        $sending = $container->get(SendInvoice::class);
        $gateway = new RecordingMailGateway();
        RecordingMailGateway::slowDown(self::SERVER_TALKS);
        $results = [];
        $began = [];
        $ended = [];
        foreach ($ids['invoices'] as $slot => $invoice) {
            usleep(max(0, (int) (($startAt + $slot * self::SLOT - microtime(true)) * 1_000_000)));
            $began[] = microtime(true);
            try {
                $results[] = match ($job) {
                    'deliver' => self::deliver($bus, $gateway, $ids['tenant'], $ids['sends'][$slot]),
                    'trash' => $trash->trash($invoice, 'Wrong customer')->isTrashed() ? 'done' : 'not trashed',
                    'send' => '' !== $sending->send(self::request($invoice))->id() ? 'done' : 'no id',
                    default => throw new \InvalidArgumentException('Unknown job.'),
                };
            } catch (SendRefused $refused) {
                $results[] = 'refused:'.$refused->reason;
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
     * @return list<array{trashed: bool, delivered: bool, sends: list<array{status: string, revoked: bool}>}>
     */
    public static function inspect(string $json): array
    {
        $ids = self::ids($json);
        self::enter($ids);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $found = [];
        foreach ($ids['invoices'] as $invoice) {
            $row = $connection->fetchAssociative('SELECT deleted_at, delivered_at FROM invoice WHERE id = ?', [$invoice]);
            \assert(\is_array($row));
            $sends = [];
            foreach ($connection->fetchAllAssociative('SELECT status, revoked_at FROM invoice_send WHERE invoice_id = ? ORDER BY created_at', [$invoice]) as $send) {
                \assert(\is_string($send['status']));
                $sends[] = ['status' => $send['status'], 'revoked' => null !== $send['revoked_at']];
            }
            $found[] = ['trashed' => null !== $row['deleted_at'], 'delivered' => null !== $row['delivered_at'], 'sends' => $sends];
        }

        return $found;
    }

    private static function deliver(MessageBusInterface $bus, RecordingMailGateway $gateway, string $tenant, string $send): string
    {
        $before = \count($gateway->attempts());
        $queue = self::getContainer()->get('messenger.transport.mail');
        \assert($queue instanceof InMemoryTransport);
        $queued = \count($queue->getSent());
        $bus->dispatch(new Envelope(new DeliverInvoiceSend($send), [new ReceivedStamp('mail'), new TenantStamp($tenant)]));
        $mailed = \count($gateway->attempts()) > $before;
        // The other worker holds the claim: the message is put back for when its lease ends.
        if (\count($queue->getSent()) > $queued) {
            return $mailed ? 'mailed and waiting' : 'waiting';
        }

        return $mailed ? 'mailed' : 'left alone';
    }

    private static function request(string $invoice): SendRequest
    {
        return new SendRequest($invoice, ['billing@example.com'], [], false, 'Invoice 20260001', "Invoice 20260001\n{link}");
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
