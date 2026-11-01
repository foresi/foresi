<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Audit\Domain\AuditEntry;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Import\Domain\Import;
use App\Import\Domain\ImportAmbiguousMatch;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportRow;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesCounter;
use App\Party\Domain\ContactLink;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyFirmSettings;
use App\Tenancy\Domain\TenantOwned;
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
        foreach ([Party::class, PartyAddress::class, PartyEmail::class, PartyBankAccount::class, ContactLink::class, LegalEntity::class, Membership::class, AuditEntry::class, Series::class, SeriesCounter::class, FirmInvoicing::class, Invoice::class, InvoiceLine::class, AbandonedNumber::class, PartyFirmSettings::class, Import::class, ImportRow::class, ImportProfile::class, ImportMissingRecord::class, ImportAmbiguousMatch::class] as $class) {
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
