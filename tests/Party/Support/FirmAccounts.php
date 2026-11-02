<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Support;

use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a firm of a test a bank account without the application path: the
 * firm's first account is the primary one, later ones come after it. It
 * persists and leaves the flush to the test.
 */
final class FirmAccounts
{
    public static function add(EntityManagerInterface $em, LegalEntity $firm, string $iban, Currency $currency = Currency::CZK): FirmBankAccount
    {
        $existing = self::of($em, $firm);
        $account = FirmBankAccount::open($firm, Iban::fromString($iban), $currency, '', \count($existing));
        if ([] === $existing) {
            $account->markPrimary(true);
        }
        $em->persist($account);

        return $account;
    }

    /**
     * Archives every account of the firm, as an owner who dropped them would. It leaves the flush to the test.
     */
    public static function archiveAll(EntityManagerInterface $em, LegalEntity $firm): void
    {
        foreach (self::of($em, $firm) as $account) {
            $account->archive(new \DateTimeImmutable('2026-11-02 12:00:00'));
        }
    }

    /**
     * Stored and waiting to be stored.
     *
     * @return list<FirmBankAccount>
     */
    private static function of(EntityManagerInterface $em, LegalEntity $firm): array
    {
        $uow = $em->getUnitOfWork();
        $accounts = array_values(array_filter($uow->getScheduledEntityInsertions(), static fn (object $entity): bool => $entity instanceof FirmBankAccount && $entity->firm() === $firm));
        if ($em->contains($firm) && !$uow->isScheduledForInsert($firm)) {
            /** @var list<FirmBankAccount> $stored */
            $stored = $em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.firm = :firm')->setParameter('firm', $firm)->getResult();
            $accounts = [...$accounts, ...$stored];
        }

        return $accounts;
    }
}
