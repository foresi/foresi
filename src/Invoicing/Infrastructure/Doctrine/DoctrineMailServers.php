<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\MailServers;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Party\Domain\LegalEntity;

final readonly class DoctrineMailServers implements MailServers
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function for(LegalEntity $firm): ?FirmMailServer
    {
        $stored = $this->em->createQuery('SELECT s FROM '.FirmMailServer::class.' s WHERE s.firm = :firm')
            ->setParameter('firm', $firm->id())
            ->getOneOrNullResult();

        return $stored instanceof FirmMailServer ? $stored : null;
    }

    public function save(FirmMailServer $server): void
    {
        $this->em->persist($server);
    }
}
