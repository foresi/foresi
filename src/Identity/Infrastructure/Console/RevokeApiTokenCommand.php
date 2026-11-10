<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\ApiTokens;
use App\Identity\Application\ManageApiTokens;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator revokes any token, in whichever organisation it belongs to.
 */
#[AsCommand(name: 'app:api-token:revoke', description: 'Revoke a personal API token')]
final readonly class RevokeApiTokenCommand
{
    public function __construct(
        private ManageApiTokens $manage,
        private ApiTokens $tokens,
        private TenantContext $context,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Id of the token')]
        string $tokenId,
    ): int {
        $tenant = Id::isValid($tokenId) ? $this->tokens->tenantOf($tokenId) : null;
        if (!$tenant instanceof TenantId) {
            $io->error('No API token has this id.');

            return Command::FAILURE;
        }

        $this->context->switchTo($tenant);
        try {
            $token = $this->manage->revokeAsOperator($tokenId);
        } catch (ApiTokenRefused) {
            $io->error('No API token has this id.');

            return Command::FAILURE;
        } finally {
            $this->context->reset();
        }

        $io->success(\sprintf('Revoked "%s"; it signs nobody in any more.', $token->name()));

        return Command::SUCCESS;
    }
}
