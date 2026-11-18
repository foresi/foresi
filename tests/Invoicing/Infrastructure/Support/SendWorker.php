<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Support;

use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Invoicing\Application\DeliverInvoiceSend;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\SendInvoice;
use Foresi\Invoicing\Application\SendRefused;
use Foresi\Invoicing\Application\SendRequest;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
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
 * @phpstan-type Ids array{invoices: list<string>, sends: list<string>}
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
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register');
        $em->persist($firm);
        FirmAccounts::add($em, $firm, self::IBAN);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $container->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        $em->flush();

        $ids = ['invoices' => [], 'sends' => []];
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
        self::enter();
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
                    'deliver' => self::deliver($bus, $gateway, $ids['sends'][$slot]),
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
        self::enter();
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

    private static function deliver(MessageBusInterface $bus, RecordingMailGateway $gateway, string $send): string
    {
        $before = \count($gateway->attempts());
        $queue = self::getContainer()->get('messenger.transport.mail');
        \PHPUnit\Framework\Assert::assertInstanceOf(InMemoryTransport::class, $queue);
        $queued = \count($queue->getSent());
        $bus->dispatch(new Envelope(new DeliverInvoiceSend($send), [new ReceivedStamp('mail')]));
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
