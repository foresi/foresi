<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Adds "tenant_id = :tenant" to every query on a TenantOwned entity. It is
 * enabled by default and throws NoActiveTenant when no tenant is active. The
 * only sanctioned way to read across tenants is TenantFilterBypass, which
 * switches the filter off for the duration of one scalar-only callback and
 * restores it. Do not disable the filter anywhere else.
 */
final class TenantFilter extends SQLFilter
{
    public const string NAME = 'tenant';
    public const string PARAMETER = 'tenant';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantOwned::class)) {
            return '';
        }

        // Fail closed: a tenant-owned query without an active tenant must not run.
        if (!$this->hasParameter(self::PARAMETER)) {
            throw NoActiveTenant::create();
        }

        return sprintf(
            '%s.%s = %s',
            $targetTableAlias,
            $targetEntity->getColumnName('tenantId'),
            $this->getParameter(self::PARAMETER),
        );
    }
}
