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
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
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
        foreach ([Party::class, LegalEntity::class, Membership::class, AuditEntry::class] as $class) {
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
