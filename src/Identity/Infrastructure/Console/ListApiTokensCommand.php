<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Console;

use Foresi\Identity\Application\ApiTokenRecord;
use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The personal API tokens of the instance. Only the last four
 * characters of a token are known, so no secret is ever printed.
 */
#[AsCommand(
    name: 'app:api-token:list',
    description: 'List personal API tokens',
    help: <<<'HELP'
    Lists the personal API tokens of the instance with their user, scopes, expiry, last use and state.
    Run it by hand to see who holds a token and whether it is still used, for example before revoking
    one. It is read-only and safe to repeat. Only the last four characters of each token are known, so
    no secret is printed.
    HELP,
)]
final readonly class ListApiTokensCommand
{
    public function __construct(
        private ApiTokens $tokens,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $records = $this->tokens->records();
        if ([] === $records) {
            $io->note('No API tokens.');

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        $io->table(
            ['Id', 'User', 'Name', 'Scopes', 'Ends in', 'Expires', 'Last used', 'State'],
            array_map(fn (ApiTokenRecord $record): array => [
                $record->id,
                $this->emailOf($record->userId),
                $record->name,
                implode(', ', array_map(static fn (ApiScope $scope): string => $scope->value, $record->scopes)),
                '…'.$record->lastFour,
                $record->expiresAt->format(\DATE_ATOM),
                $record->lastUsedAt?->format(\DATE_ATOM) ?? '-',
                $record->state($now)->value,
            ], $records),
        );

        return Command::SUCCESS;
    }

    private function emailOf(string $userId): string
    {
        $user = $this->users->findById($userId);

        return $user instanceof User ? $user->email() : $userId;
    }
}
