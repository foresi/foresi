<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application\Query;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Purchasing\Application\Query\ReceivedDocumentHistory;
use App\Purchasing\Application\Query\ReceivedDocumentHistoryEntry;
use App\Purchasing\Application\RestoreReceivedDocument;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Domain\ReceivedDocument;
use App\Tests\Purchasing\Application\PurchasingTestCase;

final class ReceivedDocumentHistoryTest extends PurchasingTestCase
{
    /** @var list<list<string>> */
    private array $asked = [];

    public function testTheStepsOfARealDocumentAreToldOldestFirst(): void
    {
        $id = $this->draft();
        $this->clock->modify('+1 minute');
        $this->editing()->edit($id, $this->input());
        $this->clock->modify('+1 minute');
        $this->recording()->record($id, $this->input(supplierNumber: 'FV-2'));
        $this->clock->modify('+1 minute');
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Not ours');
        $this->clock->modify('+1 minute');
        self::getContainer()->get(RestoreReceivedDocument::class)->restore($id);
        $this->clock->modify('+1 minute');
        $this->editing()->edit($id, $this->input(supplierNumber: 'FV-3', base: 5000));

        $steps = self::getContainer()->get(ReceivedDocumentHistory::class)->of($this->document($id));

        self::assertSame(
            [
                ['09:00', 'Olga Owner', 'created', [], null],
                ['09:01', 'Olga Owner', 'changed', ['supplierNumber', 'variableSymbol', 'issueDate', 'taxPointDate', 'dueDate', 'receivedDate', 'total', 'vatRecap', 'supplier'], null],
                ['09:02', 'Olga Owner', 'recorded', [], null],
                ['09:03', 'Olga Owner', 'trashed', [], 'Not ours'],
                ['09:04', 'Olga Owner', 'restored', [], null],
                ['09:05', 'Olga Owner', 'changed', ['supplierNumber', 'total', 'vatRecap'], null],
            ],
            array_map(static fn (ReceivedDocumentHistoryEntry $step): array => [$step->occurredAt->format('H:i'), $step->userName, $step->event, $step->fields, $step->reason], $steps),
        );
    }

    public function testOnlyTheKnownUsersAreAskedForTheirNamesOnce(): void
    {
        $steps = $this->history([
            $this->entry(['kind' => ['a', 'b']], userId: 'user-1', at: '09:00'),
            $this->entry(['kind' => ['a', 'b']], userId: null, at: '09:01'),
            $this->entry(['kind' => ['a', 'b']], userId: 'user-2', at: '09:02'),
            $this->entry(['kind' => ['a', 'b']], userId: 'user-1', at: '09:03'),
            $this->entry(['kind' => ['a', 'b']], userId: null, at: '09:04'),
        ]);

        self::assertSame([['user-1', 'user-2']], $this->asked);
        self::assertSame(['Name of user-1', null, 'Name of user-2', 'Name of user-1', null], array_map(static fn (ReceivedDocumentHistoryEntry $step): ?string => $step->userName, $steps));
    }

    public function testEntriesOfOneActionAreOneStepAndTheFirstOneNamesTheUser(): void
    {
        $steps = $this->history([
            $this->entry(['kind' => ['a', 'b']], userId: 'user-1', at: '09:00', correlation: 'one'),
            $this->entry(['dueDate' => [null, '2026-11-11']], userId: 'user-2', at: '09:00', correlation: 'one'),
            $this->entry(['currency' => ['CZK', 'EUR']], userId: 'user-2', at: '09:01', correlation: 'two'),
            $this->entry(['currency' => ['EUR', 'CZK']], userId: 'user-2', at: '09:02', correlation: 'one'),
        ]);

        self::assertCount(3, $steps);
        self::assertSame(['kind', 'dueDate'], $steps[0]->fields);
        self::assertSame('Name of user-1', $steps[0]->userName);
        self::assertSame(['currency'], $steps[1]->fields);
        self::assertSame(['currency'], $steps[2]->fields, 'The same correlation later is another action.');
    }

    public function testEntriesWithoutACorrelationShareAStepWhenTheyShareTheMoment(): void
    {
        $steps = $this->history([
            $this->entry(['kind' => ['a', 'b']], userId: 'user-1', at: '09:00'),
            $this->entry(['dueDate' => [null, '2026-11-11']], userId: 'user-1', at: '09:00'),
            $this->entry(['currency' => ['CZK', 'EUR']], userId: 'user-1', at: '09:00:00.5'),
        ]);

        self::assertCount(2, $steps);
        self::assertSame(['kind', 'dueDate'], $steps[0]->fields);
        self::assertSame(['currency'], $steps[1]->fields);
    }

    public function testACreatedDocumentIsToldAsCreatedWhateverElseTheActionSaved(): void
    {
        $steps = $this->history([
            $this->entry(['kind' => [null, 'tax_invoice']], userId: 'user-1', at: '09:00', correlation: 'one', action: AuditAction::Created),
            $this->entry(['originalAttachmentId' => [null, 'file']], userId: 'user-1', at: '09:00', correlation: 'one'),
        ]);

        self::assertCount(1, $steps);
        self::assertSame(ReceivedDocumentHistoryEntry::CREATED, $steps[0]->event);
        self::assertSame([], $steps[0]->fields);
        self::assertNull($steps[0]->reason);
    }

    public function testEntriesOfOtherEntitiesTellNothing(): void
    {
        $steps = $this->history([
            $this->entry(['kind' => ['a', 'b']], userId: 'user-1', at: '09:00', correlation: 'one', entity: 'Other', action: AuditAction::Created),
            $this->entry(['kind' => ['a', 'b']], userId: 'user-1', at: '09:00', correlation: 'one', entity: 'Other'),
        ]);

        self::assertSame([], $steps);
    }

    public function testTheTrashKeepsItsReasonAndTheFieldsOnceInOrder(): void
    {
        $steps = $this->history([
            $this->entry([
                'deletedAt' => [null, '2026-11-10T09:00:00+00:00'],
                'deletedReason' => [null, 'Recorded twice'],
                'supplierParty' => ['a', 'b'],
                'supplier.name' => ['a', 'b'],
                'kind' => ['a', 'b'],
                'unknown' => ['a', 'b'],
            ], userId: 'user-1', at: '09:00'),
        ]);

        self::assertCount(1, $steps);
        self::assertSame(ReceivedDocumentHistoryEntry::TRASHED, $steps[0]->event);
        self::assertSame('Recorded twice', $steps[0]->reason);
        self::assertSame(['supplier', 'kind'], $steps[0]->fields);
    }

    public function testATrashWithoutAReasonHasNone(): void
    {
        $steps = $this->history([
            $this->entry(['deletedAt' => [null, '2026-11-10T09:00:00+00:00']], userId: 'user-1', at: '09:00', correlation: 'one'),
            $this->entry(['deletedAt' => [null, '2026-11-10T09:01:00+00:00'], 'deletedReason' => [null, null]], userId: 'user-1', at: '09:01', correlation: 'two'),
        ]);

        self::assertSame([ReceivedDocumentHistoryEntry::TRASHED, ReceivedDocumentHistoryEntry::TRASHED], array_column($steps, 'event'));
        self::assertSame([null, null], array_column($steps, 'reason'));
    }

    public function testARestoreHasNoReason(): void
    {
        $steps = $this->history([
            $this->entry(['deletedAt' => ['2026-11-10T09:00:00+00:00', null], 'deletedReason' => ['Recorded twice', null]], userId: 'user-1', at: '09:00', correlation: 'one'),
            $this->entry(['deletedAt' => ['2026-11-10T09:00:00+00:00', null], 'deletedReason' => ['Recorded twice', 'Another']], userId: 'user-1', at: '09:01', correlation: 'two'),
        ]);

        self::assertSame([ReceivedDocumentHistoryEntry::RESTORED, ReceivedDocumentHistoryEntry::RESTORED], array_column($steps, 'event'));
        self::assertSame([null, null], array_column($steps, 'reason'));
    }

    public function testRecordingNamesNoFields(): void
    {
        $steps = $this->history([
            $this->entry(['state' => ['draft', 'recorded'], 'supplierNumber' => ['a', 'b'], 'evidenceNumber' => [null, 'R20260001']], userId: 'user-1', at: '09:00'),
        ]);

        self::assertCount(1, $steps);
        self::assertSame(ReceivedDocumentHistoryEntry::RECORDED, $steps[0]->event);
        self::assertSame([], $steps[0]->fields);
    }

    public function testAChangeNamesEachFieldOnceInOrderAndASaveOfNothingVisibleIsLeftOut(): void
    {
        $steps = $this->history([
            $this->entry(['supplierParty' => ['a', 'b'], 'supplier.name' => ['a', 'b'], 'kind' => ['a', 'b'], 'exchangeRate' => ['1', '2'], 'exchangeRateDate' => ['a', 'b']], userId: 'user-1', at: '09:00', correlation: 'one'),
            $this->entry(['version' => [1, 2], 'baseMinor' => [1, 2]], userId: 'user-1', at: '09:01', correlation: 'two'),
        ]);

        self::assertCount(1, $steps);
        self::assertSame(ReceivedDocumentHistoryEntry::CHANGED, $steps[0]->event);
        self::assertSame(['supplier', 'kind', 'exchangeRate'], $steps[0]->fields);
        self::assertNull($steps[0]->reason);
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<ReceivedDocumentHistoryEntry>
     */
    private function history(array $entries): array
    {
        $this->asked = [];
        $trail = new readonly class($entries) implements AuditTrail {
            /** @param list<AuditEntry> $entries */
            public function __construct(private array $entries)
            {
            }

            public function changedSince(string $entityClass, string $entityId, \DateTimeImmutable $since, string ...$exceptCorrelationIds): bool
            {
                return false;
            }

            public function rootHistory(string $rootClass, string $rootId): array
            {
                return $this->entries;
            }
        };
        $users = new class implements UserRepository {
            /** @var list<list<string>> */
            public array $asked = [];

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
                $this->asked[] = $ids;
                $names = [];
                foreach ($ids as $id) {
                    $names[$id] = 'Name of '.$id;
                }

                return $names;
            }

            public function save(User $user): void
            {
            }
        };
        $steps = new ReceivedDocumentHistory($trail, $users)->of($this->document($this->draft()));
        $this->asked = $users->asked;

        return $steps;
    }

    /**
     * @param array<string, array<mixed>> $changes
     */
    private function entry(array $changes, ?string $userId, string $at, ?string $correlation = null, string $entity = ReceivedDocument::class, AuditAction $action = AuditAction::Updated): AuditEntry
    {
        return new AuditEntry($entity, 'entity-id', $action, $changes, $userId, new \DateTimeImmutable('2026-11-10 '.$at, new \DateTimeZone('UTC')), $correlation, ReceivedDocument::class, 'document-id');
    }
}
