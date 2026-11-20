<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;

/**
 * Where the owner pays an authority, the match rule for the payments of its
 * advances (B4): the account and the payment reference, which may be empty.
 */
final readonly class AuthorityAccount
{
    public const int MAX_ACCOUNT = 64;
    public const int MAX_REFERENCE = 35;

    public function __construct(
        public Authority $authority,
        public string $account,
        public string $variableSymbol,
    ) {
        if ('' === trim($account) || mb_strlen($account) > self::MAX_ACCOUNT || mb_strlen($variableSymbol) > self::MAX_REFERENCE) {
            throw new \InvalidArgumentException('An authority account has an account of 1 to 64 characters and a reference of at most 35.');
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function stored(array $data): self
    {
        return new self(Authority::from(Stored::string($data, 'authority')), Stored::string($data, 'account'), Stored::string($data, 'variable_symbol'));
    }

    /**
     * @return array{authority: string, account: string, variable_symbol: string}
     */
    public function data(): array
    {
        return ['authority' => $this->authority->value, 'account' => $this->account, 'variable_symbol' => $this->variableSymbol];
    }

    public function view(): AuthorityAccountView
    {
        return new AuthorityAccountView($this->authority->value, $this->account, $this->variableSymbol);
    }
}
