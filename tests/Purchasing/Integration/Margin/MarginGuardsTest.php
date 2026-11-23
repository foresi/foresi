<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Margin;

use Foresi\Audit\Domain\AuditEntry;
use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\AssignDocumentInput;
use Foresi\Margin\Application\SetLineAllocationInput;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;

/**
 * Who works with the margin at the use cases, behind every surface: a guest
 * signed in is refused by the staff gate on every operation, and what the
 * staff decide is written to the audit log.
 */
final class MarginGuardsTest extends PurchasingTestCase
{
    public function testAGuestIsRefusedByTheStaffGateOnEveryOperation(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        $this->signIn($this->guest);

        $calls = [
            'read' => fn () => self::getContainer()->get(AllocationReads::class)->document($id),
            'set' => fn () => self::getContainer()->get(AllocationWrites::class)->setLine(new SetLineAllocationInput($id, 'rate:21', null)),
            'assign' => fn () => self::getContainer()->get(AllocationWrites::class)->assign(new AssignDocumentInput($id, 'own')),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail('A guest was let through: '.$name);
            } catch (MarginRefused $refused) {
                self::assertSame(['forbidden', RefusalKind::Forbidden], [$refused->reason(), $refused->kind()], $name);
            }
        }
        self::assertSame(0, $this->em->getRepository(DocumentAllocation::class)->count([]));
    }

    public function testWhatTheStaffDecideIsAudited(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        $writes = self::getContainer()->get(AllocationWrites::class);

        $writes->setLine(new SetLineAllocationInput($id, 'rate:21', 'whole', [['recipient' => 'own']]));
        $writes->assign(new AssignDocumentInput($id, 'own'));

        foreach ([LineAllocation::class, DocumentAllocation::class] as $class) {
            $entries = $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => $class]);
            self::assertCount(1, $entries, $class);
            self::assertNotNull($entries[0]->userId(), $class);
        }
    }
}
