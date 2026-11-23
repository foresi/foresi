<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Audit\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Audit\Application\TokenAuditActor;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\View\SavedView;
use Symfony\Component\Clock\ClockInterface;

/**
 * Writes the audit log in the transaction of the change it describes.
 *
 * Creations, updates and removals are persisted as AuditEntry entities during
 * onFlush: ids are assigned when an entity is constructed, so a new entity
 * already has the id its entry names.
 *
 * It also guards the log: updating or removing a persisted AuditEntry throws.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AuditListener
{
    /** @var array<class-string, list<string>> */
    private array $excluded = [];

    private ?AuditValueNormalizer $normalizer = null;

    public function __construct(
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
        private readonly AuditCorrelation $correlation,
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
            if (null !== $collection->getOwner() && $this->audited($collection->getOwner()) && !$this->itemsAuditedOnTheirOwn($collection)) {
                throw new \LogicException('Changes to collections of audited entities are not audited yet.');
            }
        }

        $entries = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->audited($entity)) {
                $entries[] = $this->entry($em, $entity, AuditAction::Created, $this->snapshot($em, $entity, true));
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->audited($entity)) {
                $changes = $this->updateChanges($em, $entity);
                if ([] !== $changes) {
                    $entries[] = $this->entry($em, $entity, AuditAction::Updated, $changes);
                }
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->audited($entity)) {
                $entries[] = $this->entry($em, $entity, AuditAction::Deleted, $this->snapshot($em, $entity, false));
            }
        }

        $metadata = $em->getClassMetadata(AuditEntry::class);
        foreach ($entries as $entry) {
            $em->persist($entry);
            $uow->computeChangeSet($metadata, $entry);
        }
    }

    /**
     * The audited entities: those of the modules mark themselves Audited; the
     * shared kernel cannot know the Audit module, so its saved views are named here.
     */
    private function audited(object $entity): bool
    {
        return $entity instanceof Audited || $entity instanceof SavedView;
    }

    /**
     * A one-to-many collection keeps nothing of its own: each item holds the
     * reference, so adding, changing or removing an audited item is an entry of
     * the item. A many-to-many collection (a join table), owning or inverse, has
     * no such entry.
     *
     * @param PersistentCollection<array-key, object> $collection
     */
    private function itemsAuditedOnTheirOwn(PersistentCollection $collection): bool
    {
        return $collection->getMapping()->isOneToMany()
            && $collection->getTypeClass()->getReflectionClass()->implementsInterface(Audited::class);
    }

    /**
     * @param array<string, array{mixed, mixed}> $changes
     */
    private function entry(EntityManagerInterface $em, object $entity, AuditAction $action, array $changes): AuditEntry
    {
        $root = $entity instanceof AuditRooted ? $entity->auditRoot() : $entity;

        return new AuditEntry(
            $entity::class,
            $this->identifier($em, $entity),
            $action,
            $changes,
            $this->actor->userId(),
            $this->actor->kind(),
            $this->clock->now(),
            $this->correlation->current(),
            $em->getClassMetadata($root::class)->getName(),
            $this->identifier($em, $root),
            $this->actor instanceof TokenAuditActor ? $this->actor->apiTokenId() : null,
        );
    }

    private function identifier(EntityManagerInterface $em, object $entity): string
    {
        $identifier = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return implode('-', array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $identifier));
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

    private function associatedId(EntityManagerInterface $em, object $entity): ?string
    {
        $id = $em->getUnitOfWork()->getSingleIdentifierValue($entity);

        return \is_string($id) ? $id : null;
    }

    private function normalizer(EntityManagerInterface $em): AuditValueNormalizer
    {
        return $this->normalizer ??= new AuditValueNormalizer($em->getConnection()->getDatabasePlatform());
    }

    /**
     * Identifiers are implied by the entry itself; excluded fields are never
     * written.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function skipped(ClassMetadata $metadata, string $field): bool
    {
        return $metadata->isIdentifier($field)
            // An embedded object also appears as one field; its columns are logged individually.
            || isset($metadata->embeddedClasses[$field])
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
