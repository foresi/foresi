<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Application\SalesWork;
use Foresi\Shared\Application\Event\EventOutbox;

/**
 * The unit of work of Sales on the shared entity manager, as the catalog runs
 * its own: a unit inside another is part of it; on a failure the entity
 * manager is replaced; the outbox learns how the outermost unit ended. Two
 * people linking one project to two lines at once both pass the check of
 * DealLineWrites; the unique index stops the second, refused as the check would.
 */
final readonly class DoctrineSalesWork implements SalesWork
{
    /** The unique index on a line's project, as PostgreSQL and SQLite name it. */
    private const array PROJECT_INDEX = ['uniq_sales_deal_line_project', 'sales_deal_line.project_id'];

    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private EventOutbox $outbox,
    ) {
    }

    public function run(callable $work): mixed
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            return $work();
        }
        try {
            $result = $this->em->wrapInTransaction($work);
        } catch (\Throwable $failure) {
            $this->outbox->rolledBack();
            $this->registry->resetManager();
            if ($failure instanceof UniqueConstraintViolationException && self::isProjectIndex($failure)) {
                throw SalesRefused::conflict('project_already_linked', 'The project was just linked to another line; a project serves one hourly line. Unlink it there first.', 'projectId');
            }

            throw $failure;
        }
        $this->outbox->committed();

        return $result;
    }

    private static function isProjectIndex(UniqueConstraintViolationException $failure): bool
    {
        foreach (self::PROJECT_INDEX as $name) {
            if (str_contains($failure->getMessage(), $name)) {
                return true;
            }
        }

        return false;
    }
}
