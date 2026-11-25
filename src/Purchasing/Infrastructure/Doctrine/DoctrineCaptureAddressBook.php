<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Purchasing\Application\Mail\CaptureAddressBook;
use Foresi\Purchasing\Domain\CaptureAddress;

final readonly class DoctrineCaptureAddressBook implements CaptureAddressBook
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function activeOf(string $ownerUserId): ?CaptureAddress
    {
        $address = $this->entityManager->createQuery('SELECT a FROM '.CaptureAddress::class.' a WHERE a.ownerUserId = :owner AND a.revokedAt IS NULL')
            ->setParameter('owner', $ownerUserId)
            ->getOneOrNullResult();

        return $address instanceof CaptureAddress ? $address : null;
    }

    public function ofMailAddress(string $mailAddressId): ?CaptureAddress
    {
        $address = $this->entityManager->createQuery('SELECT a FROM '.CaptureAddress::class.' a WHERE a.mailAddressId = :id')
            ->setParameter('id', $mailAddressId)
            ->getOneOrNullResult();

        return $address instanceof CaptureAddress ? $address : null;
    }

    public function add(CaptureAddress $address): void
    {
        $this->entityManager->persist($address);
    }
}
