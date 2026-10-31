<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantMismatch;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMapping;
use Doctrine\ORM\Proxy\DefaultProxyClassNameResolver;

/**
 * Stamps the active tenant on new tenant-owned entities. Entities expose no
 * setter, so the value can only be written here, once, on insert. On flush it
 * also refuses a single-valued association from a tenant-owned entity to an
 * entity of another tenant. That runs on flush, not on persist, because by then
 * every new entity of the unit of work has been stamped, whatever the order of
 * persist calls. Targets that are not tenant-owned (a User, say) are skipped.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class StampTenantListener
{
    private const string FIELD = 'tenantId';

    public function __construct(private TenantContext $context)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TenantOwned) {
            return;
        }

        $tenant = $this->context->current();
        $metadata = $args->getObjectManager()->getClassMetadata($entity::class);
        if (!$metadata->hasField(self::FIELD)) {
            throw new \LogicException(sprintf('%s implements TenantOwned but does not map the "%s" field.', $entity::class, self::FIELD));
        }

        $existing = $metadata->getFieldValue($entity, self::FIELD);
        if ($existing instanceof TenantId) {
            if (!$tenant->equals($existing)) {
                throw TenantMismatch::create();
            }

            return;
        }

        $metadata->setFieldValue($entity, self::FIELD, $tenant);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof TenantOwned) {
                $this->assertReferencesShareTenant($em, $entity);
            }
        }
    }

    private function assertReferencesShareTenant(EntityManagerInterface $em, TenantOwned $entity): void
    {
        $metadata = $em->getClassMetadata($entity::class);
        $own = $this->tenantOf($metadata, $entity);

        foreach ($metadata->getAssociationMappings() as $mapping) {
            if (!$mapping instanceof ToOneAssociationMapping) {
                continue;
            }

            $target = $metadata->getFieldValue($entity, $mapping->fieldName);
            if (!$target instanceof TenantOwned) {
                continue;
            }

            try {
                if ($em->getUnitOfWork()->isUninitializedObject($target)) {
                    $em->getUnitOfWork()->initializeObject($target);
                }
                $theirs = $this->tenantOf($em->getClassMetadata(DefaultProxyClassNameResolver::getClass($target)), $target);
            } catch (EntityNotFoundException) {
                // An uninitialized proxy the tenant filter hides: it belongs elsewhere.
                throw TenantMismatch::create();
            }

            if (!$own->equals($theirs)) {
                throw TenantMismatch::create();
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function tenantOf(ClassMetadata $metadata, TenantOwned $entity): TenantId
    {
        $tenant = $metadata->getFieldValue($entity, self::FIELD);

        return $tenant instanceof TenantId ? $tenant : $this->context->current();
    }
}
