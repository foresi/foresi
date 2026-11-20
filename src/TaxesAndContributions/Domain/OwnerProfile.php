<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\TaxesAndContributions\Contract\Authority;

/**
 * The profile of the owner of the instance's firm (B7: one per instance in
 * phase 1), as one row with a constant key, so a second profile cannot be
 * stored. It holds the inputs of each year and where each authority is paid;
 * the dated statuses are rows of their own that point to it. It holds personal
 * data, so only the owner reads and changes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'taxes_owner_profile')]
class OwnerProfile implements Audited
{
    public const int ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id = self::ID;

    /** @var list<array<mixed>> the inputs of each year, oldest first */
    #[ORM\Column(type: 'json')]
    private array $years = [];

    /** @var list<array{authority: string, account: string, variable_symbol: string}> in the order of Authority */
    #[ORM\Column(type: 'json')]
    private array $authorities = [];

    public function id(): int
    {
        return $this->id;
    }

    /**
     * Replaces the inputs of their year.
     */
    public function setYear(YearInputs $inputs): void
    {
        $years = array_filter($this->years(), static fn (YearInputs $year): bool => $year->year() !== $inputs->year());
        $years[] = $inputs;
        usort($years, static fn (YearInputs $a, YearInputs $b): int => $a->year() <=> $b->year());
        $this->years = array_map(static fn (YearInputs $year): array => $year->data(), $years);
    }

    /**
     * @return list<YearInputs> oldest first
     */
    public function years(): array
    {
        return array_map(YearInputs::stored(...), $this->years);
    }

    public function year(int $year): ?YearInputs
    {
        return array_find($this->years(), static fn (YearInputs $inputs): bool => $inputs->year() === $year);
    }

    /**
     * Replaces the account of its authority.
     */
    public function setAuthority(AuthorityAccount $account): void
    {
        $accounts = [];
        foreach (Authority::cases() as $authority) {
            $current = $authority === $account->authority ? $account : $this->authority($authority);
            if ($current instanceof AuthorityAccount) {
                $accounts[] = $current->data();
            }
        }
        $this->authorities = $accounts;
    }

    /**
     * @return list<AuthorityAccount> in the order of Authority
     */
    public function authorities(): array
    {
        return array_map(AuthorityAccount::stored(...), $this->authorities);
    }

    private function authority(Authority $authority): ?AuthorityAccount
    {
        return array_find($this->authorities(), static fn (AuthorityAccount $account): bool => $account->authority === $authority);
    }
}
