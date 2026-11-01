<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Invoicing\Application\InvoiceHistory;
use App\Invoicing\Application\InvoiceHistoryEntry;
use App\Invoicing\Application\InvoiceHistoryLine;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class InvoiceHistoryTest extends InvoiceTestCase
{
    private const string USER = '01963c5e-0000-7000-8000-00000000000a';
    private const string LINE = '01963c5e-0000-7000-8000-00000000000b';

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testASaveByAMachineNamesNoPerson(): void
    {
        Clock::set(new MockClock('2026-11-01 12:00:00', 'UTC'));
        $invoice = $this->issue->issue($this->input());

        $history = self::getContainer()->get(InvoiceHistory::class)->of($invoice);

        self::assertCount(1, $history);
        self::assertSame(InvoiceHistoryEntry::CREATED, $history[0]->event);
        self::assertNull($history[0]->userName);
        self::assertSame([], $history[0]->fields);
        self::assertSame([], $history[0]->lines);
        self::assertNull($history[0]->reason);
        self::assertSame('2026-11-01 12:00', $history[0]->occurredAt->format('Y-m-d H:i'));
    }

    public function testAnEditIsOneStepWithItsFieldsAndLines(): void
    {
        $clock = new MockClock('2026-11-01 12:00:00', 'UTC');
        Clock::set($clock);
        $invoice = $this->issue->issue($this->input());
        $clock->sleep(5);
        $this->edited($invoice, $this->input([$this->line(price: '2000.00', text: 'Better work'), $this->line(text: 'Second')], ['description' => 'Changed', 'orderNumber' => 'PO-2']));
        $clock->sleep(5);
        $this->edited($invoice, $this->input([$this->line(price: '2000.00', text: 'Better work')], ['description' => 'Changed', 'orderNumber' => 'PO-2']));

        $history = self::getContainer()->get(InvoiceHistory::class)->of($this->reloaded($invoice));

        self::assertSame([InvoiceHistoryEntry::CREATED, InvoiceHistoryEntry::CHANGED, InvoiceHistoryEntry::CHANGED], array_map(static fn (InvoiceHistoryEntry $entry): string => $entry->event, $history));
        self::assertSame(['orderNumber', 'description', 'payableMinor'], $history[1]->fields);
        self::assertSame([
            [2, 'Second', InvoiceHistoryLine::ADDED],
            [1, 'Better work', InvoiceHistoryLine::CHANGED],
        ], array_map(static fn (InvoiceHistoryLine $line): array => [$line->number, $line->text, $line->change], $history[1]->lines));
        self::assertSame(['text', 'priceMinor'], $history[1]->lines[1]->fields);
        self::assertSame([[2, 'Second', InvoiceHistoryLine::REMOVED]], array_map(static fn (InvoiceHistoryLine $line): array => [$line->number, $line->text, $line->change], $history[2]->lines));
    }

    public function testTrashRestoreAndDeliveryAreSteps(): void
    {
        $clock = new MockClock('2026-11-01 12:00:00', 'UTC');
        Clock::set($clock);
        $invoice = $this->issue->issue($this->input());
        $clock->sleep(5);
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $clock->sleep(5);
        $this->trash->restore($invoice->id());
        $clock->sleep(5);
        $saved = $this->delivered->mark($invoice->id());

        $history = self::getContainer()->get(InvoiceHistory::class)->of($saved);

        self::assertSame([InvoiceHistoryEntry::CREATED, InvoiceHistoryEntry::TRASHED, InvoiceHistoryEntry::RESTORED, InvoiceHistoryEntry::DELIVERED], array_map(static fn (InvoiceHistoryEntry $entry): string => $entry->event, $history));
        self::assertSame('Wrong customer', $history[1]->reason);
        self::assertNull($history[2]->reason);
        self::assertSame([[], [], [], []], array_map(static fn (InvoiceHistoryEntry $entry): array => $entry->fields, $history));
    }

    public function testACopiedPartyIsOneFieldAndDerivedFieldsStayQuiet(): void
    {
        $history = $this->historyOf([
            $this->entry(Invoice::class, AuditAction::Updated, ['customer.name' => ['A', 'B'], 'customer.address.city' => ['C', 'D'], 'customerParty' => ['x', 'y'], 'numberKey' => ['1', '2'], 'number' => ['1', '2'], 'netMinor' => [1, 2], 'vatMinor' => [1, 2], 'roundingMinor' => [1, 2]]),
            $this->entry(InvoiceLine::class, AuditAction::Updated, ['invoice' => ['a', 'b'], 'position' => [0, 0], 'rate' => ['1', '2'], 'unitNetMinor' => [1, 2], 'netMinor' => [1, 2], 'vatMinor' => [1, 2], 'grossMinor' => [1, 2]]),
        ]);

        self::assertCount(1, $history);
        self::assertSame(['customer', 'number'], $history[0]->fields);
        self::assertSame([], $history[0]->lines, 'A line with only derived changes is no change to show.');
    }

    public function testStepsAreKeptApartByPersonAndSecondAndOnlyThisDocumentsEntitiesCount(): void
    {
        $at = '2026-11-01 12:00:00.100000';
        $history = $this->historyOf([
            $this->entry(Invoice::class, AuditAction::Updated, ['description' => ['a', 'b']], at: $at),
            $this->entry(Invoice::class, AuditAction::Updated, ['orderNumber' => ['a', 'b']], at: '2026-11-01 12:00:00.900000'),
            $this->entry(Invoice::class, AuditAction::Updated, ['textAfter' => ['a', 'b']], at: '2026-11-01 12:00:00.950000', user: null),
            $this->entry(Invoice::class, AuditAction::Updated, ['textBefore' => ['a', 'b']], at: '2026-11-01 12:00:01.000000', user: null),
            $this->entry(AbandonedNumber::class, AuditAction::Created, ['number' => [null, '1']], at: '2026-11-01 12:00:01.000000', user: null),
        ]);

        self::assertSame([['description', 'orderNumber'], ['textAfter'], ['textBefore']], array_map(static fn (InvoiceHistoryEntry $entry): array => $entry->fields, $history));
        self::assertSame(['Example User', null, null], array_map(static fn (InvoiceHistoryEntry $entry): ?string => $entry->userName, $history));
    }

    public function testAPersonWhoLeftHasNoName(): void
    {
        $history = $this->historyOf([$this->entry(Invoice::class, AuditAction::Updated, ['description' => ['a', 'b']], user: '01963c5e-0000-7000-8000-00000000000c')]);

        self::assertNull($history[0]->userName);
    }

    public function testALineChangedLaterKeepsItsPlaceAndAFieldRepeatedInAStepShowsOnce(): void
    {
        $history = $this->historyOf([
            $this->entry(InvoiceLine::class, AuditAction::Created, ['position' => [null, 2], 'text' => [null, 'Third']], at: '2026-11-01 12:00:00'),
            $this->entry(InvoiceLine::class, AuditAction::Updated, ['discount' => ['0', '10']], at: '2026-11-01 12:01:00'),
            $this->entry(InvoiceLine::class, AuditAction::Updated, ['text' => ['Third', 'Renamed']], at: '2026-11-01 12:02:00'),
            $this->entry(Invoice::class, AuditAction::Updated, ['customer.name' => ['a', 'b'], 'customer.email' => ['c', 'd']], at: '2026-11-01 12:03:00'),
            $this->entry(Invoice::class, AuditAction::Updated, ['customer.name' => ['b', 'c']], at: '2026-11-01 12:03:00'),
        ]);

        self::assertSame([3, 'Third', ['discount']], [$history[1]->lines[0]->number, $history[1]->lines[0]->text, $history[1]->lines[0]->fields]);
        self::assertSame([3, 'Renamed', ['text']], [$history[2]->lines[0]->number, $history[2]->lines[0]->text, $history[2]->lines[0]->fields]);
        self::assertSame(['customer'], $history[3]->fields);
    }

    public function testAChangeOfTheReasonInLaterStepsDoesNotEraseItAndARestoreHasNone(): void
    {
        $history = $this->historyOf([
            $this->entry(Invoice::class, AuditAction::Updated, ['deletedAt' => [null, '2026-11-01'], 'deletedReason' => [null, 'Why'], 'deletedBy' => [null, self::USER]], at: '2026-11-01 12:00:00'),
            $this->entry(Invoice::class, AuditAction::Updated, ['deletedAt' => ['2026-11-01', null], 'deletedReason' => ['Why', null], 'deletedBy' => [self::USER, null]], at: '2026-11-01 12:05:00'),
        ]);

        self::assertSame([InvoiceHistoryEntry::TRASHED, InvoiceHistoryEntry::RESTORED], [$history[0]->event, $history[1]->event]);
        self::assertSame(['Why', null], [$history[0]->reason, $history[1]->reason]);
        self::assertSame([[], []], [$history[0]->fields, $history[1]->fields]);
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<InvoiceHistoryEntry>
     */
    private function historyOf(array $entries): array
    {
        $invoice = $this->issue->issue($this->input());
        $trail = new readonly class($entries) implements AuditTrail {
            /**
             * @param list<AuditEntry> $entries
             */
            public function __construct(private array $entries)
            {
            }

            public function changedSince(string $entityClass, string $entityId, \DateTimeImmutable $since, string ...$exceptCorrelationIds): bool
            {
                return false;
            }

            public function rootHistory(string $rootClass, string $rootId): array
            {
                return Invoice::class === $rootClass ? $this->entries : [];
            }
        };
        $users = new class implements UserRepository {
            public function findByEmail(string $email): ?User
            {
                return null;
            }

            public function findById(string $id): ?User
            {
                return null;
            }

            public function namesOf(array $ids): array
            {
                return array_intersect_key(['01963c5e-0000-7000-8000-00000000000a' => 'Example User'], array_flip($ids));
            }

            public function save(User $user): void
            {
            }
        };

        return new InvoiceHistory($trail, $users)->of($invoice);
    }

    /**
     * @param class-string                $class
     * @param array<string, array<mixed>> $changes
     */
    private function entry(string $class, AuditAction $action, array $changes, string $at = '2026-11-01 12:00:00', ?string $user = self::USER): AuditEntry
    {
        return new AuditEntry($class, self::LINE, $action, $changes, $user, new \DateTimeImmutable($at, new \DateTimeZone('UTC')));
    }
}
