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
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Stamps the active tenant on new tenant-owned entities. Entities expose no
 * setter, so the value can only be written here, once, on insert.
 */
#[AsDoctrineListener(event: Events::prePersist)]
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
}
