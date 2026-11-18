<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Support;

use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\RecurringInput;
use Foresi\Invoicing\Application\RecurringLifecycle;
use Foresi\Invoicing\Application\RecurringRefused;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Invoicing\Domain\Percent;
use Foresi\Invoicing\Domain\PriceType;
use Foresi\Invoicing\Domain\Quantity;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Invoicing\Domain\TaxTreatment;
use Foresi\Invoicing\Domain\VatRateKind;
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
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What recurring-race.php runs: issuing an occurrence, pausing and editing the
 * template in parallel processes, in time slots shared with the others, one
 * template per slot. Each template is monthly from today, so today is its next
 * occurrence; every edit comes from a form opened at the first version.
 *
 * @phpstan-type Ids array{firm: string, series: string, customer: string, today: string, templates: list<string>}
 * @phpstan-type Stored array{state: string, next: string|null, issued: int, invoices: list<list<string>>}
 */
abstract class RecurringWorker extends KernelTestCase
{
    /** @var list<string> the line texts a template is saved with */
    public const array OLD_LINES = ['Hosting {month/year}'];
    /** @var list<string> the line texts an edit gives it */
    public const array NEW_LINES = ['Support {month/year}', 'Licence'];

    private const string IBAN = 'CZ6508000000192000145399';
    private const float SLOT = 0.6;

    /**
     * Rebuilds the schema and saves the templates.
     *
     * @return Ids
     */
    public static function seed(int $templates): array
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
        $em->flush();

        $ids = ['firm' => $firm->id(), 'series' => $series->id(), 'customer' => $customer->id(), 'today' => $container->get(InstanceClock::class)->today()->format('Y-m-d'), 'templates' => []];
        $save = $container->get(SaveRecurringInvoice::class);
        for ($i = 0; $i < $templates; ++$i) {
            $ids['templates'][] = $save->create(self::input($ids, self::OLD_LINES))->id();
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
        $occurrences = $container->get(IssueRecurringOccurrence::class);
        $lifecycle = $container->get(RecurringLifecycle::class);
        $save = $container->get(SaveRecurringInvoice::class);
        $today = new \DateTimeImmutable($ids['today']);
        $results = [];
        $began = [];
        $ended = [];
        foreach ($ids['templates'] as $slot => $template) {
            usleep(max(0, (int) (($startAt + $slot * self::SLOT - microtime(true)) * 1_000_000)));
            $began[] = microtime(true);
            try {
                $results[] = match ($job) {
                    'issue' => null === $occurrences->issue($template, $today) ? 'taken' : 'issued',
                    'pause' => self::paused($lifecycle, $template),
                    'edit' => '' !== $save->edit($template, 1, self::input($ids, self::NEW_LINES))->id() ? 'edited' : 'no id',
                    default => throw new \InvalidArgumentException('Unknown job.'),
                };
            } catch (RecurringRefused $refused) {
                $results[] = 'refused:'.$refused->problems[0]->key;
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
     * @return list<Stored>
     */
    public static function inspect(string $json): array
    {
        $ids = self::ids($json);
        self::enter();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $found = [];
        foreach ($ids['templates'] as $template) {
            $row = $connection->fetchAssociative('SELECT state, next_occurrence, issued_count FROM recurring_invoice WHERE id = ?', [$template]);
            \assert(\is_array($row));
            $invoices = [];
            foreach ($connection->fetchFirstColumn('SELECT id FROM invoice WHERE recurring_invoice_id = ? ORDER BY recurring_occurrence', [$template]) as $invoice) {
                /** @var list<string> $texts */
                $texts = $connection->fetchFirstColumn('SELECT text FROM invoice_line WHERE invoice_id = ? ORDER BY position', [$invoice]);
                $invoices[] = $texts;
            }
            \assert(\is_string($row['state']) && \is_int($row['issued_count']));
            $found[] = [
                'state' => $row['state'],
                'next' => \is_string($row['next_occurrence']) ? $row['next_occurrence'] : null,
                'issued' => $row['issued_count'],
                'invoices' => $invoices,
            ];
        }

        return $found;
    }

    private static function paused(RecurringLifecycle $lifecycle, string $template): string
    {
        $lifecycle->pause($template);

        return 'paused';
    }

    /**
     * @param Ids          $ids
     * @param list<string> $texts
     */
    private static function input(array $ids, array $texts): RecurringInput
    {
        $lines = array_map(static fn (string $text): LineInput => new LineInput($text, Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')), $texts);
        $recurrence = new Recurrence(new \DateTimeImmutable($ids['today']), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null);

        return new RecurringInput($ids['firm'], $ids['series'], $ids['customer'], PaymentMethod::BankTransfer, self::IBAN, '', '', 'Hosting {month/year}', '', '', DocumentLanguage::Czech, $lines, $recurrence, TaxPointRule::IssueDate, 14, RecurringDelivery::Send);
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
