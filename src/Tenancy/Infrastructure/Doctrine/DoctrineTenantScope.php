<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Application\TenantScope;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTenantScope implements TenantScope
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function activate(TenantId $tenant): void
    {
        $this->entityManager->clear();
        $filters = $this->entityManager->getFilters();
        $filter = $filters->isEnabled(TenantFilter::NAME) ? $filters->getFilter(TenantFilter::NAME) : $filters->enable(TenantFilter::NAME);
        $filter->setParameter(TenantFilter::PARAMETER, $tenant->toString(), 'string');
    }

    public function deactivate(): void
    {
        $this->entityManager->clear();
        $filters = $this->entityManager->getFilters();
        // A fresh filter instance has no parameter, so queries fail closed.
        if ($filters->isEnabled(TenantFilter::NAME)) {
            $filters->disable(TenantFilter::NAME);
        }
        $filters->enable(TenantFilter::NAME);
    }
}
