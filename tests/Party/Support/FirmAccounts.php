<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Shared\Domain\Currency;

/**
 * Gives the firm of a test a bank account without the application path: the
 * first account is the primary one, later ones come after it. It persists and
 * leaves the flush to the test.
 */
final class FirmAccounts
{
    public static function add(EntityManagerInterface $em, string $iban, ?Currency $currency = null): FirmBankAccount
    {
        $currency ??= Currency::of('CZK');
        $existing = self::all($em);
        $account = FirmBankAccount::open(Iban::fromString($iban), $currency, '', \count($existing));
        if ([] === $existing) {
            $account->markPrimary(true);
        }
        $em->persist($account);

        return $account;
    }

    /**
     * Archives every account, as an owner who dropped them would. It leaves the flush to the test.
     */
    public static function archiveAll(EntityManagerInterface $em): void
    {
        foreach (self::all($em) as $account) {
            $account->archive(new \DateTimeImmutable('2026-11-02 12:00:00'));
        }
    }

    /**
     * Stored and waiting to be stored.
     *
     * @return list<FirmBankAccount>
     */
    private static function all(EntityManagerInterface $em): array
    {
        $uow = $em->getUnitOfWork();
        $accounts = array_values(array_filter($uow->getScheduledEntityInsertions(), static fn (object $entity): bool => $entity instanceof FirmBankAccount));
        /** @var list<FirmBankAccount> $stored */
        $stored = $em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a')->getResult();

        return [...$accounts, ...$stored];
    }
}
