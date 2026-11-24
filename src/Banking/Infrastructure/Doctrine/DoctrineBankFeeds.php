<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\AddressRefusal;
use Foresi\Banking\Application\AddressRefused;
use Foresi\Banking\Application\BankFeeds;
use Foresi\Banking\Domain\BankFeed;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;

/**
 * Through the ORM, except touch(), which is plain SQL by the feed's id.
 */
final readonly class DoctrineBankFeeds implements BankFeeds
{
    private const string TOUCH = 'UPDATE bank_feed SET last_received_at = :at WHERE id = :id AND (last_received_at IS NULL OR last_received_at < :at)';

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private AddressIssuer $issuer,
    ) {
    }

    public function ofAccount(string $accountId): ?BankFeed
    {
        return $this->ofAccounts([$accountId])[$accountId] ?? null;
    }

    public function ofAccounts(array $accountIds): array
    {
        if ([] === $accountIds) {
            return [];
        }
        /** @var list<BankFeed> $feeds */
        $feeds = $this->em->createQuery('SELECT f, a FROM '.BankFeed::class.' f JOIN f.account a WHERE a.id IN (:ids)')
            ->setParameter('ids', $accountIds)
            ->getResult();
        $byAccount = [];
        foreach ($feeds as $feed) {
            $byAccount[$feed->account()->id()] = $feed;
        }

        return $byAccount;
    }

    public function add(BankFeed $feed): void
    {
        $this->em->persist($feed);
    }

    public function revealAddress(BankFeed $feed): string
    {
        try {
            return $this->issuer->reveal($feed->addressId()) ?? throw new \LogicException('The address of a bank feed is always shown again; Mail has none to show.');
        } catch (AddressNotIssued $refused) {
            throw new AddressRefused(AddressUnavailable::NoKey === $refused->reason ? AddressRefusal::NoKey : AddressRefusal::NotConfigured);
        }
    }

    public function touch(string $feedId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(self::TOUCH, ['at' => $at, 'id' => $feedId], ['at' => Types::DATETIME_IMMUTABLE]);
    }
}
