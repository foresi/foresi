<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\AddressRefusal;
use App\Banking\Application\AddressRefused;
use App\Banking\Application\BankFeeds;
use App\Banking\Application\InboundAddresses;
use App\Banking\Domain\BankFeed;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailSecretsUnavailable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tenant-scoped through the Doctrine tenant filter, except touch(), which is
 * plain SQL by the feed's id.
 */
final readonly class DoctrineBankFeeds implements BankFeeds
{
    private const string TOUCH = 'UPDATE bank_feed SET last_received_at = :at WHERE id = :id AND (last_received_at IS NULL OR last_received_at < :at)';

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private MailSecrets $secrets,
        private InboundAddresses $addresses,
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
            $token = $this->secrets->open($feed->tokenSealed());
        } catch (MailSecretsUnavailable) {
            throw new AddressRefused(AddressRefusal::NoKey);
        }

        return $this->addresses->addressFor($token);
    }

    public function touch(string $feedId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(self::TOUCH, ['at' => $at, 'id' => $feedId], ['at' => Types::DATETIME_IMMUTABLE]);
    }
}
