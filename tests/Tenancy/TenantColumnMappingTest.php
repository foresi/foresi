<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Audit\Domain\AuditEntry;
use App\Banking\Domain\BankFeed;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\FirmPairing;
use App\Banking\Domain\InboundMessage;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Import\Domain\Import;
use App\Import\Domain\ImportAmbiguousMatch;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportRow;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\MailTemplate;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesCounter;
use App\Party\Domain\ContactLink;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyFirmSettings;
use App\Tenancy\Domain\ScheduledJobRun;
use App\Tenancy\Domain\TenantOwned;
use App\Work\Domain\Label;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;
use Doctrine\DBAL\Schema\Column;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantColumnMappingTest extends KernelTestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('tenantOwnedEntities')]
    public function testTenantColumnIsNotNullable(string $class): void
    {
        self::assertTrue($this->column($class, 'tenant_id')->getNotnull());
    }

    /**
     * A new tenant-owned entity cannot stay out of the list above.
     */
    public function testEveryTenantOwnedEntityIsListed(): void
    {
        self::bootKernel();
        $listed = array_keys(iterator_to_array(self::tenantOwnedEntities()));
        foreach (self::getContainer()->get('doctrine.orm.entity_manager')->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (is_subclass_of($metadata->getName(), TenantOwned::class) && !str_starts_with($metadata->getName(), 'App\\Tests\\')) {
                self::assertContains($metadata->getName(), $listed);
            }
        }
    }

    public function testMembershipUserIsRequired(): void
    {
        // Building one keeps the mapping attributes on the constructor covered for mutation testing.
        $membership = new Membership(new User('testing@example.com', 'hash', 'Example Company'), Role::Owner);

        self::assertSame(Role::Owner, $membership->role());
        self::assertTrue($this->column(Membership::class, 'user_id')->getNotnull());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function tenantOwnedEntities(): iterable
    {
        foreach ([Party::class, PartyAddress::class, PartyEmail::class, PartyBankAccount::class, ContactLink::class, LegalEntity::class, FirmBankAccount::class, Membership::class, Invitation::class, AuditEntry::class, Series::class, SeriesCounter::class, FirmInvoicing::class, FirmMailServer::class, MailTemplate::class, Invoice::class, InvoiceLine::class, InvoiceSend::class, AbandonedNumber::class, RecurringInvoice::class, RecurringInvoiceLine::class, FirmReminders::class, Payment::class, PaymentAllocation::class, BankMovement::class, PartyFirmSettings::class, Import::class, ImportRow::class, ImportProfile::class, ImportMissingRecord::class, ImportAmbiguousMatch::class, ScheduledJobRun::class, BankFeed::class, FirmPairing::class, InboundMessage::class, IdokladConnection::class, IdokladPull::class, IdokladRecord::class, IdokladLink::class, IdokladImportRun::class, IdokladJob::class, Project::class, ProjectStatus::class, Section::class, Task::class, TaskWatcher::class, Label::class, TaskLabel::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    private function column(string $class, string $name): Column
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $metadata = $em->getClassMetadata($class);
        $schema = new SchemaTool($em)->getSchemaFromMetadata([$metadata]);

        return $schema->getTable($metadata->getTableName())->getColumn($name);
    }
}
