<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Doctrine;

use App\Audit\Application\AuditActor;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditEntry;
use App\Audit\Domain\AuditExcluded;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantMismatch;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Clock\ClockInterface;

/**
 * Writes the audit log in the transaction of the change it describes.
 *
 * Updates and removals are persisted as AuditEntry entities during onFlush.
 * Creations cannot be: generated ids exist only after the insert, and Doctrine
 * dispatches postPersist once every insert of the flush has run but still
 * inside the transaction, so the entry is inserted there, directly.
 *
 * It also guards the log: updating or removing a persisted AuditEntry throws.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postPersist)]
final class AuditListener
{
    /** @var array<class-string, list<string>> */
    private array $excluded = [];

    private ?AuditValueNormalizer $normalizer = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof AuditEntry) {
                throw new \LogicException('Audit entries are append-only and cannot be updated or removed.');
            }
        }

        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            if ($collection->getOwner() instanceof Audited) {
                throw new \LogicException('Changes to collections of audited entities are not audited yet.');
            }
        }

        $entries = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Audited) {
                $this->tenantOf($entity);
                $changes = $this->updateChanges($em, $entity);
                if ([] !== $changes) {
                    $entries[] = $this->entry($em, $entity, AuditAction::Updated, $changes);
                }
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Audited) {
                $this->tenantOf($entity);
                $entries[] = $this->entry($em, $entity, AuditAction::Deleted, $this->snapshot($em, $entity, false));
            }
        }

        $metadata = $em->getClassMetadata(AuditEntry::class);
        foreach ($entries as $entry) {
            $em->persist($entry);
            $uow->computeChangeSet($metadata, $entry);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Audited) {
            return;
        }

        $em = $args->getObjectManager();
        $tenant = $this->tenantOf($entity);
        $entry = $this->entry($em, $entity, AuditAction::Created, $this->snapshot($em, $entity, true));
        $em->getConnection()->insert(
            $em->getClassMetadata(AuditEntry::class)->getTableName(),
            [
                'tenant_id' => $tenant,
                'entity_class' => $entry->entityClass(),
                'entity_id' => $entry->entityId(),
                'action' => $entry->action()->value,
                'changes' => $entry->changes(),
                'user_id' => $entry->userId(),
                'occurred_at' => $entry->occurredAt(),
            ],
            ['tenant_id' => 'tenant_id', 'changes' => 'json', 'occurred_at' => 'datetime_immutable'],
        );
    }

    /**
     * Same rule as StampTenantListener: an owned entity's tenant must be the
     * active one, for every kind of change, so an entry is never written under
     * a tenant other than the entity's own.
     */
    private function tenantOf(object $entity): TenantId
    {
        $active = $this->context->current();
        $tenant = $entity instanceof TenantOwned ? $entity->tenantId() : $active;
        if (!$tenant->equals($active)) {
            throw TenantMismatch::create();
        }

        return $tenant;
    }

    /**
     * @param array<string, array{mixed, mixed}> $changes
     */
    private function entry(EntityManagerInterface $em, object $entity, AuditAction $action, array $changes): AuditEntry
    {
        $identifier = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return new AuditEntry(
            $entity::class,
            implode('-', array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $identifier)),
            $action,
            $changes,
            $this->actor->userId(),
            $this->clock->now(),
        );
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    private function updateChanges(EntityManagerInterface $em, object $entity): array
    {
        $metadata = $em->getClassMetadata($entity::class);
        $changes = [];
        foreach ($em->getUnitOfWork()->getEntityChangeSet($entity) as $field => $pair) {
            if ($this->skipped($metadata, $field)) {
                continue;
            }
            $changes[$field] = [$this->value($em, $metadata, $field, $pair[0]), $this->value($em, $metadata, $field, $pair[1])];
        }

        return $changes;
    }

    /**
     * The current values of an entity as a creation ([null, value]) or a
     * removal ([value, null]) diff; empty values are left out.
     *
     * @return array<string, array{mixed, mixed}>
     */
    private function snapshot(EntityManagerInterface $em, object $entity, bool $created): array
    {
        $metadata = $em->getClassMetadata($entity::class);
        $fields = [...$metadata->getFieldNames()];
        foreach ($metadata->getAssociationNames() as $association) {
            if ($metadata->isSingleValuedAssociation($association) && false === $metadata->isAssociationInverseSide($association)) {
                $fields[] = $association;
            }
        }

        $changes = [];
        foreach ($fields as $field) {
            if ($this->skipped($metadata, $field)) {
                continue;
            }
            $value = $this->value($em, $metadata, $field, $metadata->getFieldValue($entity, $field));
            if (null !== $value) {
                $changes[$field] = $created ? [null, $value] : [$value, null];
            }
        }

        return $changes;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function value(EntityManagerInterface $em, ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        if ($metadata->hasAssociation($field)) {
            return \is_object($value) ? $this->associatedId($em, $value) : null;
        }

        return $this->normalizer($em)->normalize($value, $metadata->getTypeOfField($field) ?? 'string');
    }

    private function associatedId(EntityManagerInterface $em, object $entity): int|string|null
    {
        $id = $em->getUnitOfWork()->getSingleIdentifierValue($entity);

        return \is_int($id) || \is_string($id) ? $id : null;
    }

    private function normalizer(EntityManagerInterface $em): AuditValueNormalizer
    {
        return $this->normalizer ??= new AuditValueNormalizer($em->getConnection()->getDatabasePlatform());
    }

    /**
     * Identifiers and the tenant are implied by the entry itself; excluded
     * fields are never written.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function skipped(ClassMetadata $metadata, string $field): bool
    {
        return $metadata->isIdentifier($field)
            // An embedded object also appears as one field; its columns are logged individually.
            || isset($metadata->embeddedClasses[$field])
            || 'tenantId' === $field
            || \in_array($field, $this->excludedFields($metadata), true);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private function excludedFields(ClassMetadata $metadata): array
    {
        if (isset($this->excluded[$metadata->getName()])) {
            return $this->excluded[$metadata->getName()];
        }

        $roots = [];
        for ($class = $metadata->getReflectionClass(); $class instanceof \ReflectionClass; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ([] !== $property->getAttributes(AuditExcluded::class)) {
                    $roots[] = $property->getName();
                }
            }
        }

        $fields = [];
        foreach ([...$metadata->getFieldNames(), ...$metadata->getAssociationNames()] as $field) {
            if (\in_array(explode('.', $field)[0], $roots, true)) {
                $fields[] = $field;
            }
        }

        return $this->excluded[$metadata->getName()] = $fields;
    }
}
