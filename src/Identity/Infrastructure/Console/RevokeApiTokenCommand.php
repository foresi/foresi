<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\ManageApiTokens;
use App\Shared\Domain\Id;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator revokes any token.
 */
#[AsCommand(name: 'app:api-token:revoke', description: 'Revoke a personal API token')]
final readonly class RevokeApiTokenCommand
{
    public function __construct(private ManageApiTokens $manage)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Id of the token')]
        string $tokenId,
    ): int {
        if (!Id::isValid($tokenId)) {
            $io->error('No API token has this id.');

            return Command::FAILURE;
        }

        try {
            $token = $this->manage->revokeAsOperator($tokenId);
        } catch (ApiTokenRefused) {
            $io->error('No API token has this id.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Revoked "%s"; it signs nobody in any more.', $token->name()));

        return Command::SUCCESS;
    }
}
