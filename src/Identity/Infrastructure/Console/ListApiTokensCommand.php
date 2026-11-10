<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\ApiTokenRecord;
use App\Identity\Application\ApiTokens;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The personal API tokens of every organisation, or of one. Only the last four
 * characters of a token are known, so no secret is ever printed.
 */
#[AsCommand(name: 'app:api-token:list', description: 'List personal API tokens, of all organisations or of one')]
final readonly class ListApiTokensCommand
{
    public function __construct(
        private ApiTokens $tokens,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Id of the organisation (tenant); all when left out')]
        ?string $tenant = null,
    ): int {
        if (null !== $tenant && !Id::isValid($tenant)) {
            $io->error('The organisation id is not valid.');

            return Command::INVALID;
        }
        $records = $this->tokens->records(null === $tenant ? null : TenantId::fromString($tenant));
        if ([] === $records) {
            $io->note('No API tokens.');

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        $io->table(
            ['Id', 'Organisation', 'User', 'Name', 'Scopes', 'Ends in', 'Expires', 'Last used', 'State'],
            array_map(fn (ApiTokenRecord $record): array => [
                $record->id,
                $record->tenantId->toString(),
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
